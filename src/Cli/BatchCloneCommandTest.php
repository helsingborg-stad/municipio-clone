<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Cli;

use MunicipioClone\Cli\BatchCloneCommand;
use MunicipioClone\Cli\BatchConfigurationLoader;
use MunicipioClone\Cli\CloneCommand;
use MunicipioClone\Cli\CloneMapping;
use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Import\TargetLockManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Cli\BatchCloneCommand
 */
class BatchCloneCommandTest extends TestCase
{
    public function testRunsEveryConfiguredMapping(): void
    {
        $cloneCommand = new class() extends CloneCommand {
            public array $calls = [];

            public function __construct()
            {
            }

            public function handle(array $arguments, array $associativeArguments): void
            {
                $this->calls[] = $associativeArguments;
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
            $cloneCommand,
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

        $this->assertCount(2, $cloneCommand->calls);
        $this->assertSame('https://one.example.test', $cloneCommand->calls[0]['source-url']);
        $this->assertSame('https://two.example.test', $cloneCommand->calls[1]['source-url']);
        $this->assertSame('municipio_clone_batch_completed', $logger->entries[2][0]);
        $this->assertSame(0, $logger->entries[2][1]['failure_count']);
    }

    public function testContinuesAfterMappingFailureAndReturnsAggregateError(): void
    {
        \WP_CLI::$warningMessages = [];
        $cloneCommand = new class() extends CloneCommand {
            public array $calls = [];

            public function __construct()
            {
            }

            public function handle(array $arguments, array $associativeArguments): void
            {
                $this->calls[] = $associativeArguments;
                if ($associativeArguments['source-url'] === 'https://one.example.test') {
                    throw new \RuntimeException('Remote export failed.');
                }
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
            $cloneCommand,
            $this->configurationLoaderFor($this->mappings()),
            $this->targetLockManager(),
            $logger,
        );
        putenv('MUNICIPIO_CLONE_BATCH_USER=clone-user');
        putenv('MUNICIPIO_CLONE_BATCH_PASSWORD=clone-password');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('1 of 2 clone mappings failed.');
            $command->handle([], ['config' => '/not-used.json']);
        } finally {
            putenv('MUNICIPIO_CLONE_BATCH_USER');
            putenv('MUNICIPIO_CLONE_BATCH_PASSWORD');
        }

        $this->assertCount(2, $cloneCommand->calls);
        $this->assertContains('[municipio-clone] Failed to synchronize https://one-target.example.test: Remote export failed.', \WP_CLI::$warningMessages);
        $this->assertSame('municipio_clone_batch_mapping_failed', $logger->entries[0][0]);
        $this->assertSame('municipio_clone_batch_mapping_completed', $logger->entries[1][0]);
        $this->assertSame('municipio_clone_batch_completed', $logger->entries[2][0]);
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
            new CloneMapping('https://one.example.test', 'https://one-target.example.test', 'MUNICIPIO_CLONE_BATCH_USER', 'MUNICIPIO_CLONE_BATCH_PASSWORD', false, false),
            new CloneMapping('https://two.example.test', 'https://two-target.example.test', 'MUNICIPIO_CLONE_BATCH_USER', 'MUNICIPIO_CLONE_BATCH_PASSWORD', true, true),
        ];
    }
}