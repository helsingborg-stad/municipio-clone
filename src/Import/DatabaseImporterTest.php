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

                return str_starts_with($command, 'db tables') ? "wp_posts\nwp_options" : '';
            }
        };
        $stages = [];
        $stageRunner = static function (string $stage, string $label, callable $operation) use (&$stages): mixed {
            $stages[] = [$stage, $label];

            return $operation();
        };

        (new DatabaseImporter($runner, 'https://clone.invalid'))->import(
            '/tmp/export.sql',
            'https://target.example.test',
            $stageRunner,
        );

        $this->assertSame([
            ['database_import', 'Importing SQL into the target database'],
            ['table_discovery', 'Discovering imported database tables'],
            ['url_replacement', 'Replacing source URLs in imported data'],
        ], $stages);
        $this->assertCount(3, $runner->commands);
    }
}