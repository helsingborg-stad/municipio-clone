<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

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
    ): void
    {
        $this->runStage(
            $stageRunner,
            'database_import',
            'Importing SQL into the target database',
            fn(): string => $this->wpCliRunner->run(sprintf('db import %s', escapeshellarg($artifactPath))),
        );
        $tablesOutput = $this->runStage(
            $stageRunner,
            'table_discovery',
            'Discovering imported database tables',
            fn(): string => $this->wpCliRunner->run(sprintf(
                'db query %s --skip-column-names',
                escapeshellarg(sprintf(
                    "SHOW TABLES LIKE '%s'",
                    addcslashes($targetTablePrefix, "\\_%'") . '%',
                )),
            )),
        );
        $tables = array_values(array_filter(array_map('trim', explode("\n", $tablesOutput))));
        if ($tables === []) {
            throw new \RuntimeException(sprintf('No imported tables were found with prefix "%s".', $targetTablePrefix));
        }
        $tableArguments = $tables !== [] ? ' ' . implode(' ', array_map('escapeshellarg', $tables)) : '';
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

    private function runStage(?callable $stageRunner, string $stage, string $label, callable $operation): mixed
    {
        if ($stageRunner !== null) {
            return $stageRunner($stage, $label, $operation);
        }

        return $operation();
    }
}
