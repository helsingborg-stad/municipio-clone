<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

use WpService\WpService;

/**
 * Resolves or creates multisite targets before import.
 */
class TargetSiteManager
{
    public function __construct(private WpService $wpService)
    {
    }

    public function prepare(string $targetUrl): array
    {
        $blogId = $this->wpService->getCurrentBlogId();
        if (!$this->wpService->isMultisite()) {
            return [
                'url' => $targetUrl,
                'blog_id' => $blogId,
                'table_prefix' => $this->getBlogPrefix($blogId),
            ];
        }

        $targetParts = wp_parse_url($targetUrl);
        $domain = (string) ($targetParts['host'] ?? '');
        $path = (string) ($targetParts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_ends_with($path, '/')) {
            $path .= '/';
        }

        foreach ((array) $this->wpService->getSites(['number' => 0]) as $site) {
            $siteDomain = (string) ($site->domain ?? '');
            $sitePath = (string) ($site->path ?? '/');
            if ($siteDomain === $domain && $sitePath === $path) {
                if (class_exists('WP_CLI')) {
                    \WP_CLI::confirm(sprintf('Overwrite existing target subsite %s?', $targetUrl));
                }

                return [
                    'url' => $targetUrl,
                    'blog_id' => (int) ($site->blog_id ?? $blogId),
                    'table_prefix' => $this->getBlogPrefix((int) ($site->blog_id ?? $blogId)),
                ];
            }
        }

        $createdBlogId = $this->wpService->wpInsertSite([
            'domain' => $domain,
            'path' => $path,
        ]);
        if ($createdBlogId instanceof \WP_Error) {
            throw new \RuntimeException((string) $createdBlogId->get_error_message());
        }

        return [
            'url' => $targetUrl,
            'blog_id' => (int) $createdBlogId,
            'table_prefix' => $this->getBlogPrefix((int) $createdBlogId),
        ];
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
