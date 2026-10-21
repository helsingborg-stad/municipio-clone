<?php

declare(strict_types=1);

namespace MunicipioClone\Cli;

use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Import\TargetLockManager;
use MunicipioClone\Import\WpCliRunner;

/**
 * Runs multiple configured clone operations while isolating mapping failures.
 */
class BatchCloneCommand
{
    private const USERNAME_ENV = 'MUNICIPIO_CLONE_BATCH_CHILD_USERNAME';
    private const PASSWORD_ENV = 'MUNICIPIO_CLONE_BATCH_CHILD_PASSWORD';

    /**
     * @param WpCliRunner $wpCliRunner Fresh-process WP-CLI command runner.
     * @param BatchConfigurationLoader $configurationLoader Batch configuration reader.
     * @param TargetLockManager $targetLockManager Target synchronization lock.
     * @param LoggerInterface $logger Structured event logger.
     */
    public function __construct(
        private WpCliRunner $wpCliRunner,
        private BatchConfigurationLoader $configurationLoader,
        private TargetLockManager $targetLockManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs the batch command as a WP-CLI entry point.
     *
     * @param array<int, string> $arguments Positional WP-CLI arguments.
     * @param array<string, mixed> $associativeArguments Named WP-CLI arguments.
     */
    public function run(array $arguments, array $associativeArguments): void
    {
        try {
            $this->handle($arguments, $associativeArguments);
        } catch (\Throwable $throwable) {
            $this->logger->info('municipio_clone_batch_command_failed', [
                'error_type' => $throwable::class,
                'error_message' => $throwable->getMessage(),
                'error_trace' => $throwable->getTraceAsString(),
            ]);
            \WP_CLI::error($throwable->getMessage());
        }
    }

    /**
     * @param array<int, string> $arguments Positional WP-CLI arguments.
     * @param array<string, mixed> $associativeArguments Named WP-CLI arguments.
     */
    public function handle(array $arguments, array $associativeArguments): void
    {
        $configurationPath = (string) ($associativeArguments['config'] ?? '');
        $mappings = $this->configurationLoader->load($configurationPath);
        $failures = [];

        $mappingCount = count($mappings);
        foreach (array_values($mappings) as $index => $mapping) {
            $startedAt = microtime(true);
            try {
                $this->writeLog(sprintf('[%d/%d] Synchronizing %s to %s', $index + 1, $mappingCount, $mapping->sourceUrl, $mapping->targetUrl));
                if (!$this->targetLockManager->acquire($mapping->targetUrl)) {
                    throw new \RuntimeException(sprintf('Target "%s" is already being synchronized.', $mapping->targetUrl));
                }
                try {
                    $this->runClone($mapping);
                    try {
                        $this->wpCliRunner->runInFreshProcess(sprintf('cache flush --url=%s', escapeshellarg($mapping->targetUrl)));
                    } catch (\RuntimeException $throwable) {
                        $this->logger->info('municipio_clone_batch_cache_flush_failed', [
                            'target' => $mapping->targetUrl,
                            'error_type' => $throwable::class,
                            'error_message' => $throwable->getMessage(),
                        ]);
                        $this->writeWarning(sprintf('Cloned %s, but could not flush its object cache: %s', $mapping->targetUrl, $throwable->getMessage()));
                    }
                } finally {
                    $this->targetLockManager->release($mapping->targetUrl);
                }
                $this->logger->info('municipio_clone_batch_mapping_completed', [
                    'source' => $mapping->sourceUrl,
                    'target' => $mapping->targetUrl,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                ]);
            } catch (\Throwable $throwable) {
                $failures[] = $mapping->targetUrl;
                $this->logger->info('municipio_clone_batch_mapping_failed', [
                    'source' => $mapping->sourceUrl,
                    'target' => $mapping->targetUrl,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                    'error_type' => $throwable::class,
                    'error_message' => $throwable->getMessage(),
                    'error_trace' => $throwable->getTraceAsString(),
                ]);
                $this->writeWarning(sprintf('Failed to synchronize %s: %s', $mapping->targetUrl, $throwable->getMessage()));
            }
        }

        $this->logger->info('municipio_clone_batch_completed', [
            'mapping_count' => count($mappings),
            'failure_count' => count($failures),
        ]);
        if ($failures !== []) {
            throw new \RuntimeException(sprintf('%d of %d clone mappings failed.', count($failures), count($mappings)));
        }

        if (class_exists('WP_CLI')) {
            \WP_CLI::success(sprintf('Completed %d clone mappings.', count($mappings)));
        }
    }

    /**
     * Runs the single-site clone without putting credentials in process arguments.
     */
    private function runClone(CloneMapping $mapping): void
    {
        $arguments = $mapping->toAssociativeArguments();
        $environment = [
            self::USERNAME_ENV => (string) $arguments['username'],
            self::PASSWORD_ENV => (string) $arguments['application-password'],
        ];
        unset($arguments['username'], $arguments['application-password']);
        $arguments['username-env'] = self::USERNAME_ENV;
        $arguments['application-password-env'] = self::PASSWORD_ENV;

        $previous = [];
        try {
            foreach ($environment as $name => $value) {
                $previous[$name] = getenv($name);
                if (!putenv($name . '=' . $value)) {
                    throw new \RuntimeException(sprintf('Could not set temporary clone environment variable %s.', $name));
                }
            }
            $flags = [];
            foreach ($arguments as $name => $value) {
                $flags[] = '--' . $name . ($value === true ? '' : '=' . escapeshellarg((string) $value));
            }
            $output = $this->wpCliRunner->runInFreshProcess('municipio clone ' . implode(' ', $flags));
            if ($output !== '') {
                $this->writeLog($output);
            }
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                }
            }
        }
    }

    private function writeLog(string $message): void
    {
        if (class_exists('WP_CLI') && method_exists('WP_CLI', 'log')) {
            \WP_CLI::log($message);
        }
    }

    private function writeWarning(string $message): void
    {
        if (class_exists('WP_CLI') && method_exists('WP_CLI', 'warning')) {
            \WP_CLI::warning($message);
        }
    }
}