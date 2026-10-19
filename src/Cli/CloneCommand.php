<?php

declare(strict_types=1);

namespace MunicipioClone\Cli;

use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Import\DatabaseImporter;
use MunicipioClone\Import\LegacyGuidRestorer;
use MunicipioClone\Import\RemoteExportClient;
use MunicipioClone\Import\TablePrefixRemapper;
use MunicipioClone\Import\TargetEnvironmentGuard;
use MunicipioClone\Import\TargetSiteManager;
use MunicipioClone\Support\Config;

/**
 * Implements the wp municipio clone command.
 */
class CloneCommand
{
    private string $activeStage = 'initializing';

    private ?string $failedStageLabel = null;

    private bool $commandCompleted = true;

    private ?string $fatalErrorMemoryReserve = null;

    private mixed $downloadProgressBar = null;

    private int $reportedDownloadBytes = 0;

    private mixed $stageProgressBar = null;

    private int $reportedStageBytes = 0;

    private float $lastStageProgressLogAt = 0.0;

    private bool $stageInputComplete = false;

    private LegacyGuidRestorer $legacyGuidRestorer;

    /**
    * @param callable(string, string):RemoteExportClient $remoteExportClientFactory
     */
    public function __construct(
        private TargetEnvironmentGuard $environmentGuard,
        private $remoteExportClientFactory,
        private TargetSiteManager $targetSiteManager,
        private TablePrefixRemapper $tablePrefixRemapper,
        private DatabaseImporter $databaseImporter,
        private LoggerInterface $logger,
        ?LegacyGuidRestorer $legacyGuidRestorer = null,
    ) {
        $this->legacyGuidRestorer = $legacyGuidRestorer ?? new LegacyGuidRestorer();
    }

    /**
     * Runs the single-site command as a WP-CLI entry point.
     *
     * @param array<int, string> $arguments Positional WP-CLI arguments.
     * @param array<string, mixed> $associativeArguments Named WP-CLI arguments.
     */
    public function run(array $arguments, array $associativeArguments): void
    {
        try {
            $this->handle($arguments, $associativeArguments);
        } catch (\Throwable $throwable) {
            $this->logger->info('municipio_clone_command_failed', [
                'error_type' => $throwable::class,
                'error_message' => $throwable->getMessage(),
                'error_trace' => $throwable->getTraceAsString(),
            ]);
            \WP_CLI::error($this->failureMessage($throwable));
        }
    }

    /**
     * Formats a clone failure for a user without exposing its stack trace.
     */
    public function failureMessage(\Throwable $throwable): string
    {
        return $this->failedStageLabel === null
            ? $throwable->getMessage()
            : sprintf('Failed during "%s": %s', $this->failedStageLabel, $throwable->getMessage());
    }

    /**
     * Performs a clone without exiting, so batch mappings can continue after failure.
     *
     * @param array<int, string> $arguments Positional WP-CLI arguments.
     * @param array<string, mixed> $associativeArguments Named WP-CLI arguments.
     */
    public function handle(array $arguments, array $associativeArguments): void
    {
        $this->failedStageLabel = null;
        $sourceUrl = (string) ($associativeArguments['source-url'] ?? '');
        $targetUrl = (string) ($associativeArguments['target'] ?? '');
        $username = (string) ($associativeArguments['username'] ?? '');
        $applicationPassword = (string) ($associativeArguments['application-password'] ?? $associativeArguments['password'] ?? '');
        if ($sourceUrl === '' || $targetUrl === '' || $username === '' || $applicationPassword === '') {
            throw new \InvalidArgumentException('The --source-url, --target, --username, and --application-password arguments are required.');
        }

        $force = array_key_exists('force', $associativeArguments) && (string) $associativeArguments['force'] !== 'false';
        $keepRemoteMediaUrls = array_key_exists('keep-remote-media-urls', $associativeArguments)
            && (string) $associativeArguments['keep-remote-media-urls'] !== 'false';

        $this->commandCompleted = false;
        $commandStartedAt = microtime(true);
        $this->fatalErrorMemoryReserve = str_repeat(' ', 256 * 1024);
        $this->registerFatalShutdownReporter($sourceUrl, $targetUrl);

        try {
            $this->runStage('environment_validation', 'Validating target environment', fn() => $this->environmentGuard->assertSafe());
            $targetSite = $this->runStage(
                'target_preparation',
                'Preparing target site',
                fn(): array => $this->targetSiteManager->prepare($targetUrl, $associativeArguments),
            );
            $remoteExportClient = ($this->remoteExportClientFactory)($username, $applicationPassword);
            $manifest = $this->runStage(
                'remote_export',
                'Requesting remote export',
                fn(): array => $remoteExportClient->requestExport($sourceUrl, $force),
            );
            $artifactPath = $this->runStage(
                'artifact_download',
                'Downloading export artifact',
                fn(): string => $remoteExportClient->downloadArtifact(
                    $manifest,
                    fn(int $downloadedBytes, int $totalBytes): null => $this->reportDownloadProgress($downloadedBytes, $totalBytes),
                ),
            );

            try {
                $artifactPath = $this->runStage(
                    'prefix_remapping',
                    'Remapping database table prefixes',
                    fn(): string => $this->tablePrefixRemapper->remapFile(
                        $artifactPath,
                        (string) ($manifest['source_table_prefix'] ?? 'wp_'),
                        (string) $targetSite['table_prefix'],
                        fn(int $processed, int $total): null => $this->reportStageProgress('Remapping SQL', $processed, $total),
                    ),
                );
                $this->runStage(
                    'legacy_guid_restoration',
                    'Restoring legacy GUIDs',
                    fn(): string => $this->legacyGuidRestorer->restoreFile(
                        $artifactPath,
                        (string) ($manifest['placeholder_url'] ?? Config::placeholderUrl()),
                        (string) ($manifest['source_url'] ?? $sourceUrl),
                        fn(int $processed, int $total): null => $this->reportStageProgress('Restoring GUIDs', $processed, $total),
                    ),
                );
                $this->databaseImporter->import(
                    $artifactPath,
                    (string) $targetSite['url'],
                    (int) $targetSite['blog_id'],
                    (string) $targetSite['table_prefix'],
                    fn(string $stage, string $label, callable $operation): mixed => $this->runStage($stage, $label, $operation),
                    $sourceUrl,
                    $keepRemoteMediaUrls,
                    (string) ($manifest['source_media_base_url'] ?? ''),
                    (string) ($targetSite['siteurl'] ?? $targetSite['url']),
                    fn(int $sent, int $total, bool $waiting): null => $this->reportStageProgress('Sending SQL to importer', $sent, $total, $waiting),
                );
            } finally {
                @unlink($artifactPath);
            }

            try {
                $this->runStage(
                    'rewrite_flush',
                    'Flushing target rewrite rules',
                    fn() => $this->databaseImporter->flushTargetRewriteRules((string) $targetSite['url']),
                );
            } catch (\RuntimeException $throwable) {
                $this->failedStageLabel = null;
                \WP_CLI::warning(sprintf(
                    'Cloned %s to %s, but could not flush its rewrite rules: %s',
                    $sourceUrl,
                    $targetSite['url'],
                    $throwable->getMessage(),
                ));
            }

            $this->logger->info('municipio_clone_import_completed', [
                'source' => $sourceUrl,
                'target' => $targetSite['url'],
                'cache_status' => $manifest['cache_status'] ?? 'unknown',
            ]);

            if (class_exists('WP_CLI')) {
                \WP_CLI::success(sprintf(
                    'Cloned %s to %s in %.1fs.',
                    $sourceUrl,
                    $targetSite['url'],
                    microtime(true) - $commandStartedAt,
                ));
            }
        } finally {
            $this->finishDownloadProgressBar();
            $this->commandCompleted = true;
            $this->fatalErrorMemoryReserve = null;
        }
    }

    private function runStage(string $stage, string $label, callable $operation): mixed
    {
        $this->activeStage = $stage;
        $this->lastStageProgressLogAt = 0.0;
        $this->stageInputComplete = false;
        $startedAt = microtime(true);
        $this->writeProgress($label . '...');
        $this->logger->info('municipio_clone_stage_started', [
            'stage' => $stage,
            'memory_bytes' => memory_get_usage(true),
        ]);

        try {
            $result = $operation();
        } catch (\Throwable $throwable) {
            $this->failedStageLabel = $label;
            $context = [
                'stage' => $stage,
                'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                'memory_bytes' => memory_get_usage(true),
                'error_type' => $throwable::class,
                'error_message' => $throwable->getMessage(),
            ];
            $this->logger->info('municipio_clone_stage_failed', $context);
            $this->finishDownloadProgressBar();
            if ($this->stageProgressBar !== null) {
                $this->stageProgressBar = null;
                $this->writeProgress('');
            }

            throw $throwable;
        }

        $this->failedStageLabel = null;
        $this->finishStageProgressBar();
        $this->logger->info('municipio_clone_stage_completed', [
            'stage' => $stage,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'memory_bytes' => memory_get_usage(true),
        ]);

        return $result;
    }

    private function registerFatalShutdownReporter(string $sourceUrl, string $targetUrl): void
    {
        register_shutdown_function(function () use ($sourceUrl, $targetUrl): void {
            if ($this->commandCompleted) {
                return;
            }

            $this->fatalErrorMemoryReserve = null;

            $error = error_get_last();
            if (!is_array($error) || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }

            $context = [
                'stage' => $this->activeStage,
                'source' => $sourceUrl,
                'target' => $targetUrl,
                'memory_bytes' => memory_get_usage(true),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'error_message' => (string) ($error['message'] ?? 'Unknown fatal error.'),
                'error_file' => (string) ($error['file'] ?? ''),
                'error_line' => (int) ($error['line'] ?? 0),
            ];
            $this->logger->info('municipio_clone_fatal_error', $context);
            $this->writeWarning(sprintf(
                'Clone stopped during stage "%s" because of a fatal error: %s',
                $this->activeStage,
                $context['error_message'],
            ));
        });
    }

    private function writeProgress(string $message): void
    {
        if (class_exists('WP_CLI') && method_exists('WP_CLI', 'log')) {
            \WP_CLI::log($message);
        }
    }

    private function writeWarning(string $message): void
    {
        if (class_exists('WP_CLI') && method_exists('WP_CLI', 'warning')) {
            \WP_CLI::warning($message);

            return;
        }

        fwrite(STDERR, 'Warning: ' . $message . PHP_EOL);
    }

    private function reportDownloadProgress(int $downloadedBytes, int $totalBytes): null
    {
        $percentage = $totalBytes > 0 ? min(100, (int) floor(($downloadedBytes / $totalBytes) * 100)) : 0;
        $this->advanceDownloadProgressBar($downloadedBytes, $totalBytes);
        $this->logger->info('municipio_clone_download_progress', [
            'downloaded_bytes' => $downloadedBytes,
            'total_bytes' => $totalBytes,
            'percentage' => $percentage,
        ]);

        return null;
    }

    private function advanceDownloadProgressBar(int $downloadedBytes, int $totalBytes): void
    {
        if (!function_exists('WP_CLI\Utils\make_progress_bar')) {
            return;
        }

        if ($this->downloadProgressBar === null) {
            $this->reportedDownloadBytes = 0;
            $this->downloadProgressBar = \WP_CLI\Utils\make_progress_bar(
                sprintf('Downloading %s', $this->formatBytes($totalBytes)),
                $totalBytes,
            );
        }

        $this->downloadProgressBar->tick($downloadedBytes - $this->reportedDownloadBytes);
        $this->reportedDownloadBytes = $downloadedBytes;

        if ($downloadedBytes >= $totalBytes) {
            $this->finishDownloadProgressBar();
        }
    }

    private function finishDownloadProgressBar(): void
    {
        if ($this->downloadProgressBar === null) {
            return;
        }

        $this->downloadProgressBar->finish();
        $this->downloadProgressBar = null;
    }

    /**
     * Reports input bytes, not the number of SQL statements committed by the database.
     */
    private function reportStageProgress(string $label, int $processed, int $total, bool $waiting = false): null
    {
        $now = microtime(true);
        $finishedSending = $waiting && $processed === $total;
        $firstWait = $finishedSending && !$this->stageInputComplete;
        $this->stageInputComplete = $finishedSending;
        if (
            $this->stageProgressBar === null
            && !$waiting
            && $total > 0
            && function_exists('WP_CLI\Utils\make_progress_bar')
            && defined('STDOUT')
            && function_exists('stream_isatty')
            && stream_isatty(STDOUT)
        ) {
            $this->stageProgressBar = \WP_CLI\Utils\make_progress_bar($label . ' (' . $this->formatBytes($total) . ')', $total);
            $this->reportedStageBytes = 0;
        }
        if ($this->stageProgressBar !== null) {
            $this->stageProgressBar->tick($processed - $this->reportedStageBytes);
            $this->reportedStageBytes = $processed;
            if ($finishedSending) {
                $this->finishStageProgressBar();
            } else {
                return null;
            }
        }
        if ($this->lastStageProgressLogAt === 0.0 || $now - $this->lastStageProgressLogAt >= 5 || $firstWait || (!$waiting && $total > 0 && $processed === $total)) {
            $percentage = $total > 0 ? min(100, (int) floor(100 * $processed / $total)) : 100;
            $this->writeProgress(sprintf(
                '%s: %d%% (%s of %s)%s',
                $label,
                $percentage,
                $this->formatBytes($processed),
                $this->formatBytes($total),
                $finishedSending ? '; waiting for database import to finish...' : '',
            ));
            $this->lastStageProgressLogAt = $now;
        }

        return null;
    }

    /**
     * Completes the current stage's interactive progress display.
     */
    private function finishStageProgressBar(): void
    {
        if ($this->stageProgressBar === null) {
            return;
        }
        $this->stageProgressBar->finish();
        $this->stageProgressBar = null;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
