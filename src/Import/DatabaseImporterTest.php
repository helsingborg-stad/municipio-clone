<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Contracts\DatabaseConnectionInterface;
use MunicipioClone\Import\DatabaseImporter;
use MunicipioClone\Import\RemoteMediaUrlRewriter;
use MunicipioClone\Import\WpCliRunner;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\DatabaseImporter
 */
class DatabaseImporterTest extends TestCase
{
    public function testReportsEachImportSubstage(): void
    {
        $runner = new class() extends WpCliRunner {
            public array $commands = [];

            public function run(string $command): string
            {
                $this->commands[] = $command;

                return '';
            }
        };
        $databaseConnection = new class() implements DatabaseConnectionInterface {
            public ?int $requestedBlogId = null;

            public function getSiteContext(): array
            {
                return [];
            }

            public function getSiteTables(int $blogId): array
            {
                $this->requestedBlogId = $blogId;

                return ['mun_3_posts', 'mun_3_options'];
            }

            public function getCreateTableStatement(string $table): string
            {
                return '';
            }

            public function getRows(string $table): iterable
            {
                return [];
            }
        };
        $stages = [];
        $stageRunner = static function (string $stage, string $label, callable $operation) use (&$stages): mixed {
            $stages[] = [$stage, $label];

            return $operation();
        };

        $wpService = new MutableWpService();
        (new DatabaseImporter($runner, 'https://clone.invalid', $wpService, $databaseConnection, new RemoteMediaUrlRewriter($wpService)))->import(
            '/tmp/export.sql',
            'http://localhost:8080/hbgtest/',
            3,
            'mun_3_',
            $stageRunner,
        );

        $this->assertSame([
            ['database_import', 'Importing SQL into the target database'],
            ['table_discovery', 'Discovering imported database tables'],
            ['url_replacement', 'Replacing source URLs in imported data'],
            ['site_url_normalization', 'Normalizing target home and site URLs'],
            ['remote_media_url_configuration', 'Configuring remote media URLs'],
        ], $stages);
        $this->assertSame(3, $databaseConnection->requestedBlogId);
        $this->assertStringContainsString("'mun_3_posts' 'mun_3_options'", $runner->commands[1]);
        $this->assertStringContainsString('--all-tables-with-prefix', $runner->commands[1]);
        $this->assertStringContainsString('--skip-plugins --skip-themes', $runner->commands[1]);
        $this->assertStringNotContainsString('--url=', $runner->commands[1]);
        $this->assertSame('http://localhost:8080/hbgtest', $wpService->options[3]['home']);
        $this->assertSame('http://localhost:8080/hbgtest', $wpService->options[3]['siteurl']);
        $this->assertSame(1, $wpService->currentBlogId);
        $this->assertCount(2, $runner->commands);
    }

    public function testFailsWhenWordPressOverridesTheTargetSiteUrl(): void
    {
        $runner = new class() extends WpCliRunner {
            public function run(string $command): string
            {
                return '';
            }
        };
        $databaseConnection = new class() implements DatabaseConnectionInterface {
            public function getSiteContext(): array
            {
                return [];
            }

            public function getSiteTables(int $blogId): array
            {
                return ['mun_3_options'];
            }

            public function getCreateTableStatement(string $table): string
            {
                return '';
            }

            public function getRows(string $table): iterable
            {
                return [];
            }
        };
        $wpService = new class() extends MutableWpService {
            public function getOption(string $option, mixed $defaultValue = false): mixed
            {
                if ($option === 'siteurl') {
                    return 'https://localhost/hbgtest/';
                }

                return parent::getOption($option, $defaultValue);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Target option "siteurl" resolved to "https://localhost/hbgtest" instead of "http://localhost:8080/hbgtest". Check WP_HOME, WP_SITEURL, and URL filters.',
        );

        (new DatabaseImporter($runner, 'https://clone.invalid', $wpService, $databaseConnection, new RemoteMediaUrlRewriter($wpService)))->import(
            '/tmp/export.sql',
            'http://localhost:8080/hbgtest/',
            3,
            'mun_3_',
        );
    }

    public function testKeepsRemoteMediaUrlsWhenRequested(): void
    {
        $runner = new class() extends WpCliRunner {
            public array $commands = [];

            public function run(string $command): string
            {
                $this->commands[] = $command;

                return '';
            }
        };
        $databaseConnection = new class() implements DatabaseConnectionInterface {
            public function getSiteContext(): array
            {
                return [];
            }

            public function getSiteTables(int $blogId): array
            {
                return ['mun_3_posts'];
            }

            public function getCreateTableStatement(string $table): string
            {
                return '';
            }

            public function getRows(string $table): iterable
            {
                return [];
            }
        };

        $wpService = new MutableWpService();
        (new DatabaseImporter($runner, 'https://clone.invalid', $wpService, $databaseConnection, new RemoteMediaUrlRewriter($wpService)))->import(
            '/tmp/export.sql',
            'http://localhost:8080/hbgtest/',
            3,
            'mun_3_',
            null,
            'https://source.example.test/site-a/',
            true,
        );

        $this->assertCount(3, $runner->commands);
        $this->assertStringContainsString(
            "'https://clone.invalid/wp-content/uploads/' 'https://source.example.test/site-a/wp-content/uploads/'",
            $runner->commands[1],
        );
        $this->assertStringContainsString(
            "'https://clone.invalid' 'http://localhost:8080/hbgtest/'",
            $runner->commands[2],
        );
    }

    public function testUsesTheCapturedSourceMediaBaseUrlWhenProvided(): void
    {
        $runner = new class() extends WpCliRunner {
            public array $commands = [];

            public function run(string $command): string
            {
                $this->commands[] = $command;

                return '';
            }
        };
        $databaseConnection = new class() implements DatabaseConnectionInterface {
            public function getSiteContext(): array
            {
                return [];
            }

            public function getSiteTables(int $blogId): array
            {
                return ['mun_3_posts'];
            }

            public function getCreateTableStatement(string $table): string
            {
                return '';
            }

            public function getRows(string $table): iterable
            {
                return [];
            }
        };

        $wpService = new MutableWpService();
        (new DatabaseImporter($runner, 'https://clone.invalid', $wpService, $databaseConnection, new RemoteMediaUrlRewriter($wpService)))->import(
            '/tmp/export.sql',
            'http://localhost:8080/hbgtest/',
            3,
            'mun_3_',
            null,
            'https://source.example.test/site-a/',
            true,
            'https://media-cdn.example.test/uploads/networks/5/sites/196',
        );

        $this->assertStringContainsString(
            "'https://clone.invalid/wp-content/uploads/' 'https://media-cdn.example.test/uploads/networks/5/sites/196/'",
            $runner->commands[1],
        );
    }

    public function testFailsWhenNoTargetTablesAreDiscovered(): void
    {
        $runner = new class() extends WpCliRunner {
            public function run(string $command): string
            {
                return '';
            }
        };
        $databaseConnection = new class() implements DatabaseConnectionInterface {
            public function getSiteContext(): array
            {
                return [];
            }

            public function getSiteTables(int $blogId): array
            {
                return [];
            }

            public function getCreateTableStatement(string $table): string
            {
                return '';
            }

            public function getRows(string $table): iterable
            {
                return [];
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No imported tables were found with prefix "mun_4_".');

        $wpService = new MutableWpService();
        (new DatabaseImporter($runner, 'https://clone.invalid', $wpService, $databaseConnection, new RemoteMediaUrlRewriter($wpService)))->import(
            '/tmp/export.sql',
            'http://localhost:8080/visit/',
            4,
            'mun_4_',
        );
    }
}