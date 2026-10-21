<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Export;

use MunicipioClone\Contracts\DatabaseConnectionInterface;
use MunicipioClone\Export\DataWasher;
use MunicipioClone\Export\FakeDataGenerator;
use MunicipioClone\Export\SqlExportGenerator;
use MunicipioClone\Export\WasherRegistry;
use MunicipioClone\Support\PlaceholderReplacer;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Export\SqlExportGenerator
 */
class SqlExportGeneratorTest extends TestCase
{
    public function testPreservesMaximumLengthGuidWhileReplacingOtherSourceUrls(): void
    {
        $sourceUrl = 'https://s.test';
        $placeholderUrl = 'https://municipio-clone-placeholder.invalid';
        $guid = $sourceUrl . '/' . str_repeat('g', 255 - strlen($sourceUrl) - 1);
        $this->assertSame(255, strlen($guid));
        $this->assertGreaterThan(255, strlen(str_replace($sourceUrl, $placeholderUrl, $guid)));
        $databaseConnection = new class($sourceUrl, $guid) implements DatabaseConnectionInterface {
            public function __construct(private string $sourceUrl, private string $guid)
            {
            }

            public function getSiteContext(): array
            {
                return ['source_url' => $this->sourceUrl, 'blog_id' => 2, 'table_prefix' => 'wp_2_'];
            }

            public function getSiteTables(int $blogId): array
            {
                return ['wp_2_posts'];
            }

            public function getCreateTableStatement(string $table): string
            {
                return 'CREATE TABLE `wp_2_posts` (`guid` varchar(255), `post_content` text)';
            }

            public function getRows(string $table): iterable
            {
                yield ['guid' => $this->guid, 'post_content' => $this->sourceUrl . '/news'];
                yield ['guid' => 'urn:post:3', 'post_content' => $this->sourceUrl . '/events'];
            }
        };
        $wpService = new MutableWpService();
        $generator = new SqlExportGenerator(
            $databaseConnection,
            new DataWasher((new WasherRegistry($wpService))->all(), new FakeDataGenerator()),
            new PlaceholderReplacer($wpService),
            $placeholderUrl,
        );

        $export = $generator->generate();
        try {
            $sql = file_get_contents($export['content_path']);

            $this->assertTrue($export['guid_preserved']);
            $this->assertStringContainsString(sprintf("VALUES ('%s', '%s/news')", $guid, $placeholderUrl), $sql);
            $this->assertStringContainsString(sprintf("('urn:post:3', '%s/events')", $placeholderUrl), $sql);
            $this->assertSame(1, substr_count($sql, 'INSERT INTO `wp_2_posts`'));
            $this->assertStringNotContainsString($placeholderUrl . substr($guid, strlen($sourceUrl)), $sql);
        } finally {
            unlink($export['content_path']);
        }
    }

    public function testBatchesRowsByCountAndFlushesAtTableBoundary(): void
    {
        $rows = [];
        for ($id = 1; $id <= 258; ++$id) {
            $rows[] = ['ID' => $id, 'post_content' => 'content'];
        }

        $sql = $this->generateSql([
            'wp_2_posts' => $rows,
            'wp_2_options' => [['option_name' => 'home', 'option_value' => 'https://s.test']],
        ]);

        $this->assertSame(2, substr_count($sql, 'INSERT INTO `wp_2_posts`'));
        $this->assertSame(1, substr_count($sql, 'INSERT INTO `wp_2_options`'));
        $this->assertStringContainsString("(256, 'content');\n\nINSERT INTO `wp_2_posts`", $sql);
        $this->assertStringContainsString("(258, 'content');\n\nDROP TABLE IF EXISTS `wp_2_options`", $sql);
        $this->assertStringContainsString("'https://municipio-clone-placeholder.invalid'", $sql);
    }

    public function testFlushesWhenColumnLayoutChangesWithoutMixingValues(): void
    {
        $sql = $this->generateSql([
            'wp_2_posts' => [
                ['ID' => 1, 'post_content' => "a'b\nc"],
                ['ID' => 2, 'guid' => 'https://s.test/post'],
                ['guid' => null, 'ID' => 3],
            ],
        ]);

        $this->assertSame(3, substr_count($sql, 'INSERT INTO `wp_2_posts`'));
        $this->assertStringContainsString("(`ID`, `post_content`) VALUES (1, 'a\\'b\\nc');", $sql);
        $this->assertStringContainsString("(`ID`, `guid`) VALUES (2, 'https://s.test/post');", $sql);
        $this->assertStringContainsString('(`guid`, `ID`) VALUES (NULL, 3);', $sql);
    }

    public function testCapsStatementSizeButAllowsAnOversizedSingleRow(): void
    {
        $sql = $this->generateSql([
            'wp_2_posts' => [
                ['post_content' => str_repeat('a', 1024 * 1024)],
                ['post_content' => 'second'],
                ['post_content' => 'third'],
            ],
        ]);

        $statements = array_values(array_filter(explode("\n\n", $sql), static fn(string $part): bool => str_starts_with($part, 'INSERT INTO')));
        $this->assertCount(2, $statements);
        $this->assertGreaterThan(1024 * 1024, strlen($statements[0]));
        $this->assertLessThanOrEqual(1024 * 1024, strlen($statements[1]));
        $this->assertStringContainsString("VALUES ('second'), ('third');", $statements[1]);
    }

    public function testSplitsSmallRowsBeforeStatementExceedsOneMebibyte(): void
    {
        $rows = array_fill(0, 200, ['post_content' => str_repeat('x', 6000)]);

        $sql = $this->generateSql(['wp_2_posts' => $rows]);

        $statements = array_values(array_filter(explode("\n\n", $sql), static fn(string $part): bool => str_starts_with($part, 'INSERT INTO')));
        $this->assertCount(2, $statements);
        foreach ($statements as $statement) {
            $this->assertLessThanOrEqual(1024 * 1024, strlen($statement));
        }
        $this->assertSame(200, substr_count($sql, "('" . str_repeat('x', 6000) . "')"));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $tables
     */
    private function generateSql(array $tables): string
    {
        $databaseConnection = new class($tables) implements DatabaseConnectionInterface {
            public function __construct(private array $tables)
            {
            }

            public function getSiteContext(): array
            {
                return ['source_url' => 'https://s.test', 'blog_id' => 2, 'table_prefix' => 'wp_2_'];
            }

            public function getSiteTables(int $blogId): array
            {
                return array_keys($this->tables);
            }

            public function getCreateTableStatement(string $table): string
            {
                return sprintf('CREATE TABLE `%s` (`ID` int)', $table);
            }

            public function getRows(string $table): iterable
            {
                yield from $this->tables[$table];
            }
        };
        $wpService = new MutableWpService();
        $generator = new SqlExportGenerator(
            $databaseConnection,
            new DataWasher((new WasherRegistry($wpService))->all(), new FakeDataGenerator()),
            new PlaceholderReplacer($wpService),
            'https://municipio-clone-placeholder.invalid',
        );
        $export = $generator->generate();
        try {
            return (string) file_get_contents($export['content_path']);
        } finally {
            unlink($export['content_path']);
        }
    }
}
