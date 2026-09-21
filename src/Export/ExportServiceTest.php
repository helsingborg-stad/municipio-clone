<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Export;

use MunicipioClone\Contracts\ArtifactStorageInterface;
use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Export\ArtifactManifest;
use MunicipioClone\Export\ExportService;
use MunicipioClone\Export\SqlExportGenerator;
use MunicipioClone\Support\RateLimiter;
use PHPUnit\Framework\TestCase;
use MunicipioClone\Tests\TestDoubles\MutableWpService;

/**
 * @covers \MunicipioClone\Export\ExportService
 */
class ExportServiceTest extends TestCase
{
    public function testGeneratesArtifactWhenCacheIsMissing(): void
    {
        $storage = new class() implements ArtifactStorageInterface {
            public ?ArtifactManifest $storedManifest = null;
            public function getFresh(string $cacheKey): ?ArtifactManifest
            {
                return null;
            }
            public function store(string $cacheKey, string $content, array $metadata): ArtifactManifest
            {
                $this->storedManifest = new ArtifactManifest('artifact-generated', hash('sha256', $content), time(), time() + 60, $metadata['source_url'], $metadata['source_blog_id'], $metadata['source_table_prefix'], $cacheKey);

                return $this->storedManifest;
            }
            public function retrieveContent(string $artifactId): string
            {
                return '';
            }
        };
        $generator = $this->createMock(SqlExportGenerator::class);
        $generator->method('generate')->willReturn([
            'content' => 'SQL',
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $logger = new class() implements LoggerInterface {
            public function info(string $message, array $context = []): void
            {
            }
        };

        $service = new ExportService($storage, $generator, new RateLimiter(new MutableWpService(), 600), $logger);
        $result = $service->create('cache-key', false, 12);

        $this->assertSame('generated', $result['cache_status']);
        $this->assertSame('artifact-generated', $result['manifest']->artifactId);
    }

    public function testUsesFreshCacheWhenForceIsNotAllowed(): void
    {
        $storage = new class() implements ArtifactStorageInterface {
            public ArtifactManifest $manifest;
            public function __construct()
            {
                $this->manifest = new ArtifactManifest('artifact', 'checksum', time(), time() + 60, 'https://source.example.test', 1, 'wp_', 'cache-key');
            }
            public function getFresh(string $cacheKey): ?ArtifactManifest
            {
                return $this->manifest;
            }
            public function store(string $cacheKey, string $content, array $metadata): ArtifactManifest
            {
                throw new \RuntimeException('Store should not be called.');
            }
            public function retrieveContent(string $artifactId): string
            {
                return '';
            }
        };
        $generator = $this->createMock(SqlExportGenerator::class);
        $logger = new class() implements LoggerInterface {
            public array $entries = [];
            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $wpService = new MutableWpService();
        $wpService->transients['municipio_clone_force_' . hash('sha256', 'cache-key')] = time();
        $service = new ExportService($storage, $generator, new RateLimiter($wpService, 600), $logger);

        $result = $service->create('cache-key', true, 12);

        $this->assertSame('hit', $result['cache_status']);
        $this->assertSame('artifact', $result['manifest']->artifactId);
    }

    public function testForceRegenerationReturnsForcedStatus(): void
    {
        $storage = new class() implements ArtifactStorageInterface {
            public function getFresh(string $cacheKey): ?ArtifactManifest
            {
                return new ArtifactManifest('stale-artifact', 'checksum', time(), time() + 60, 'https://source.example.test', 1, 'wp_', $cacheKey);
            }
            public function store(string $cacheKey, string $content, array $metadata): ArtifactManifest
            {
                return new ArtifactManifest('forced-artifact', hash('sha256', $content), time(), time() + 60, $metadata['source_url'], $metadata['source_blog_id'], $metadata['source_table_prefix'], $cacheKey);
            }
            public function retrieveContent(string $artifactId): string
            {
                return '';
            }
        };
        $generator = $this->createMock(SqlExportGenerator::class);
        $generator->method('generate')->willReturn([
            'content' => 'SQL',
            'source_url' => 'https://source.example.test',
            'source_blog_id' => 1,
            'source_table_prefix' => 'wp_',
        ]);
        $logger = new class() implements LoggerInterface {
            public function info(string $message, array $context = []): void
            {
            }
        };

        $service = new ExportService($storage, $generator, new RateLimiter(new MutableWpService(), 600), $logger);
        $result = $service->create('cache-key', true, 12);

        $this->assertSame('forced', $result['cache_status']);
        $this->assertSame('forced-artifact', $result['manifest']->artifactId);
    }
}
