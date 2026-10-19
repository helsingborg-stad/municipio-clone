<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\WpCliRunner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\WpCliRunner
 */
class WpCliRunnerTest extends TestCase
{
    public function testStreamingImportInheritsExplicitRootAuthorization(): void
    {
        $runner = new class() extends WpCliRunner {
            public function command(): array
            {
                return $this->importCommand();
            }
        };
        $previousArguments = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'][0] = __FILE__;
        \WP_CLI::$config = ['path' => '/var/www/wordpress', 'url' => 'https://stage.example.test/site', 'allow-root' => true];

        try {
            $this->assertSame([
                PHP_BINARY,
                realpath(__FILE__),
                'db',
                'import',
                '-',
                '--path=/var/www/wordpress',
                '--url=https://stage.example.test/site',
                '--allow-root',
            ], $runner->command());

            \WP_CLI::$config['allow-root'] = false;
            $this->assertSame([
                PHP_BINARY,
                realpath(__FILE__),
                'db',
                'import',
                '-',
                '--path=/var/www/wordpress',
                '--url=https://stage.example.test/site',
            ], $runner->command());
        } finally {
            \WP_CLI::$config = [];
            if ($previousArguments === null) {
                unset($GLOBALS['argv']);
            } else {
                $GLOBALS['argv'] = $previousArguments;
            }
        }
    }

    public function testStreamsArtifactAndReportsBytesUntilChildCompletes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_stream_test_');
        $sql = str_repeat("SELECT 'payload';\n", 8000);
        file_put_contents($path, $sql);
        $runner = new class() extends WpCliRunner {
            protected function importCommand(): array
            {
                return [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 131072)); while (!feof(STDIN)) { $chunk = fread(STDIN, 16384); if ($chunk === false) { exit(3); } } fwrite(STDOUT, "Done");'];
            }
        };
        $updates = [];

        try {
            $runner->importFromStream($path, static function (int $sent, int $total, bool $waiting) use (&$updates): void {
                $updates[] = [$sent, $total, $waiting];
            });

            $this->assertSame([0, strlen($sql), false], $updates[0]);
            $this->assertContains([strlen($sql), strlen($sql), true], $updates);
            $this->assertGreaterThan(2, count($updates));
        } finally {
            @unlink($path);
        }
    }

    public function testStreamingImportReportsChildFailureWithoutClaimingSuccess(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_stream_test_');
        file_put_contents($path, 'SELECT 1;');
        $runner = new class() extends WpCliRunner {
            protected function importCommand(): array
            {
                return [PHP_BINARY, '-r', 'fwrite(STDERR, "Import rejected."); exit(7);'];
            }
        };

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('WP-CLI database import failed with exit code 7: Import rejected.');
            $runner->importFromStream($path, static function (int $sent, int $total, bool $waiting): void {
            });
        } finally {
            @unlink($path);
        }
    }

    public function testStreamingImportHandlesEmptyArtifact(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_stream_test_');
        $runner = new class() extends WpCliRunner {
            protected function importCommand(): array
            {
                return [PHP_BINARY, '-r', 'if (stream_get_contents(STDIN) !== "") { exit(4); }'];
            }
        };
        $updates = [];

        try {
            $runner->importFromStream($path, static function (int $sent, int $total, bool $waiting) use (&$updates): void {
                $updates[] = [$sent, $total, $waiting];
            });

            $this->assertSame([0, 0, true], $updates[0]);
        } finally {
            @unlink($path);
        }
    }

    protected function tearDown(): void
    {
        \WP_CLI::$runCommandResponse = null;
    }

    public function testFreshProcessReturnsOutputAndRequestsExitStatus(): void
    {
        \WP_CLI::$runCommands = [];
        \WP_CLI::$runCommandResponse = (object) ['stdout' => "Success: Flushed rewrite rules.\n", 'stderr' => '', 'return_code' => 0];

        $output = (new WpCliRunner())->runInFreshProcess("rewrite flush --url='https://local.example.test/site'");

        $this->assertSame('Success: Flushed rewrite rules.', $output);
        $this->assertSame([
            ["rewrite flush --url='https://local.example.test/site'", ['launch' => true, 'return' => 'all', 'exit_error' => false]],
        ], \WP_CLI::$runCommands);
    }

    public function testFreshProcessReportsNonzeroExitStatus(): void
    {
        \WP_CLI::$runCommandResponse = (object) ['stdout' => '', 'stderr' => 'Rewrite rules could not be saved.', 'return_code' => 1];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WP-CLI command failed with exit code 1: rewrite flush');

        (new WpCliRunner())->runInFreshProcess("rewrite flush --url='https://local.example.test/site'");
    }

    public function testFreshProcessRejectsMissingExitStatus(): void
    {
        \WP_CLI::$runCommandResponse = (object) ['stdout' => '', 'stderr' => ''];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WP-CLI command did not report an exit code');

        (new WpCliRunner())->runInFreshProcess("rewrite flush --url='https://local.example.test/site'");
    }

    public function testExistingCommandsRemainInProcess(): void
    {
        \WP_CLI::$runCommands = [];

        (new WpCliRunner())->run('db import /tmp/export.sql');

        $this->assertSame([
            ['db import /tmp/export.sql', ['launch' => false, 'return' => true, 'exit_error' => false]],
        ], \WP_CLI::$runCommands);
    }
}
