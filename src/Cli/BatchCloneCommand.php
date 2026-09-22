<?php

declare(strict_types=1);

namespace MunicipioClone\Cli;

use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Import\TargetLockManager;

/**
 * Runs multiple configured clone operations while isolating mapping failures.
 */
class BatchCloneCommand
{
    /**
     * @param CloneCommand $cloneCommand Existing single-site command.
     * @param BatchConfigurationLoader $configurationLoader Batch configuration reader.
     * @param LoggerInterface $logger Structured event logger.
     */
    public function __construct(
        private CloneCommand $cloneCommand,
        private BatchConfigurationLoader $configurationLoader,
        private TargetLockManager $targetLockManager,
        private LoggerInterface $logger,
    ) {
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

        foreach ($mappings as $mapping) {
            $startedAt = microtime(true);
            try {
                $this->writeLog(sprintf('Synchronizing %s to %s', $mapping->sourceUrl, $mapping->targetUrl));
                if (!$this->targetLockManager->acquire($mapping->targetUrl)) {
                    throw new \RuntimeException(sprintf('Target "%s" is already being synchronized.', $mapping->targetUrl));
                }
                try {
                    $this->cloneCommand->handle([], $mapping->toAssociativeArguments());
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

    private function writeLog(string $message): void
    {
        if (class_exists('WP_CLI') && method_exists('WP_CLI', 'log')) {
            \WP_CLI::log('[municipio-clone] ' . $message);
        }
    }

    private function writeWarning(string $message): void
    {
        if (class_exists('WP_CLI') && method_exists('WP_CLI', 'warning')) {
            \WP_CLI::warning('[municipio-clone] ' . $message);
        }
    }
}