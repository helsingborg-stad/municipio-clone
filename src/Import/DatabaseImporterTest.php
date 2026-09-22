<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\DatabaseImporter;
use MunicipioClone\Import\WpCliRunner;
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

                if (str_starts_with($command, 'db tables')) {
                    return "wp_posts\nwp_options";
                }
                if (str_starts_with($command, "option get 'home'")) {
                    return 'http://localhost:8080/hbgtest';
                }
                if (str_starts_with($command, "option get 'siteurl'")) {
                    return 'http://localhost:8080/hbgtest';
                }

                return '';
            }
        };
        $stages = [];
        $stageRunner = static function (string $stage, string $label, callable $operation) use (&$stages): mixed {
            $stages[] = [$stage, $label];

            return $operation();
        };

        (new DatabaseImporter($runner, 'https://clone.invalid'))->import(
            '/tmp/export.sql',
            'http://localhost:8080/hbgtest/',
            $stageRunner,
        );

        $this->assertSame([
            ['database_import', 'Importing SQL into the target database'],
            ['table_discovery', 'Discovering imported database tables'],
            ['url_replacement', 'Replacing source URLs in imported data'],
            ['site_url_normalization', 'Normalizing target home and site URLs'],
        ], $stages);
        $this->assertContains(
            "option update 'home' 'http://localhost:8080/hbgtest' --url='http://localhost:8080/hbgtest/'",
            $runner->commands,
        );
        $this->assertContains(
            "option update 'siteurl' 'http://localhost:8080/hbgtest' --url='http://localhost:8080/hbgtest/'",
            $runner->commands,
        );
        $this->assertCount(7, $runner->commands);
    }

    public function testFailsWhenWordPressOverridesTheTargetSiteUrl(): void
    {
        $runner = new class() extends WpCliRunner {
            public function run(string $command): string
            {
                if (str_starts_with($command, 'db tables')) {
                    return 'wp_options';
                }
                if (str_starts_with($command, "option get 'home'")) {
                    return 'http://localhost:8080/hbgtest';
                }
                if (str_starts_with($command, "option get 'siteurl'")) {
                    return 'https://localhost/hbgtest/';
                }

                return '';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Target option "siteurl" resolved to "https://localhost/hbgtest" instead of "http://localhost:8080/hbgtest". Check WP_HOME, WP_SITEURL, and URL filters.',
        );

        (new DatabaseImporter($runner, 'https://clone.invalid'))->import(
            '/tmp/export.sql',
            'http://localhost:8080/hbgtest/',
        );
    }
}