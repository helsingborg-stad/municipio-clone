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
        $client = new RemoteExportClient('api-key', static fn(string $method, string $url, ?array $payload, string $apiKey): array => [
            '{"artifact_id":"artifact"}',
            ['HTTP/1.1 302 Found', 'Location: https://redirected.example.test', 'HTTP/1.1 200 OK'],
        ]);

        $manifest = $client->requestExport('https://source.example.test', false);

        $this->assertSame('artifact', $manifest['artifact_id']);
    }

    public function testRequestExportThrowsForNonSuccessResponses(): void
    {
        $client = new RemoteExportClient('api-key', static fn(string $method, string $url, ?array $payload, string $apiKey): array => [
            '{}',
            ['HTTP/1.1 401 Unauthorized'],
        ]);

        $this->expectException(\RuntimeException::class);
        $client->requestExport('https://source.example.test', false);
    }

    public function testDownloadArtifactRejectsMissingDownloadUrl(): void
    {
        $client = new RemoteExportClient('api-key', static fn(string $method, string $url, ?array $payload, string $apiKey): array => [
            '',
            ['HTTP/1.1 200 OK'],
        ]);

        $this->expectException(\RuntimeException::class);
        $client->downloadArtifact(['checksum' => 'checksum', 'requested_source_origin' => 'https://source.example.test']);
    }

    public function testDownloadArtifactRejectsCrossOriginDownloadUrl(): void
    {
        $client = new RemoteExportClient('api-key', static fn(string $method, string $url, ?array $payload, string $apiKey): array => [
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
}
