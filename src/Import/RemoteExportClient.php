<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Requests remote export manifests and downloads artifacts.
 */
class RemoteExportClient
{
    /**
     * @param callable|null $transport
     */
    public function __construct(private string $apiKey, private $transport = null)
    {
        $this->transport ??= [$this, 'defaultTransport'];
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

        $bytesWritten = file_put_contents($path, $body);
        if ($bytesWritten === false || $bytesWritten !== strlen($body)) {
            throw new \RuntimeException('Failed to persist the downloaded artifact to disk.');
        }

        return $path;
    }

    private function request(string $method, string $url, ?array $payload = null): string
    {
        [$body, $responseHeaders] = ($this->transport)($method, $url, $payload, $this->apiKey);
        $statusLine = $this->findLastStatusLine($responseHeaders);
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) !== 1) {
            throw new \RuntimeException(sprintf('HTTP response from %s did not include a valid status code.', $url));
        }

        $statusCode = (int) $matches[1];
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('HTTP request to %s returned status %d.', $url, $statusCode));
        }

        return $body;
    }

    private function defaultTransport(string $method, string $url, ?array $payload, string $apiKey): array
    {
        $headers = [
            'Content-Type: application/json',
            'X-Municipio-Clone-Key: ' . $apiKey,
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

        return [$body, $http_response_header ?? []];
    }

    /**
     * @param string[] $responseHeaders
     */
    private function findLastStatusLine(array $responseHeaders): string
    {
        $statusLine = '';
        foreach ($responseHeaders as $header) {
            if (str_starts_with($header, 'HTTP/')) {
                $statusLine = $header;
            }
        }

        return $statusLine;
    }
}
