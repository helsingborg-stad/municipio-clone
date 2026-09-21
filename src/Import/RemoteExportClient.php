<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Requests remote export manifests and downloads artifacts.
 */
class RemoteExportClient
{
    public function __construct(private string $apiKey)
    {
    }

    public function requestExport(string $sourceUrl, bool $force): array
    {
        $response = $this->request(
            'POST',
            rtrim($sourceUrl, '/') . '/wp-json/municipio-clone/v1/export',
            ['force' => $force],
        );

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode export manifest response.');
        }

        return $decoded;
    }

    public function downloadArtifact(array $manifest): string
    {
        $downloadUrl = (string) ($manifest['download_url'] ?? '');
        if ($downloadUrl === '') {
            throw new \RuntimeException('Manifest did not include a download URL.');
        }

        $body = $this->request('GET', $downloadUrl);
        $checksum = hash('sha256', $body);
        if ($checksum !== (string) ($manifest['checksum'] ?? '')) {
            throw new \RuntimeException('Downloaded artifact checksum did not match the manifest.');
        }

        $path = tempnam(sys_get_temp_dir(), 'municipio-clone-');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary artifact file.');
        }

        file_put_contents($path, $body);

        return $path;
    }

    private function request(string $method, string $url, ?array $payload = null): string
    {
        $headers = [
            'Content-Type: application/json',
            'X-Municipio-Clone-Key: ' . $this->apiKey,
        ];
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null,
                'ignore_errors' => true,
                'timeout' => 300,
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException(sprintf('HTTP request to %s failed.', $url));
        }

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\\s(\\d{3})\\s/', $statusLine, $matches) !== 1) {
            throw new \RuntimeException(sprintf('HTTP response from %s did not include a valid status code.', $url));
        }

        $statusCode = (int) $matches[1];
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('HTTP request to %s returned status %d.', $url, $statusCode));
        }

        return $body;
    }
}
