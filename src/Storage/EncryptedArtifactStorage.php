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

    public function __construct(private string $storageDirectory, string $encryptionKey, private int $ttl)
    {
        // Sodium's secretstream requires an exact 32-byte key; derive one so any key length is accepted.
        $this->encryptionKey = hash('sha256', $encryptionKey, true);
    }

    public function getFresh(string $cacheKey): ?ArtifactManifest
    {
        $metadataPath = $this->metadataPath($this->artifactIdFromCacheKey($cacheKey));
        if (!is_file($metadataPath)) {
            return null;
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        if (!is_array($metadata)) {
            return null;
        }

        if ((int) ($metadata['expires_at'] ?? 0) < time()) {
            @unlink($metadataPath);
            @unlink($this->payloadPath((string) ($metadata['artifact_id'] ?? '')));

            return null;
        }

        return ArtifactManifest::fromArray($metadata);
    }

    public function store(string $cacheKey, string $contentPath, array $metadata): ArtifactManifest
    {
        $this->ensureDirectory();
        if (!is_file($contentPath)) {
            throw new \RuntimeException('The export content file does not exist.');
        }

        $artifactId = $this->artifactIdFromCacheKey($cacheKey);
        $checksum = hash_file('sha256', $contentPath);
        if ($checksum === false) {
            throw new \RuntimeException('Failed to checksum the export content.');
        }

        $this->encryptFileToPayload($contentPath, $this->payloadPath($artifactId));

        $manifest = new ArtifactManifest(
            $artifactId,
            $checksum,
            time(),
            time() + $this->ttl,
            (string) $metadata['source_url'],
            (int) $metadata['source_blog_id'],
            (string) $metadata['source_table_prefix'],
            $cacheKey,
        );
        $manifestJson = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $metadataWriteResult = file_put_contents($this->metadataPath($artifactId), $manifestJson);
        if ($metadataWriteResult === false || $metadataWriteResult !== strlen($manifestJson)) {
            @unlink($this->payloadPath($artifactId));
            throw new \RuntimeException('Failed to persist the export artifact metadata.');
        }

        return $manifest;
    }

    public function writeContentToFile(string $artifactId, string $destinationPath): void
    {
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

    /**
     * Encrypts a file in fixed-size chunks so the whole payload is never held in memory at once.
     */
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

    private function artifactIdFromCacheKey(string $cacheKey): string
    {
        return substr(hash('sha256', $cacheKey), 0, 32);
    }

    private function loadManifest(string $artifactId): ?ArtifactManifest
    {
        $metadataPath = $this->metadataPath($artifactId);
        if (!is_file($metadataPath)) {
            return null;
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        if (!is_array($metadata)) {
            return null;
        }

        return ArtifactManifest::fromArray($metadata);
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
