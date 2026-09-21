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
        $iv = random_bytes((int) openssl_cipher_iv_length('aes-256-cbc'));
        $ciphertext = openssl_encrypt($content, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt export artifact.');
        }

        file_put_contents($this->payloadPath($artifactId), $iv . $ciphertext);
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
        file_put_contents($this->metadataPath($artifactId), json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $manifest;
    }

    public function retrieveContent(string $artifactId): string
    {
        $payload = (string) file_get_contents($this->payloadPath($artifactId));
        $ivLength = (int) openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($payload, 0, $ivLength);
        $ciphertext = substr($payload, $ivLength);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
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

    private function payloadPath(string $artifactId): string
    {
        return rtrim($this->storageDirectory, '/') . '/' . $artifactId . '.bin';
    }

    private function metadataPath(string $artifactId): string
    {
        return rtrim($this->storageDirectory, '/') . '/' . $artifactId . '.json';
    }
}
