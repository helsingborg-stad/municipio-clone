<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

use MunicipioClone\Contracts\DatabaseConnectionInterface;
use MunicipioClone\Database\StoredSiteOptions;
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
        private ?StoredSiteOptions $storedSiteOptions = null,
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
        ?string $sourceMediaBaseUrl = null,
        ?string $targetSiteUrl = null,
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
        $resolvedSourceMediaBaseUrl = '';
        if ($keepRemoteMediaUrls) {
            if ($sourceUrl === null || $sourceUrl === '') {
                throw new \InvalidArgumentException('A source URL is required when keeping remote media URLs.');
            }

            $resolvedSourceMediaBaseUrl = $sourceMediaBaseUrl !== null && $sourceMediaBaseUrl !== ''
                ? rtrim($sourceMediaBaseUrl, '/')
                : rtrim($sourceUrl, '/') . '/wp-content/uploads';

            $this->runStage(
                $stageRunner,
                'remote_media_url_restoration',
                'Keeping remote media URLs',
                fn(): string => $this->wpCliRunner->run(sprintf(
                    'search-replace %s %s%s --all-tables-with-prefix --precise --skip-columns=guid --skip-plugins --skip-themes --format=count',
                    escapeshellarg(rtrim($this->placeholderUrl, '/') . '/wp-content/uploads/'),
                    escapeshellarg($resolvedSourceMediaBaseUrl . '/'),
                    $tableArguments,
                )),
            );
        }
        $this->runStage(
            $stageRunner,
            'url_replacement',
            'Replacing source URLs in imported data',
            fn(): string => $this->wpCliRunner->run(sprintf(
                'search-replace %s %s%s --all-tables-with-prefix --precise --skip-columns=guid --skip-plugins --skip-themes --format=count',
                escapeshellarg($this->placeholderUrl),
                escapeshellarg($targetUrl),
                $tableArguments,
            )),
        );
        $this->runStage(
            $stageRunner,
            'site_url_normalization',
            'Normalizing target home and site URLs',
            fn(): null => $this->normalizeSiteUrls($targetBlogId, $targetUrl, $targetSiteUrl ?? $targetUrl),
        );
        $this->runStage(
            $stageRunner,
            'remote_media_url_configuration',
            'Configuring remote media URLs',
            fn(): null => $this->configureRemoteMediaUrls($targetBlogId, $resolvedSourceMediaBaseUrl, $keepRemoteMediaUrls),
        );
    }

    /**
     * Rebuilds only the target site's stored rewrite rules after the import has finished.
     */
    public function flushTargetRewriteRules(string $targetUrl): void
    {
        $this->wpCliRunner->runInFreshProcess(sprintf('rewrite flush --url=%s', escapeshellarg($targetUrl)));
    }

    private function normalizeSiteUrls(int $targetBlogId, string $targetUrl, string $targetSiteUrl): null
    {
        $expected = ['home' => rtrim($targetUrl, '/'), 'siteurl' => rtrim($targetSiteUrl, '/')];
        $storedOptions = $this->storedSiteOptions ?? new StoredSiteOptions();
        $this->wpService->switchToBlog($targetBlogId);
        try {
            foreach ($expected as $optionName => $url) {
                $storedOptions->set($targetBlogId, $optionName, $url);
            }
            $this->wpService->wpCacheDelete('alloptions', 'options');
            $this->wpService->wpCacheDelete('notoptions', 'options');
            foreach ($expected as $optionName => $url) {
                $this->wpService->wpCacheDelete($optionName, 'options');
                $stored = $storedOptions->get($targetBlogId, $optionName);
                if ($stored !== $url) {
                    throw new \RuntimeException(sprintf('Target option "%s" is stored as "%s" instead of "%s". Check database permissions.', $optionName, $stored ?? '(missing)', $url));
                }
                $actualUrl = rtrim((string) $this->wpService->getOption($optionName), '/');
                if ($actualUrl !== $url) {
                    throw new \RuntimeException(sprintf(
                        'Target option "%s" resolved to "%s" instead of "%s". Check WP_HOME, WP_SITEURL, and URL filters.',
                        $optionName,
                        $actualUrl,
                        $url,
                    ));
                }
            }
        } finally {
            $this->wpService->restoreCurrentBlog();
        }

        return null;
    }

    private function configureRemoteMediaUrls(int $targetBlogId, string $sourceMediaBaseUrl, bool $keepRemoteMediaUrls): null
    {
        $this->remoteMediaUrlRewriter->configure($targetBlogId, $sourceMediaBaseUrl, $keepRemoteMediaUrls);

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
