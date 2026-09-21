<?php

declare(strict_types=1);

namespace MunicipioClone\Database;

use MunicipioClone\Contracts\DatabaseConnectionInterface;
use WpService\WpService;

/**
 * WordPress database reader used by the export generator.
 */
class WordPressDatabaseConnection implements DatabaseConnectionInterface
{
    public function __construct(private WpService $wpService)
    {
    }

    public function getSiteContext(): array
    {
        global $wpdb;

        $blogId = $this->wpService->getCurrentBlogId();
        $tablePrefix = method_exists($wpdb, 'get_blog_prefix') ? (string) $wpdb->get_blog_prefix($blogId) : ($blogId === 1 ? 'wp_' : sprintf('wp_%d_', $blogId));

        return [
            'blog_id' => $blogId,
            'source_url' => $this->wpService->getHomeUrl($blogId),
            'table_prefix' => $tablePrefix,
        ];
    }

    public function getSiteTables(int $blogId): array
    {
        global $wpdb;

        if (method_exists($wpdb, 'tables')) {
            return $wpdb->tables('blog', true, $blogId);
        }

        $prefix = method_exists($wpdb, 'get_blog_prefix') ? $wpdb->get_blog_prefix($blogId) : ($blogId === 1 ? 'wp_' : sprintf('wp_%d_', $blogId));
        $rows = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . '%'));

        return array_map('strval', $rows);
    }

    public function getCreateTableStatement(string $table): string
    {
        global $wpdb;

        $this->assertValidTableName($table);
        $row = $wpdb->get_row(sprintf('SHOW CREATE TABLE `%s`', $table), ARRAY_N);

        return (string) ($row[1] ?? '');
    }

    public function getRows(string $table): array
    {
        global $wpdb;

        $this->assertValidTableName($table);

        return $wpdb->get_results(sprintf('SELECT * FROM `%s`', $table), ARRAY_A) ?: [];
    }

    private function assertValidTableName(string $table): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid table name "%s".', $table));
        }
    }
}
