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
    public function testFileImportInheritsExplicitRootAuthorization(): void
    {
        $runner = new class() extends WpCliRunner {
            public function command(string $path): array
            {
                return $this->importCommand($path);
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
                '/tmp/export.sql',
                '--path=/var/www/wordpress',
                '--url=https://stage.example.test/site',
                '--allow-root',
            ], $runner->command('/tmp/export.sql'));

            \WP_CLI::$config['allow-root'] = false;
            $this->assertSame([
                PHP_BINARY,
                realpath(__FILE__),
                'db',
                'import',
                '/tmp/export.sql',
                '--path=/var/www/wordpress',
                '--url=https://stage.example.test/site',
            ], $runner->command('/tmp/export.sql'));
        } finally {
            \WP_CLI::$config = [];
            if ($previousArguments === null) {
                unset($GLOBALS['argv']);
            } else {
                $GLOBALS['argv'] = $previousArguments;
            }
        }
    }

    public function testFileImportReadsChildOutputWithoutDeadlocking(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_stream_test_');
        file_put_contents($path, str_repeat("SELECT 'payload';\n", 8000));
        $runner = new class() extends WpCliRunner {
            protected function importCommand(string $path): array
            {
                return [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 131072)); fwrite(STDERR, str_repeat("y", 131072)); if (file_get_contents($argv[1]) === false) { exit(3); } fwrite(STDOUT, "Done");', $path];
            }
        };

        $waits = 0;
        try {
            $runner->importFromFile($path, static function () use (&$waits): void {
                $waits++;
            });
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, $waits);
        } finally {
            @unlink($path);
        }
    }

    public function testFileImportReportsChildFailureWithoutClaimingSuccess(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_stream_test_');
        file_put_contents($path, 'SELECT 1;');
        $runner = new class() extends WpCliRunner {
            protected function importCommand(string $path): array
            {
                return [PHP_BINARY, '-r', 'fwrite(STDERR, "Import rejected."); exit(7);'];
            }
        };

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('WP-CLI database import failed with exit code 7: Import rejected.');
            $runner->importFromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testFileImportHandlesEmptyArtifact(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_stream_test_');
        $runner = new class() extends WpCliRunner {
            protected function importCommand(string $path): array
            {
                return [PHP_BINARY, '-r', 'if (file_get_contents($argv[1]) !== "") { exit(4); }', $path];
            }
        };

        try {
            $runner->importFromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testFileImportRejectsMissingArtifact(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open the SQL artifact');

        (new WpCliRunner())->importFromFile('/tmp/municipio-clone-missing-artifact.sql');
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
