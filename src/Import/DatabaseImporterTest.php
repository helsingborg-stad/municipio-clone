<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\DatabaseImporter;
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

                if (str_starts_with($command, 'db query')) {
                    return "mun_3_posts\nmun_3_options";
                }

                return '';
            }
        };
        $stages = [];
        $stageRunner = static function (string $stage, string $label, callable $operation) use (&$stages): mixed {
            $stages[] = [$stage, $label];

            return $operation();
        };

        $wpService = new MutableWpService();
        (new DatabaseImporter($runner, 'https://clone.invalid', $wpService))->import(
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
        ], $stages);
        $this->assertStringContainsString('SHOW TABLES LIKE', $runner->commands[1]);
        $this->assertStringContainsString('mun\\_3\\_%', $runner->commands[1]);
        $this->assertStringContainsString("'mun_3_posts' 'mun_3_options'", $runner->commands[2]);
        $this->assertStringContainsString('--all-tables-with-prefix', $runner->commands[2]);
        $this->assertSame('http://localhost:8080/hbgtest', $wpService->options[3]['home']);
        $this->assertSame('http://localhost:8080/hbgtest', $wpService->options[3]['siteurl']);
        $this->assertSame(1, $wpService->currentBlogId);
        $this->assertCount(3, $runner->commands);
    }

    public function testFailsWhenWordPressOverridesTheTargetSiteUrl(): void
    {
        $runner = new class() extends WpCliRunner {
            public function run(string $command): string
            {
                return str_starts_with($command, 'db query') ? 'mun_3_options' : '';
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

        (new DatabaseImporter($runner, 'https://clone.invalid', $wpService))->import(
            '/tmp/export.sql',
            'http://localhost:8080/hbgtest/',
            3,
            'mun_3_',
        );
    }
}