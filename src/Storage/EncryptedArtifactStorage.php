<?php

declare(strict_types=1);

namespace MunicipioClone\Storage;

use MunicipioClone\Contracts\ArtifactStorageInterface;
use MunicipioClone\Export\ArtifactManifest;

/**
 * Stores export artifacts encrypted on disk outside the web root by default.
 */
class EncryptedArtifactStorage implements ArtifactStorageInterface
{
    public function __construct(private string $storageDirectory, private string $encryptionKey, private int $ttl)
    {
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

    public function store(string $cacheKey, string $content, array $metadata): ArtifactManifest
    {
        $this->ensureDirectory();
        $artifactId = $this->artifactIdFromCacheKey($cacheKey);
        $iv = random_bytes((int) openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        $ciphertext = openssl_encrypt($content, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt export artifact.');
        }

        $payload = $iv . $tag . $ciphertext;
        $payloadWriteResult = file_put_contents($this->payloadPath($artifactId), $payload);
        if ($payloadWriteResult === false || $payloadWriteResult !== strlen($payload)) {
            throw new \RuntimeException('Failed to persist the encrypted export payload.');
        }

        $manifest = new ArtifactManifest(
            $artifactId,
            hash('sha256', $content),
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

    public function retrieveContent(string $artifactId): string
    {
        $manifest = $this->loadManifest($artifactId);
        if ($manifest === null || $manifest->expiresAt < time()) {
            throw new \RuntimeException('The requested export artifact does not exist or has expired.');
        }

        $payload = file_get_contents($this->payloadPath($artifactId));
        if ($payload === false) {
            throw new \RuntimeException('Failed to read the requested export artifact payload.');
        }

        $ivLength = (int) openssl_cipher_iv_length('aes-256-gcm');
        $tagLength = 16;
        if (strlen($payload) < ($ivLength + $tagLength)) {
            throw new \RuntimeException('The requested export artifact payload is incomplete.');
        }

        $iv = substr($payload, 0, $ivLength);
        $tag = substr($payload, $ivLength, $tagLength);
        $ciphertext = substr($payload, $ivLength + $tagLength);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt export artifact.');
        }

        return $plaintext;
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
