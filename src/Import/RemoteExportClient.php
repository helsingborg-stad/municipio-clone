<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Requests remote export manifests and downloads artifacts.
 */
class RemoteExportClient
{
    private const DOWNLOAD_CHUNK_SIZE = 64 * 1024 * 1024;

    private const EXPORT_ROUTE = 'municipio-clone/v1/export';

    private bool $usesDefaultTransport;

    /**
     * @param callable|null $transport
     */
    public function __construct(
        private string $username,
        private string $applicationPassword,
        private $transport = null,
    )
    {
        $this->usesDefaultTransport = $this->transport === null;
        $this->transport ??= [$this, 'defaultTransport'];
    }

    /**
     * Requests an export manifest from the source site.
     *
     * The source REST API root is discovered from the WordPress `Link: <...>; rel="https://api.w.org/"`
     * header, since some sites move `/wp-json/` to a custom prefix (for example `/json/`).
     * Pass $restUrl to skip discovery and use an explicit REST API root.
     */
    public function requestExport(string $sourceUrl, bool $force, ?string $restUrl = null): array
    {
        $restRoot = $restUrl !== null && $restUrl !== '' ? $restUrl : $this->discoverRestRoot($sourceUrl);
        $response = $this->request(
            'POST',
            $this->buildRestRouteUrl($restRoot, self::EXPORT_ROUTE),
            ['force' => $force],
        );

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode export manifest response.');
        }

        $decoded['requested_source_origin'] = $this->origin($restRoot);

        return $decoded;
    }

    /**
     * Resolves the source REST API root, falling back to the default `/wp-json/` prefix.
     */
    public function discoverRestRoot(string $sourceUrl): string
    {
        $defaultRoot = rtrim($sourceUrl, '/') . '/wp-json/';

        try {
            [, $responseHeaders] = ($this->transport)('HEAD', rtrim($sourceUrl, '/') . '/', null, $this->username, $this->applicationPassword);
        } catch (\Throwable) {
            return $defaultRoot;
        }

        $discoveredRoot = $this->findRestApiLink(is_array($responseHeaders) ? $responseHeaders : []);
        if ($discoveredRoot === null || $this->origin($discoveredRoot) !== $this->origin($sourceUrl)) {
            return $defaultRoot;
        }

        return $discoveredRoot;
    }

    /**
     * @param string[] $responseHeaders
     */
    private function findRestApiLink(array $responseHeaders): ?string
    {
        $restRoot = null;
        foreach ($responseHeaders as $header) {
            if (!str_starts_with(strtolower((string) $header), 'link:')) {
                continue;
            }

            $matchCount = preg_match_all('/<([^>]+)>\s*;[^,]*\brel\s*=\s*"?https:\/\/api\.w\.org\/"?/i', (string) $header, $matches);
            if ($matchCount > 0) {
                $restRoot = html_entity_decode(end($matches[1]), ENT_QUOTES);
            }
        }

        return $restRoot !== null && filter_var($restRoot, FILTER_VALIDATE_URL) !== false ? $restRoot : null;
    }

    private function buildRestRouteUrl(string $restRoot, string $route): string
    {
        $route = ltrim($route, '/');
        $parts = parse_url($restRoot);
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (!isset($query['rest_route'])) {
            return rtrim($restRoot, '/') . '/' . $route;
        }

        // Plain-permalink sites expose the REST API as `?rest_route=/`.
        $query['rest_route'] = rtrim((string) $query['rest_route'], '/') . '/' . $route;
        $base = strstr($restRoot, '?', true);

        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param null|callable(int, int): void $progress
     */
    public function downloadArtifact(array $manifest, ?callable $progress = null): string
    {
        $downloadUrl = (string) ($manifest['download_url'] ?? '');
        if ($downloadUrl === '') {
            throw new \RuntimeException('Manifest did not include a download URL.');
        }
        if (($manifest['requested_source_origin'] ?? '') !== $this->origin($downloadUrl)) {
            throw new \RuntimeException('Manifest download URL must match the requested source origin.');
        }

        $path = tempnam(sys_get_temp_dir(), 'municipio-clone-');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary artifact file.');
        }

        try {
            $downloadMetadata = $this->downloadToFile($downloadUrl, $path, $progress);

            $checksum = hash_file('sha256', $path);
            $expectedChecksum = (string) ($manifest['checksum'] ?? '');
            if ($checksum === false || !hash_equals($expectedChecksum, $checksum)) {
                throw new \RuntimeException(sprintf(
                    'Downloaded artifact checksum did not match the manifest (expected %s, received %s; expected bytes %s, received bytes %s; content encoding %s; remote checksum %s).',
                    $expectedChecksum !== '' ? $expectedChecksum : 'missing',
                    $checksum !== false ? $checksum : 'unavailable',
                    $downloadMetadata['content_length'] ?? 'unknown',
                    $downloadMetadata['received_bytes'] ?? 'unknown',
                    $downloadMetadata['content_encoding'] ?? 'unspecified',
                    $downloadMetadata['remote_checksum'] ?? 'missing',
                ));
            }
        } catch (\Throwable $throwable) {
            @unlink($path);
            throw $throwable;
        }

        return $path;
    }

    private function downloadToFile(string $url, string $destinationPath, ?callable $progress): array
    {
        $destination = fopen($destinationPath, 'wb');
        if ($destination === false) {
            throw new \RuntimeException('Failed to open the artifact file for writing.');
        }

        $offset = 0;
        $totalBytes = null;
        $metadata = [];
        try {
            do {
                $chunkMetadata = $this->downloadChunk($url, $destination, $offset);
                $bytesCopied = (int) $chunkMetadata['received_bytes'];
                $reportedOffset = (int) ($chunkMetadata['chunk_offset'] ?? -1);
                $reportedTotal = (int) ($chunkMetadata['total_bytes'] ?? 0);
                if ($reportedOffset !== $offset || $reportedTotal <= 0 || $bytesCopied <= 0) {
                    throw new \RuntimeException('Remote artifact chunk metadata was invalid or incomplete.');
                }
                if ($totalBytes !== null && $reportedTotal !== $totalBytes) {
                    throw new \RuntimeException('Remote artifact size changed during download.');
                }

                $totalBytes = $reportedTotal;
                $offset += $bytesCopied;
                $metadata = $chunkMetadata;
                if ($progress !== null) {
                    $progress($offset, $totalBytes);
                }
            } while ($offset < $totalBytes);

            if ($offset !== $totalBytes) {
                throw new \RuntimeException('Downloaded artifact exceeded the remote artifact size.');
            }
        } finally {
            fclose($destination);
        }

        $metadata['received_bytes'] = $offset;
        $metadata['content_length'] = $totalBytes ?? 'unknown';

        return $metadata;
    }

    private function downloadChunk(string $url, $destination, int $offset): array
    {
        $chunkUrl = $this->addQueryParameters($url, [
            'offset' => $offset,
            'length' => self::DOWNLOAD_CHUNK_SIZE,
        ]);
        if ($this->usesDefaultTransport) {
            $context = $this->createStreamContext('GET');
            $source = fopen($chunkUrl, 'rb', false, $context);
            $responseHeaders = $http_response_header ?? [];
            if ($source === false) {
                throw new \RuntimeException(sprintf('HTTP request to %s failed.', $chunkUrl));
            }

            try {
                $this->assertSuccessfulResponse($chunkUrl, $responseHeaders);
                $bytesCopied = stream_copy_to_stream($source, $destination);
                if ($bytesCopied === false) {
                    throw new \RuntimeException('Failed to stream the downloaded artifact chunk to disk.');
                }
            } finally {
                fclose($source);
            }
        } else {
            [$body, $responseHeaders] = ($this->transport)('GET', $chunkUrl, null, $this->username, $this->applicationPassword);
            $this->assertSuccessfulResponse($chunkUrl, $responseHeaders);
            $bytesCopied = fwrite($destination, $body);
            if ($bytesCopied === false || $bytesCopied !== strlen($body)) {
                throw new \RuntimeException('Failed to persist the downloaded artifact chunk to disk.');
            }
        }

        $contentLength = (int) ($this->findLastHeaderValue($responseHeaders, 'Content-Length') ?? 0);
        if ($contentLength !== $bytesCopied) {
            throw new \RuntimeException(sprintf(
                'Artifact chunk was truncated (offset %d, expected %d bytes, received %d bytes).',
                $offset,
                $contentLength,
                $bytesCopied,
            ));
        }

        return [
            'received_bytes' => $bytesCopied,
            'total_bytes' => $this->findLastHeaderValue($responseHeaders, 'X-Municipio-Clone-Total-Bytes'),
            'chunk_offset' => $this->findLastHeaderValue($responseHeaders, 'X-Municipio-Clone-Chunk-Offset'),
            'content_encoding' => $this->findLastHeaderValue($responseHeaders, 'Content-Encoding') ?? 'unspecified',
            'remote_checksum' => $this->findLastHeaderValue($responseHeaders, 'X-Municipio-Clone-Checksum') ?? 'missing',
        ];
    }

    private function request(string $method, string $url, ?array $payload = null): string
    {
        [$body, $responseHeaders] = ($this->transport)($method, $url, $payload, $this->username, $this->applicationPassword);
        $this->assertSuccessfulResponse($url, $responseHeaders);

        return $body;
    }

    private function assertSuccessfulResponse(string $url, array $responseHeaders): void
    {
        $statusLine = $this->findLastStatusLine($responseHeaders);
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) !== 1) {
            throw new \RuntimeException(sprintf('HTTP response from %s did not include a valid status code.', $url));
        }

        $statusCode = (int) $matches[1];
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('HTTP request to %s returned status %d.', $url, $statusCode));
        }
    }

    private function defaultTransport(string $method, string $url, ?array $payload, string $username, string $applicationPassword): array
    {
        $context = $this->createStreamContext($method, $payload);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException(sprintf('HTTP request to %s failed.', $url));
        }

        return [$body, $http_response_header ?? []];
    }

    private function createStreamContext(string $method, ?array $payload = null)
    {
        return stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept-Encoding: identity',
                    'Authorization: Basic ' . base64_encode($this->username . ':' . $this->applicationPassword),
                ]),
                'content' => $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null,
                'ignore_errors' => true,
                'timeout' => 300,
            ],
        ]);
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

    private function findLastHeaderValue(array $responseHeaders, string $headerName): ?string
    {
        $value = null;
        $prefix = strtolower($headerName) . ':';
        foreach ($responseHeaders as $header) {
            if (str_starts_with(strtolower($header), $prefix)) {
                $value = trim(substr($header, strlen($prefix)));
            }
        }

        return $value;
    }

    private function addQueryParameters(string $url, array $parameters): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($parameters);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }
}
