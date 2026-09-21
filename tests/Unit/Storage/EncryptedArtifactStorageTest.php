<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Unit\Storage;

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

    public function testStoredArtifactCanBeRetrievedBeforeExpiry(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), 3600);
        $manifest = $storage->store('cache-key', 'SELECT 1;', [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);

        $this->assertSame('SELECT 1;', $storage->retrieveContent($manifest->artifactId));
    }

    public function testExpiredArtifactCannotBeRetrieved(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), -1);
        $manifest = $storage->store('cache-key', 'SELECT 1;', [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);

        $this->expectException(\RuntimeException::class);
        $storage->retrieveContent($manifest->artifactId);
    }
}
