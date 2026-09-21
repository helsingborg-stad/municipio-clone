<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Unit\Export;

use MunicipioClone\Contracts\ArtifactStorageInterface;
use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Export\ArtifactManifest;
use MunicipioClone\Export\ExportService;
use MunicipioClone\Export\SqlExportGenerator;
use MunicipioClone\Support\RateLimiter;
use PHPUnit\Framework\TestCase;
use WpService\Implementations\FakeWpService;

/**
 * @covers \MunicipioClone\Export\ExportService
 */
class ExportServiceTest extends TestCase
{
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
        $wpService = new FakeWpService();
        $wpService->transients['municipio_clone_force_' . md5('cache-key')] = time();
        $service = new ExportService($storage, $generator, new RateLimiter($wpService, 600), $logger);

        $result = $service->create('cache-key', true, 12);

        $this->assertSame('hit', $result['cache_status']);
        $this->assertSame('artifact', $result['manifest']->artifactId);
    }
}
