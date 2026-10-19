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
