<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

use MunicipioClone\Database\StoredSiteOptions;
use WpService\WpService;

/**
 * Resolves or creates multisite targets before import.
 */
class TargetSiteManager
{
    public function __construct(private WpService $wpService, private ?StoredSiteOptions $storedSiteOptions = null)
    {
    }

    public function prepare(string $targetUrl, array $associativeArguments = []): array
    {
        $explicitSiteUrl = $associativeArguments['target-siteurl'] ?? null;
        if ($explicitSiteUrl !== null) {
            $this->assertUrl((string) $explicitSiteUrl);
        }
        $blogId = $this->wpService->getCurrentBlogId();
        if (!$this->wpService->isMultisite()) {
            return [
                'url' => $targetUrl,
                'blog_id' => $blogId,
                'table_prefix' => $this->getBlogPrefix($blogId),
                'siteurl' => $this->resolveSiteUrl($blogId, $targetUrl, $explicitSiteUrl),
            ];
        }

        $targetParts = function_exists('wp_parse_url') ? wp_parse_url($targetUrl) : parse_url($targetUrl);
        if (
            $targetParts === false
            || !isset($targetParts['host'], $targetParts['scheme'])
            || !in_array(strtolower((string) $targetParts['scheme']), ['http', 'https'], true)
        ) {
            throw new \InvalidArgumentException('Target URL must be a valid absolute URL.');
        }

        $domain = (string) ($targetParts['host'] ?? '');
        if (isset($targetParts['port'])) {
            $domain .= ':' . (int) $targetParts['port'];
        }
        $path = (string) ($targetParts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_ends_with($path, '/')) {
            $path .= '/';
        }

        foreach ((array) $this->wpService->getSites(['domain' => $domain, 'path' => $path]) as $site) {
            $siteDomain = (string) ($site->domain ?? '');
            $sitePath = (string) ($site->path ?? '/');
            if ($siteDomain === $domain && $sitePath === $path) {
                if (class_exists('WP_CLI')) {
                    \WP_CLI::confirm(sprintf('Overwrite existing target subsite %s?', $targetUrl), $associativeArguments);
                }

                return [
                    'url' => $targetUrl,
                    'blog_id' => (int) ($site->blog_id ?? $blogId),
                    'table_prefix' => $this->getBlogPrefix((int) ($site->blog_id ?? $blogId)),
                    'siteurl' => $this->resolveSiteUrl((int) ($site->blog_id ?? $blogId), $targetUrl, $explicitSiteUrl),
                ];
            }
        }

        $hostWithoutPort = (string) ($targetParts['host'] ?? '');
        foreach ((array) $this->wpService->getSites(['path' => $path]) as $site) {
            if ((string) ($site->domain ?? '') !== $hostWithoutPort || (string) ($site->path ?? '/') !== $path) {
                continue;
            }

            if (class_exists('WP_CLI')) {
                \WP_CLI::confirm(sprintf('Update and overwrite existing target subsite %s?', $targetUrl), $associativeArguments);
            }

            $siteId = (int) ($site->blog_id ?? $blogId);
            $siteUrl = $this->resolveSiteUrl($siteId, $targetUrl, $explicitSiteUrl);
            $updateResult = $this->wpService->wpUpdateSite($siteId, [
                'domain' => $domain,
                'path' => $path,
                'scheme' => strtolower((string) $targetParts['scheme']),
            ]);
            if ($updateResult instanceof \WP_Error) {
                throw new \RuntimeException((string) $updateResult->get_error_message());
            }

            return [
                'url' => $targetUrl,
                'blog_id' => $siteId,
                'table_prefix' => $this->getBlogPrefix($siteId),
                'siteurl' => $siteUrl,
            ];
        }

        $createdBlogId = $this->wpService->wpInsertSite([
            'domain' => $domain,
            'path' => $path,
            'scheme' => strtolower((string) $targetParts['scheme']),
        ]);
        if ($createdBlogId instanceof \WP_Error) {
            throw new \RuntimeException((string) $createdBlogId->get_error_message());
        }

        return [
            'url' => $targetUrl,
            'blog_id' => (int) $createdBlogId,
            'table_prefix' => $this->getBlogPrefix((int) $createdBlogId),
            'siteurl' => $explicitSiteUrl === null ? rtrim($targetUrl, '/') : rtrim((string) $explicitSiteUrl, '/'),
        ];
    }

    /**
     * Resolves the installation URL from an override or the pre-import stored URL relationship.
     */
    private function resolveSiteUrl(int $blogId, string $targetUrl, mixed $explicitSiteUrl): string
    {
        if ($explicitSiteUrl !== null) {
            return rtrim((string) $explicitSiteUrl, '/');
        }

        $storedOptions = $this->storedSiteOptions ?? new StoredSiteOptions();
        $home = $storedOptions->get($blogId, 'home');
        $siteUrl = $storedOptions->get($blogId, 'siteurl');
        if ($siteUrl === null || $siteUrl === '') {
            return rtrim($targetUrl, '/');
        }
        $home = rtrim((string) $home, '/');
        $siteUrl = rtrim($siteUrl, '/');
        if ($home === '') {
            throw new \RuntimeException('Cannot infer target siteurl without a stored home URL. Pass --target-siteurl.');
        }
        try {
            $this->assertUrl($home);
            $this->assertUrl($siteUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Cannot safely infer target siteurl from stored options. Pass --target-siteurl.', 0, $exception);
        }
        $homeParts = parse_url($home);
        $siteParts = parse_url($siteUrl);
        if (
            $homeParts['scheme'] !== $siteParts['scheme']
            || $homeParts['host'] !== $siteParts['host']
            || ($homeParts['port'] ?? null) !== ($siteParts['port'] ?? null)
            || !str_starts_with($siteUrl, $home)
            || !in_array(substr($siteUrl, strlen($home), 1), ['', '/'], true)
        ) {
            throw new \RuntimeException('Cannot safely infer target siteurl from the existing home and siteurl. Pass --target-siteurl.');
        }

        return rtrim($targetUrl, '/') . substr($siteUrl, strlen($home));
    }

    /**
     * Rejects unsafe or ambiguous installation URLs.
     */
    private function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (
            $parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new \InvalidArgumentException('Target siteurl must be an absolute HTTP(S) URL without credentials, query or fragment.');
        }
    }

    private function getBlogPrefix(int $blogId): string
    {
        global $wpdb;

        if (isset($wpdb) && method_exists($wpdb, 'get_blog_prefix')) {
            return (string) $wpdb->get_blog_prefix($blogId);
        }

        return $blogId === 1 ? 'wp_' : sprintf('wp_%d_', $blogId);
    }
}
