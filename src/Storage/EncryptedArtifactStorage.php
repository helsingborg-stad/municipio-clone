<?php

declare(strict_types=1);

namespace MunicipioClone\Storage;

use MunicipioClone\Contracts\ArtifactStorageInterface;
use MunicipioClone\Export\ArtifactManifest;

/**
 * Stores export artifacts encrypted on disk outside the web root by default.
 *
 * Content is streamed to/from disk in fixed-size chunks (via libsodium's secretstream
 * API) instead of being held in memory as a single string, since export dumps can be
 * larger than the available PHP memory limit.
 */
class EncryptedArtifactStorage implements ArtifactStorageInterface
{
    private const CHUNK_SIZE = 1024 * 1024;

    private string $encryptionKey;

    public function __construct(
        private string $storageDirectory,
        string $encryptionKey,
        private int $ttl,
        private int $artifactRetention = 0,
    ) {
        // Sodium's secretstream requires an exact 32-byte key; derive one so any key length is accepted.
        $this->encryptionKey = hash('sha256', $encryptionKey, true);
        $this->artifactRetention = max(0, $this->artifactRetention);
    }

    public function getFresh(string $cacheKey): ?ArtifactManifest
    {
        $this->cleanupExpiredArtifacts();

        $pointerPath = $this->currentArtifactPath($cacheKey);
        if (is_file($pointerPath)) {
            $pointer = $this->readJsonFile($pointerPath);
            if (!is_array($pointer)) {
                return null;
            }

            $artifactId = (string) ($pointer['artifact_id'] ?? '');
            if (!$this->isValidArtifactId($artifactId) || (int) ($pointer['cache_expires_at'] ?? 0) < time()) {
                return null;
            }

            $manifest = $this->loadManifest($artifactId);

            return $manifest !== null && $manifest->expiresAt >= time() ? $manifest : null;
        }

        return $this->loadLegacyFreshArtifact($cacheKey);
    }

    public function store(string $cacheKey, string $contentPath, array $metadata): ArtifactManifest
    {
        $this->ensureDirectory();
        $this->cleanupExpiredArtifacts();
        if (!is_file($contentPath)) {
            throw new \RuntimeException('The export content file does not exist.');
        }

        $artifactId = bin2hex(random_bytes(16));
        $checksum = hash_file('sha256', $contentPath);
        if ($checksum === false) {
            throw new \RuntimeException('Failed to checksum the export content.');
        }

        $generatedAt = time();
        $manifest = new ArtifactManifest(
            $artifactId,
            $checksum,
            $generatedAt,
            $generatedAt + $this->ttl + $this->artifactRetention,
            (string) $metadata['source_url'],
            (int) $metadata['source_blog_id'],
            (string) $metadata['source_table_prefix'],
            $cacheKey,
            (string) ($metadata['source_media_base_url'] ?? ''),
            ($metadata['guid_preserved'] ?? false) === true,
        );

        $payloadPath = $this->payloadPath($artifactId);
        $manifestPath = $this->metadataPath($artifactId);
        $temporaryPayloadPath = tempnam($this->storageDirectory, 'artifact-');
        if ($temporaryPayloadPath === false) {
            throw new \RuntimeException('Failed to create a temporary export artifact payload.');
        }

        try {
            $this->encryptFileToPayload($contentPath, $temporaryPayloadPath);
            if (!rename($temporaryPayloadPath, $payloadPath)) {
                throw new \RuntimeException('Failed to publish the export artifact payload.');
            }

            $manifestJson = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $this->writeAtomically($manifestPath, $manifestJson);
            $this->writeAtomically(
                $this->currentArtifactPath($cacheKey),
                json_encode([
                    'artifact_id' => $artifactId,
                    'cache_expires_at' => $generatedAt + $this->ttl,
                ], JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $throwable) {
            @unlink($temporaryPayloadPath);
            @unlink($payloadPath);
            @unlink($manifestPath);
            throw $throwable;
        }

        return $manifest;
    }

    public function writeContentToFile(string $artifactId, string $destinationPath): void
    {
        if (!$this->isValidArtifactId($artifactId)) {
            throw new \RuntimeException('The requested export artifact does not exist or has expired.');
        }

        $manifest = $this->loadManifest($artifactId);
        if ($manifest === null || $manifest->expiresAt < time()) {
            throw new \RuntimeException('The requested export artifact does not exist or has expired.');
        }

        $this->decryptPayloadToFile($this->payloadPath($artifactId), $destinationPath);
        $checksum = hash_file('sha256', $destinationPath);
        if ($checksum === false || !hash_equals($manifest->checksum, $checksum)) {
            @unlink($destinationPath);
            throw new \RuntimeException('The decrypted export artifact checksum does not match its manifest.');
        }
    }

    private function loadLegacyFreshArtifact(string $cacheKey): ?ArtifactManifest
    {
        $manifest = $this->loadManifest($this->legacyArtifactIdFromCacheKey($cacheKey));

        return $manifest !== null && $manifest->expiresAt >= time() ? $manifest : null;
    }

    private function cleanupExpiredArtifacts(): void
    {
        if (!is_dir($this->storageDirectory)) {
            return;
        }

        foreach ((array) glob(rtrim($this->storageDirectory, '/') . '/*.json') as $metadataPath) {
            if (str_ends_with($metadataPath, '.current.json')) {
                continue;
            }

            $metadata = $this->readJsonFile($metadataPath);
            if (!is_array($metadata) || (int) ($metadata['expires_at'] ?? 0) >= time()) {
                continue;
            }

            $artifactId = (string) ($metadata['artifact_id'] ?? '');
            $fileArtifactId = basename($metadataPath, '.json');
            if (!$this->isValidArtifactId($artifactId) || $artifactId !== $fileArtifactId) {
                continue;
            }

            @unlink($this->payloadPath($artifactId));
            @unlink($metadataPath);
        }
    }

    private function readJsonFile(string $path): ?array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $temporaryPath = tempnam($this->storageDirectory, 'metadata-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Failed to create a temporary export metadata file.');
        }

        try {
            $bytesWritten = file_put_contents($temporaryPath, $contents);
            if ($bytesWritten === false || $bytesWritten !== strlen($contents)) {
                throw new \RuntimeException('Failed to persist export artifact metadata.');
            }
            if (!rename($temporaryPath, $path)) {
                throw new \RuntimeException('Failed to publish export artifact metadata.');
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function isValidArtifactId(string $artifactId): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $artifactId) === 1;
    }

    private function encryptFileToPayload(string $sourcePath, string $payloadPath): void
    {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Failed to open the export content for encryption.');
        }

        $destination = fopen($payloadPath, 'wb');
        if ($destination === false) {
            fclose($source);
            throw new \RuntimeException('Failed to open the export payload for writing.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->encryptionKey);
            $this->writeOrFail($destination, $header);

            $chunk = fread($source, self::CHUNK_SIZE);
            if ($chunk === false) {
                throw new \RuntimeException('Failed to read the export content while encrypting.');
            }

            do {
                $next = fread($source, self::CHUNK_SIZE);
                if ($next === false) {
                    throw new \RuntimeException('Failed to read the export content while encrypting.');
                }

                $isFinalChunk = $next === '';
                $tag = $isFinalChunk
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $this->writeOrFail($destination, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
                $chunk = $next;
            } while ($chunk !== '');
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    /**
     * Decrypts a payload in fixed-size chunks so the whole ciphertext/plaintext pair is never held in memory at once.
     */
    private function decryptPayloadToFile(string $payloadPath, string $destinationPath): void
    {
        $headerLength = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $overhead = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        $source = fopen($payloadPath, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Failed to read the requested export artifact payload.');
        }

        $destination = fopen($destinationPath, 'wb');
        if ($destination === false) {
            fclose($source);
            throw new \RuntimeException('Failed to open a temporary file for the decrypted export content.');
        }

        try {
            $header = fread($source, $headerLength);
            if ($header === false || strlen($header) !== $headerLength) {
                throw new \RuntimeException('The requested export artifact payload is incomplete.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->encryptionKey);

            $finalTagSeen = false;
            $chunk = fread($source, self::CHUNK_SIZE + $overhead);
            while ($chunk !== false && $chunk !== '') {
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                if ($result === false) {
                    throw new \RuntimeException('Failed to decrypt the export artifact; the payload may have been tampered with.');
                }

                [$plaintext, $tag] = $result;
                $this->writeOrFail($destination, $plaintext);
                $finalTagSeen = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                $chunk = fread($source, self::CHUNK_SIZE + $overhead);
            }

            if (!$finalTagSeen) {
                throw new \RuntimeException('The requested export artifact payload is incomplete.');
            }
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    private function writeOrFail($handle, string $data): void
    {
        $bytesWritten = fwrite($handle, $data);
        if ($bytesWritten === false || $bytesWritten !== strlen($data)) {
            throw new \RuntimeException('Failed to write export artifact data.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->storageDirectory) && !mkdir($this->storageDirectory, 0700, true) && !is_dir($this->storageDirectory)) {
            throw new \RuntimeException(sprintf('Failed to create Municipio Clone storage directory: %s', $this->storageDirectory));
        }
    }

    private function legacyArtifactIdFromCacheKey(string $cacheKey): string
    {
        return substr(hash('sha256', $cacheKey), 0, 32);
    }

    private function loadManifest(string $artifactId): ?ArtifactManifest
    {
        if (!$this->isValidArtifactId($artifactId)) {
            return null;
        }

        $metadataPath = $this->metadataPath($artifactId);
        if (!is_file($metadataPath)) {
            return null;
        }

        $metadata = $this->readJsonFile($metadataPath);
        if (!is_array($metadata)) {
            return null;
        }

        $manifest = ArtifactManifest::fromArray($metadata);

        return $manifest->artifactId === $artifactId ? $manifest : null;
    }

    private function currentArtifactPath(string $cacheKey): string
    {
        return rtrim($this->storageDirectory, '/') . '/' . substr(hash('sha256', $cacheKey), 0, 32) . '.current.json';
    }

    private function payloadPath(string $artifactId): string
    {
        return rtrim($this->storageDirectory, '/') . '/' . $artifactId . '.bin';
    }

    private function metadataPath(string $artifactId): string
    {
        return rtrim($this->storageDirectory, '/') . '/' . $artifactId . '.json';
    }
}
