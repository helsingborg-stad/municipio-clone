<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

use MunicipioClone\Contracts\DatabaseConnectionInterface;
use WpService\WpService;

/**
 * Imports SQL artifacts and restores the target URL placeholder.
 */
class DatabaseImporter
{
    public function __construct(
        private WpCliRunner $wpCliRunner,
        private string $placeholderUrl,
        private WpService $wpService,
        private DatabaseConnectionInterface $databaseConnection,
        private RemoteMediaUrlRewriter $remoteMediaUrlRewriter,
    )
    {
    }

    /**
     * @param null|callable(string, string, callable): mixed $stageRunner
     */
    public function import(
        string $artifactPath,
        string $targetUrl,
        int $targetBlogId,
        string $targetTablePrefix,
        ?callable $stageRunner = null,
        ?string $sourceUrl = null,
        bool $keepRemoteMediaUrls = false,
        int $sourceBlogId = 0,
    ): void
    {
        $this->runStage(
            $stageRunner,
            'database_import',
            'Importing SQL into the target database',
            fn(): string => $this->wpCliRunner->run(sprintf('db import %s', escapeshellarg($artifactPath))),
        );
        $tables = $this->runStage(
            $stageRunner,
            'table_discovery',
            'Discovering imported database tables',
            fn(): array => array_values(array_filter(array_map(
                'strval',
                $this->databaseConnection->getSiteTables($targetBlogId),
            ))),
        );
        if ($tables === []) {
            throw new \RuntimeException(sprintf('No imported tables were found with prefix "%s".', $targetTablePrefix));
        }
        $tableArguments = $tables !== [] ? ' ' . implode(' ', array_map('escapeshellarg', $tables)) : '';
        if ($keepRemoteMediaUrls) {
            if ($sourceUrl === null || $sourceUrl === '' || $sourceBlogId <= 0) {
                throw new \InvalidArgumentException('A source URL and source blog ID are required when keeping remote media URLs.');
            }

            $this->runStage(
                $stageRunner,
                'remote_media_url_restoration',
                'Keeping remote media URLs',
                fn(): string => $this->wpCliRunner->run(sprintf(
                    'search-replace %s %s%s --all-tables-with-prefix --precise --skip-columns=guid --skip-plugins --skip-themes',
                    escapeshellarg(rtrim($this->placeholderUrl, '/') . '/wp-content/uploads/'),
                    escapeshellarg(rtrim($sourceUrl, '/') . '/wp-content/uploads/'),
                    $tableArguments,
                )),
            );
        }
        $this->runStage(
            $stageRunner,
            'url_replacement',
            'Replacing source URLs in imported data',
            fn(): string => $this->wpCliRunner->run(sprintf(
                'search-replace %s %s%s --all-tables-with-prefix --precise --skip-columns=guid --skip-plugins --skip-themes',
                escapeshellarg($this->placeholderUrl),
                escapeshellarg($targetUrl),
                $tableArguments,
            )),
        );
        $this->runStage(
            $stageRunner,
            'site_url_normalization',
            'Normalizing target home and site URLs',
            fn(): null => $this->normalizeSiteUrls($targetBlogId, $targetUrl),
        );
        $this->runStage(
            $stageRunner,
            'remote_media_url_configuration',
            'Configuring remote media URLs',
            fn(): null => $this->configureRemoteMediaUrls($targetBlogId, $sourceBlogId, $keepRemoteMediaUrls),
        );
    }

    private function normalizeSiteUrls(int $targetBlogId, string $targetUrl): null
    {
        $normalizedTargetUrl = rtrim($targetUrl, '/');
        $this->wpService->switchToBlog($targetBlogId);
        try {
            foreach (['home', 'siteurl'] as $optionName) {
                $this->wpService->updateOption($optionName, $normalizedTargetUrl);
            }

            foreach (['home', 'siteurl'] as $optionName) {
                $actualUrl = rtrim((string) $this->wpService->getOption($optionName), '/');
                if ($actualUrl !== $normalizedTargetUrl) {
                    throw new \RuntimeException(sprintf(
                        'Target option "%s" resolved to "%s" instead of "%s". Check WP_HOME, WP_SITEURL, and URL filters.',
                        $optionName,
                        $actualUrl,
                        $normalizedTargetUrl,
                    ));
                }
            }
        } finally {
            $this->wpService->restoreCurrentBlog();
        }

        return null;
    }

    private function configureRemoteMediaUrls(int $targetBlogId, int $sourceBlogId, bool $keepRemoteMediaUrls): null
    {
        $this->remoteMediaUrlRewriter->configure($targetBlogId, $sourceBlogId, $keepRemoteMediaUrls);

        return null;
    }

    private function runStage(?callable $stageRunner, string $stage, string $label, callable $operation): mixed
    {
        if ($stageRunner !== null) {
            return $stageRunner($stage, $label, $operation);
        }

        return $operation();
    }
}
