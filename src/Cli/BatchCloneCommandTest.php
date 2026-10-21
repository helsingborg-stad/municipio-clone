<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Cli;

use MunicipioClone\Cli\BatchCloneCommand;
use MunicipioClone\Cli\BatchConfigurationLoader;
use MunicipioClone\Cli\CloneMapping;
use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Import\TargetLockManager;
use MunicipioClone\Import\WpCliRunner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Cli\BatchCloneCommand
 */
class BatchCloneCommandTest extends TestCase
{
    public function testRunsEveryConfiguredMapping(): void
    {
        $runner = $this->runner();
        $runner->output = 'Success: Cloned a site.';
        \WP_CLI::$logMessages = [];
        $logger = new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $command = new BatchCloneCommand(
            $runner,
            $this->configurationLoaderFor($this->mappings()),
            $this->targetLockManager(),
            $logger,
        );
        putenv('MUNICIPIO_CLONE_BATCH_USER=clone-user');
        putenv('MUNICIPIO_CLONE_BATCH_PASSWORD=clone-password');

        try {
            $command->handle([], ['config' => '/not-used.json']);
        } finally {
            putenv('MUNICIPIO_CLONE_BATCH_USER');
            putenv('MUNICIPIO_CLONE_BATCH_PASSWORD');
        }

        $this->assertCount(4, $runner->calls);
        $this->assertStringContainsString("municipio clone --source-url='https://one.example.test'", $runner->calls[0][0]);
        $this->assertStringContainsString("--target-siteurl='https://one-target.example.test/wp'", $runner->calls[0][0]);
        $this->assertStringContainsString("--username-env='MUNICIPIO_CLONE_BATCH_CHILD_USERNAME'", $runner->calls[0][0]);
        $this->assertStringContainsString("--application-password-env='MUNICIPIO_CLONE_BATCH_CHILD_PASSWORD'", $runner->calls[0][0]);
        $this->assertStringNotContainsString('clone-password', $runner->calls[0][0]);
        $this->assertSame('clone-user', $runner->calls[0][1]);
        $this->assertSame('clone-password', $runner->calls[0][2]);
        $this->assertSame("cache flush --url='https://one-target.example.test'", $runner->calls[1][0]);
        $this->assertFalse($runner->calls[1][1]);
        $this->assertFalse($runner->calls[1][2]);
        $this->assertStringContainsString("--source-url='https://two.example.test'", $runner->calls[2][0]);
        $this->assertStringContainsString('--force', $runner->calls[2][0]);
        $this->assertStringContainsString('--keep-remote-media-urls', $runner->calls[2][0]);
        $this->assertSame("cache flush --url='https://two-target.example.test'", $runner->calls[3][0]);
        $this->assertContains('Success: Cloned a site.', \WP_CLI::$logMessages);
        $this->assertSame('municipio_clone_batch_completed', $logger->entries[2][0]);
        $this->assertSame(0, $logger->entries[2][1]['failure_count']);
    }

    public function testContinuesAfterMappingFailureAndReturnsAggregateError(): void
    {
        \WP_CLI::$warningMessages = [];
        $runner = $this->runner();
        $runner->onCommand = static function (string $command): void {
            if (str_contains($command, 'one.example.test')) {
                throw new \RuntimeException('Remote export failed.');
            }
        };
        $logger = new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $command = new BatchCloneCommand(
            $runner,
            $this->configurationLoaderFor($this->mappings()),
            $this->targetLockManager(),
            $logger,
        );
        putenv('MUNICIPIO_CLONE_BATCH_USER=clone-user');
        putenv('MUNICIPIO_CLONE_BATCH_PASSWORD=clone-password');

        try {
            try {
                $command->handle([], ['config' => '/not-used.json']);
                $this->fail('Expected an aggregate mapping failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('1 of 2 clone mappings failed.', $exception->getMessage());
            }
        } finally {
            putenv('MUNICIPIO_CLONE_BATCH_USER');
            putenv('MUNICIPIO_CLONE_BATCH_PASSWORD');
        }

        $this->assertCount(3, $runner->calls);
        $this->assertStringStartsWith('municipio clone ', $runner->calls[1][0]);
        $this->assertStringStartsWith('cache flush ', $runner->calls[2][0]);
        $this->assertSame('clone-user', $runner->calls[1][1]);
        $this->assertFalse(getenv('MUNICIPIO_CLONE_BATCH_CHILD_USERNAME'));
        $this->assertFalse(getenv('MUNICIPIO_CLONE_BATCH_CHILD_PASSWORD'));
        $this->assertContains('Failed to synchronize https://one-target.example.test: Remote export failed.', \WP_CLI::$warningMessages);
        $this->assertSame('municipio_clone_batch_mapping_failed', $logger->entries[0][0]);
        $this->assertSame('municipio_clone_batch_mapping_completed', $logger->entries[1][0]);
        $this->assertSame('municipio_clone_batch_completed', $logger->entries[2][0]);
    }

    public function testRunReportsMappingOnceAndExitsWithSummary(): void
    {
        \WP_CLI::$warningMessages = [];
        \WP_CLI::$errorMessages = [];
        $runner = $this->runner();
        $runner->onCommand = static function (string $command): void {
            if (str_contains($command, 'one.example.test')) {
                throw new \RuntimeException('Failed during "Requesting remote export": Remote export failed.');
            }
        };
        $logger = new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $command = new BatchCloneCommand(
            $runner,
            $this->configurationLoaderFor($this->mappings()),
            $this->targetLockManager(),
            $logger,
        );
        putenv('MUNICIPIO_CLONE_BATCH_USER=clone-user');
        putenv('MUNICIPIO_CLONE_BATCH_PASSWORD=clone-password');

        try {
            $command->run([], ['config' => '/not-used.json']);
            $this->fail('Expected WP-CLI to exit with an error.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('WP-CLI exited with an error.', $exception->getMessage());
        } finally {
            putenv('MUNICIPIO_CLONE_BATCH_USER');
            putenv('MUNICIPIO_CLONE_BATCH_PASSWORD');
        }

        $this->assertCount(3, $runner->calls);
        $this->assertSame([
            'Failed to synchronize https://one-target.example.test: Failed during "Requesting remote export": Remote export failed.',
        ], \WP_CLI::$warningMessages);
        $this->assertSame(['1 of 2 clone mappings failed.'], \WP_CLI::$errorMessages);
        $this->assertSame('municipio_clone_batch_mapping_failed', $logger->entries[0][0]);
        $this->assertStringContainsString('testRunReportsMappingOnceAndExitsWithSummary', $logger->entries[0][1]['error_trace']);
    }

    public function testCacheFlushFailureWarnsAndStillCompletesMapping(): void
    {
        \WP_CLI::$warningMessages = [];
        $runner = $this->runner();
        $runner->onCommand = static function (string $command): void {
            if (str_starts_with($command, 'cache flush')) {
                throw new \RuntimeException('Cache backend unavailable.');
            }
        };
        $logger = $this->logger();
        $command = new BatchCloneCommand($runner, $this->configurationLoaderFor($this->mappings()), $this->targetLockManager(), $logger);
        putenv('MUNICIPIO_CLONE_BATCH_USER=clone-user');
        putenv('MUNICIPIO_CLONE_BATCH_PASSWORD=clone-password');

        try {
            $command->handle([], ['config' => '/not-used.json']);
        } finally {
            putenv('MUNICIPIO_CLONE_BATCH_USER');
            putenv('MUNICIPIO_CLONE_BATCH_PASSWORD');
        }

        $this->assertCount(4, $runner->calls);
        $this->assertCount(2, \WP_CLI::$warningMessages);
        $this->assertSame('Cloned https://one-target.example.test, but could not flush its object cache: Cache backend unavailable.', \WP_CLI::$warningMessages[0]);
        $this->assertSame('municipio_clone_batch_cache_flush_failed', $logger->entries[0][0]);
        $this->assertSame('municipio_clone_batch_mapping_completed', $logger->entries[1][0]);
        $this->assertSame(0, $logger->entries[4][1]['failure_count']);
    }

    public function testRestoresPreviousChildEnvironmentAndReleasesLockAfterFailure(): void
    {
        \WP_CLI::$warningMessages = [];
        putenv('MUNICIPIO_CLONE_BATCH_CHILD_USERNAME=previous-user');
        putenv('MUNICIPIO_CLONE_BATCH_CHILD_PASSWORD=previous-password');
        putenv('MUNICIPIO_CLONE_BATCH_USER=clone-user');
        putenv('MUNICIPIO_CLONE_BATCH_PASSWORD=clone-password');
        $runner = $this->runner();
        $runner->onCommand = static function (string $command): void {
            if (str_starts_with($command, 'municipio clone')) {
                throw new \RuntimeException('Clone failed.');
            }
        };
        $lock = new class() extends TargetLockManager {
            public array $released = [];

            public function __construct()
            {
            }

            public function acquire(string $targetUrl): bool
            {
                return true;
            }

            public function release(string $targetUrl): void
            {
                $this->released[] = $targetUrl;
            }
        };
        $command = new BatchCloneCommand($runner, $this->configurationLoaderFor($this->mappings()), $lock, $this->logger());

        try {
            try {
                $command->handle([], ['config' => '/not-used.json']);
                $this->fail('Expected an aggregate mapping failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('2 of 2 clone mappings failed.', $exception->getMessage());
            }
            $this->assertSame('previous-user', getenv('MUNICIPIO_CLONE_BATCH_CHILD_USERNAME'));
            $this->assertSame('previous-password', getenv('MUNICIPIO_CLONE_BATCH_CHILD_PASSWORD'));
        } finally {
            foreach (['MUNICIPIO_CLONE_BATCH_CHILD_USERNAME', 'MUNICIPIO_CLONE_BATCH_CHILD_PASSWORD', 'MUNICIPIO_CLONE_BATCH_USER', 'MUNICIPIO_CLONE_BATCH_PASSWORD'] as $name) {
                putenv($name);
            }
        }

        $this->assertCount(2, $runner->calls);
        $this->assertSame(['https://one-target.example.test', 'https://two-target.example.test'], $lock->released);
    }

    public function testRunReportsConfigurationErrorsWithoutMappingWarnings(): void
    {
        \WP_CLI::$warningMessages = [];
        \WP_CLI::$errorMessages = [];
        $loader = new class() extends BatchConfigurationLoader {
            public function load(string $path): array
            {
                throw new \InvalidArgumentException('Invalid batch configuration.');
            }
        };
        $logger = new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $command = new BatchCloneCommand(
            $this->runner(),
            $loader,
            $this->targetLockManager(),
            $logger,
        );

        try {
            $command->run([], ['config' => '/invalid.json']);
            $this->fail('Expected WP-CLI to exit with an error.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('WP-CLI exited with an error.', $exception->getMessage());
        }

        $this->assertSame([], \WP_CLI::$warningMessages);
        $this->assertSame(['Invalid batch configuration.'], \WP_CLI::$errorMessages);
        $this->assertSame('municipio_clone_batch_command_failed', $logger->entries[0][0]);
        $this->assertNotEmpty($logger->entries[0][1]['error_trace']);
    }

    private function configurationLoaderFor(array $mappings): BatchConfigurationLoader
    {
        return new class($mappings) extends BatchConfigurationLoader {
            /** @param CloneMapping[] $mappings */
            public function __construct(private array $mappings)
            {
            }

            public function load(string $path): array
            {
                return $this->mappings;
            }
        };
    }

    private function runner(): WpCliRunner
    {
        return new class() extends WpCliRunner {
            public array $calls = [];
            public ?\Closure $onCommand = null;
            public string $output = '';

            public function runInFreshProcess(string $command): string
            {
                $this->calls[] = [
                    $command,
                    getenv('MUNICIPIO_CLONE_BATCH_CHILD_USERNAME'),
                    getenv('MUNICIPIO_CLONE_BATCH_CHILD_PASSWORD'),
                ];
                if ($this->onCommand !== null) {
                    ($this->onCommand)($command);
                }

                return $this->output;
            }
        };
    }

    private function logger(): LoggerInterface
    {
        return new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
    }

    private function targetLockManager(): TargetLockManager
    {
        return new class() extends TargetLockManager {
            public function __construct()
            {
            }

            public function acquire(string $targetUrl): bool
            {
                return true;
            }

            public function release(string $targetUrl): void
            {
            }
        };
    }

    /** @return CloneMapping[] */
    private function mappings(): array
    {
        return [
            new CloneMapping('https://one.example.test', 'https://one-target.example.test', 'MUNICIPIO_CLONE_BATCH_USER', 'MUNICIPIO_CLONE_BATCH_PASSWORD', false, false, 'https://one-target.example.test/wp'),
            new CloneMapping('https://two.example.test', 'https://two-target.example.test', 'MUNICIPIO_CLONE_BATCH_USER', 'MUNICIPIO_CLONE_BATCH_PASSWORD', true, true),
        ];
    }
}