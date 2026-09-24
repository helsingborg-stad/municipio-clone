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
            'media_base_url' => rtrim((string) ($this->wpService->wpGetUploadDir()['baseurl'] ?? ''), '/'),
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

    public function getRows(string $table): iterable
    {
        global $wpdb;

        $this->assertValidTableName($table);

        $connection = $wpdb->dbh ?? null;
        if ($connection instanceof \mysqli) {
            yield from $this->getRowsUnbuffered($connection, $table);

            return;
        }

        yield from $this->getRowsInChunks($table);
    }

    /**
     * Streams rows one at a time from an unbuffered mysqli result so a whole
     * table never has to be held in PHP memory at once.
     *
     * @return iterable<array<string, mixed>>
     */
    private function getRowsUnbuffered(\mysqli $connection, string $table): iterable
    {
        $result = $connection->query(sprintf('SELECT * FROM `%s`', $table), MYSQLI_USE_RESULT);
        if (!$result instanceof \mysqli_result) {
            return;
        }

        while (($row = $result->fetch_assoc()) !== null) {
            yield $row;
        }

        $result->free();
    }

    /**
     * Fallback for non-mysqli drivers: paginate the table instead of loading it whole.
     *
     * @return iterable<array<string, mixed>>
     */
    private function getRowsInChunks(string $table): iterable
    {
        global $wpdb;

        $chunkSize = 1000;
        $offset = 0;

        do {
            $rows = $wpdb->get_results(sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', $table, $chunkSize, $offset), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                yield $row;
            }
            $offset += $chunkSize;
        } while (count($rows) === $chunkSize);
    }

    private function assertValidTableName(string $table): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid table name "%s".', $table));
        }
    }
}
