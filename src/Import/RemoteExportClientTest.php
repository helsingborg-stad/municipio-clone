<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\RemoteExportClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\RemoteExportClient
 */
class RemoteExportClientTest extends TestCase
{
    public function testRequestExportUsesLastHttpStatusLine(): void
    {
        $client = new RemoteExportClient('admin', 'app-password', static fn(string $method, string $url, ?array $payload, string $username, string $applicationPassword): array => [
            '{"artifact_id":"artifact"}',
            ['HTTP/1.1 302 Found', 'Location: https://redirected.example.test', 'HTTP/1.1 200 OK'],
        ]);

        $manifest = $client->requestExport('https://source.example.test', false);

        $this->assertSame('artifact', $manifest['artifact_id']);
    }

    public function testRequestExportThrowsForNonSuccessResponses(): void
    {
        $client = new RemoteExportClient('admin', 'app-password', static fn(string $method, string $url, ?array $payload, string $username, string $applicationPassword): array => [
            '{}',
            ['HTTP/1.1 401 Unauthorized'],
        ]);

        $this->expectException(\RuntimeException::class);
        $client->requestExport('https://source.example.test', false);
    }

    public function testDownloadArtifactRejectsMissingDownloadUrl(): void
    {
        $client = new RemoteExportClient('admin', 'app-password', static fn(string $method, string $url, ?array $payload, string $username, string $applicationPassword): array => [
            '',
            ['HTTP/1.1 200 OK'],
        ]);

        $this->expectException(\RuntimeException::class);
        $client->downloadArtifact(['checksum' => 'checksum', 'requested_source_origin' => 'https://source.example.test']);
    }

    public function testDownloadArtifactRejectsCrossOriginDownloadUrl(): void
    {
        $client = new RemoteExportClient('admin', 'app-password', static fn(string $method, string $url, ?array $payload, string $username, string $applicationPassword): array => [
            'payload',
            ['HTTP/1.1 200 OK'],
        ]);

        $this->expectException(\RuntimeException::class);
        $client->downloadArtifact([
            'download_url' => 'https://other.example.test/export.sql',
            'checksum' => hash('sha256', 'payload'),
            'requested_source_origin' => 'https://source.example.test',
        ]);
    }

    public function testDownloadArtifactPersistsChecksumVerifiedContent(): void
    {
        $client = new RemoteExportClient('admin', 'app-password', static fn(string $method, string $url, ?array $payload, string $username, string $applicationPassword): array => [
            'SELECT 1;',
            [
                'HTTP/1.1 200 OK',
                'Content-Length: 9',
                'Content-Encoding: identity',
                'X-Municipio-Clone-Total-Bytes: 9',
                'X-Municipio-Clone-Chunk-Offset: 0',
                'X-Municipio-Clone-Checksum: ' . hash('sha256', 'SELECT 1;'),
            ],
        ]);

        $path = $client->downloadArtifact([
            'download_url' => 'https://source.example.test/export.sql',
            'checksum' => hash('sha256', 'SELECT 1;'),
            'requested_source_origin' => 'https://source.example.test',
        ]);

        try {
            $this->assertSame('SELECT 1;', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testDownloadArtifactReportsChecksumMismatchDetails(): void
    {
        $client = new RemoteExportClient('admin', 'app-password', static fn(string $method, string $url, ?array $payload, string $username, string $applicationPassword): array => [
            'altered payload',
            [
                'HTTP/1.1 200 OK',
                'Content-Length: 15',
                'Content-Encoding: identity',
                'X-Municipio-Clone-Total-Bytes: 15',
                'X-Municipio-Clone-Chunk-Offset: 0',
                'X-Municipio-Clone-Checksum: ' . hash('sha256', 'expected payload'),
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'expected %s, received %s; expected bytes 15, received bytes 15; content encoding identity; remote checksum %s',
            hash('sha256', 'expected payload'),
            hash('sha256', 'altered payload'),
            hash('sha256', 'expected payload'),
        ));

        $client->downloadArtifact([
            'download_url' => 'https://source.example.test/export.sql',
            'checksum' => hash('sha256', 'expected payload'),
            'requested_source_origin' => 'https://source.example.test',
        ]);
    }

    public function testDownloadArtifactAssemblesMultipleChunks(): void
    {
        $content = 'SELECT 1;SELECT 2;';
        $requestedOffsets = [];
        $progress = [];
        $client = new RemoteExportClient(
            'admin',
            'app-password',
            static function (string $method, string $url) use ($content, &$requestedOffsets): array {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $offset = (int) ($query['offset'] ?? 0);
                $requestedOffsets[] = $offset;
                $body = substr($content, $offset, 9);

                return [$body, [
                    'HTTP/1.1 200 OK',
                    'Content-Length: ' . strlen($body),
                    'Content-Encoding: identity',
                    'X-Municipio-Clone-Total-Bytes: ' . strlen($content),
                    'X-Municipio-Clone-Chunk-Offset: ' . $offset,
                    'X-Municipio-Clone-Checksum: ' . hash('sha256', $content),
                ]];
            },
        );

        $path = $client->downloadArtifact(
            [
                'download_url' => 'https://source.example.test/export.sql',
                'checksum' => hash('sha256', $content),
                'requested_source_origin' => 'https://source.example.test',
            ],
            static function (int $downloadedBytes, int $totalBytes) use (&$progress): void {
                $progress[] = [$downloadedBytes, $totalBytes];
            },
        );

        try {
            $this->assertSame($content, file_get_contents($path));
            $this->assertSame([0, 9], $requestedOffsets);
            $this->assertSame([[9, 18], [18, 18]], $progress);
        } finally {
            @unlink($path);
        }
    }
}
