<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Rest;

use MunicipioClone\Contracts\ArtifactStorageInterface;
use MunicipioClone\Export\ArtifactManifest;
use MunicipioClone\Export\ExportService;
use MunicipioClone\Rest\ExportController;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Rest\ExportController
 */
class ExportControllerTest extends TestCase
{
    public function testArtifactDownloadIsStreamedBeforeRestJsonEncoding(): void
    {
        $artifactId = str_repeat('a', 32);
        $storage = new class() implements ArtifactStorageInterface {
            public function getFresh(string $cacheKey): ?ArtifactManifest
            {
                return null;
            }

            public function store(string $cacheKey, string $contentPath, array $metadata): ArtifactManifest
            {
                throw new \RuntimeException('Not used.');
            }

            public function writeContentToFile(string $artifactId, string $destinationPath): void
            {
                file_put_contents($destinationPath, 'SELECT 1;');
            }

        };
        $wpService = new MutableWpService();
        $controller = new ExportController($wpService, $this->createMock(ExportService::class), $storage);
        $request = new class($artifactId) {
            public function __construct(private string $artifactId)
            {
            }

            public function get_param(string $name): string
            {
                return match ($name) {
                    'artifact' => $this->artifactId,
                    '_municipio_clone_user_id' => '1',
                    default => '',
                };
            }

            public function get_route(): string
            {
                return '/municipio-clone/v1/export/' . $this->artifactId;
            }
        };
        $server = new class() {
            public array $headers = [];

            public function send_header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }
        };

        $response = $controller->downloadArtifact($request);
        ob_start();
        $served = $controller->streamArtifactDownload(false, $response, $request, $server);
        $output = (string) ob_get_clean();

        $this->assertSame(['artifact_id' => $artifactId], $response->get_data());
        $this->assertTrue($served);
        $this->assertSame('SELECT 1;', $output);
        $this->assertSame('application/sql', $server->headers['Content-Type']);
    }
}