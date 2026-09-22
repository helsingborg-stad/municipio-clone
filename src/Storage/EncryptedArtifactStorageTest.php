<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Storage;

use MunicipioClone\Storage\EncryptedArtifactStorage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Storage\EncryptedArtifactStorage
 */
class EncryptedArtifactStorageTest extends TestCase
{
    private string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDirectory = sys_get_temp_dir() . '/municipio-clone-storage-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->storageDirectory)) {
            foreach ((array) glob($this->storageDirectory . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->storageDirectory);
        }
    }

    public function testStoredArtifactCanBeWrittenToAFileBeforeExpiry(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), 3600);
        $manifest = $storage->store('cache-key', $this->createContentFile('SELECT 1;'), [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $destinationPath = $this->createContentFile('');

        $storage->writeContentToFile($manifest->artifactId, $destinationPath);

        $this->assertSame('SELECT 1;', file_get_contents($destinationPath));
        @unlink($destinationPath);
    }

    public function testExpiredArtifactCannotBeRetrieved(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), -1);
        $manifest = $storage->store('cache-key', $this->createContentFile('SELECT 1;'), [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);

        $this->expectException(\RuntimeException::class);
        $storage->writeContentToFile($manifest->artifactId, $this->createContentFile(''));
    }

    public function testArtifactWithMismatchedManifestChecksumCannotBeWritten(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), 3600);
        $manifest = $storage->store('cache-key', $this->createContentFile('SELECT 1;'), [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $metadataPath = $this->storageDirectory . '/' . $manifest->artifactId . '.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['checksum'] = hash('sha256', 'different content');
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        $destinationPath = $this->createContentFile('');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('The decrypted export artifact checksum does not match its manifest.');
            $storage->writeContentToFile($manifest->artifactId, $destinationPath);
        } finally {
            @unlink($destinationPath);
        }
    }

    private function createContentFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_storage_test_');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary export content file for the test.');
        }
        file_put_contents($path, $content);

        return $path;
    }
}
