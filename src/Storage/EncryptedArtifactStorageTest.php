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

    public function testGuidPreservationMarkerSurvivesCachedManifestReload(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, 'secret', 3600);
        $contentPath = $this->createContentFile('SELECT 1;');

        try {
            $manifest = $storage->store('marked', $contentPath, [
                'source_url' => 'https://source.example.test',
                'source_blog_id' => 1,
                'source_table_prefix' => 'wp_',
                'guid_preserved' => true,
            ]);

            $this->assertTrue($manifest->guidPreserved);
            $this->assertTrue($storage->getFresh('marked')?->guidPreserved);
            $this->assertTrue(json_decode(
                (string) file_get_contents($this->storageDirectory . '/' . $manifest->artifactId . '.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['guid_preserved']);
            $this->assertFalse($storage->store('unmarked', $contentPath, [
                'source_url' => 'https://source.example.test',
                'source_blog_id' => 1,
                'source_table_prefix' => 'wp_',
            ])->guidPreserved);
            $metadataPath = $this->storageDirectory . '/' . $manifest->artifactId . '.json';
            $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
            unset($metadata['guid_preserved']);
            file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
            $this->assertFalse($storage->getFresh('marked')?->guidPreserved);
        } finally {
            unlink($contentPath);
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

    public function testReplacingCachedArtifactKeepsPreviouslyIssuedArtifactAvailable(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), 3600, 3600);
        $firstContentPath = $this->createContentFile('SELECT 1;');
        $secondContentPath = $this->createContentFile('SELECT 2;');

        $firstManifest = $storage->store('cache-key', $firstContentPath, [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $secondManifest = $storage->store('cache-key', $secondContentPath, [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $firstDownloadPath = $this->createContentFile('');
        $secondDownloadPath = $this->createContentFile('');

        try {
            $storage->writeContentToFile($firstManifest->artifactId, $firstDownloadPath);
            $storage->writeContentToFile($secondManifest->artifactId, $secondDownloadPath);

            $this->assertNotSame($firstManifest->artifactId, $secondManifest->artifactId);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $secondManifest->artifactId);
            $this->assertSame('SELECT 1;', file_get_contents($firstDownloadPath));
            $this->assertSame('SELECT 2;', file_get_contents($secondDownloadPath));
            $this->assertSame($secondManifest->artifactId, $storage->getFresh('cache-key')?->artifactId);
        } finally {
            @unlink($firstContentPath);
            @unlink($secondContentPath);
            @unlink($firstDownloadPath);
            @unlink($secondDownloadPath);
        }
    }

    public function testArtifactRemainsDownloadableAfterCacheExpiresDuringRetention(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), -1, 3600);
        $contentPath = $this->createContentFile('SELECT 1;');
        $manifest = $storage->store('cache-key', $contentPath, [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $destinationPath = $this->createContentFile('');

        try {
            $this->assertNull($storage->getFresh('cache-key'));
            $storage->writeContentToFile($manifest->artifactId, $destinationPath);

            $this->assertSame('SELECT 1;', file_get_contents($destinationPath));
        } finally {
            @unlink($contentPath);
            @unlink($destinationPath);
        }
    }

    public function testExistingCacheKeyBasedArtifactRemainsReadable(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), 3600);
        $cacheKey = 'legacy-cache-key';
        $contentPath = $this->createContentFile('SELECT 1;');
        $manifest = $storage->store($cacheKey, $contentPath, [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $legacyArtifactId = substr(hash('sha256', $cacheKey), 0, 32);
        $manifestPath = $this->storageDirectory . '/' . $manifest->artifactId . '.json';
        $payloadPath = $this->storageDirectory . '/' . $manifest->artifactId . '.bin';
        $legacyManifestPath = $this->storageDirectory . '/' . $legacyArtifactId . '.json';
        $legacyPayloadPath = $this->storageDirectory . '/' . $legacyArtifactId . '.bin';
        $manifestData = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifestData['artifact_id'] = $legacyArtifactId;
        file_put_contents($legacyManifestPath, json_encode($manifestData, JSON_THROW_ON_ERROR));
        rename($payloadPath, $legacyPayloadPath);
        unlink($manifestPath);
        unlink($this->storageDirectory . '/' . substr(hash('sha256', $cacheKey), 0, 32) . '.current.json');
        $destinationPath = $this->createContentFile('');

        try {
            $this->assertSame($legacyArtifactId, $storage->getFresh($cacheKey)?->artifactId);
            $storage->writeContentToFile($legacyArtifactId, $destinationPath);

            $this->assertSame('SELECT 1;', file_get_contents($destinationPath));
        } finally {
            @unlink($contentPath);
            @unlink($destinationPath);
        }
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

    public function testExpiredArtifactsAreRemovedDuringCacheLookup(): void
    {
        $storage = new EncryptedArtifactStorage($this->storageDirectory, hash('sha256', 'secret', true), -1);
        $manifest = $storage->store('cache-key', $this->createContentFile('SELECT 1;'), [
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);

        $this->assertNull($storage->getFresh('cache-key'));
        $this->assertFileDoesNotExist($this->storageDirectory . '/' . $manifest->artifactId . '.json');
        $this->assertFileDoesNotExist($this->storageDirectory . '/' . $manifest->artifactId . '.bin');
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
