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

            $this->assertStringContainsString(sprintf("VALUES ('%s', '%s/news')", $guid, $placeholderUrl), $sql);
            $this->assertStringContainsString(sprintf("VALUES ('urn:post:3', '%s/events')", $placeholderUrl), $sql);
            $this->assertStringNotContainsString($placeholderUrl . substr($guid, strlen($sourceUrl)), $sql);
        } finally {
            unlink($export['content_path']);
        }
    }
}
