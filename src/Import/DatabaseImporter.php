<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Imports SQL artifacts and restores the target URL placeholder.
 */
class DatabaseImporter
{
    public function __construct(private WpCliRunner $wpCliRunner, private string $placeholderUrl)
    {
    }

    /**
     * @param null|callable(string, string, callable): mixed $stageRunner
     */
    public function import(string $artifactPath, string $targetUrl, ?callable $stageRunner = null): void
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
                'db tables --all-tables-with-prefix --format=csv --url=%s',
                escapeshellarg($targetUrl),
            )),
        );
        $tables = array_values(array_filter(array_map('trim', explode(',', str_replace("\n", ',', $tablesOutput)))));
        $tableArguments = $tables !== [] ? ' ' . implode(' ', array_map('escapeshellarg', $tables)) : '';
        $this->runStage(
            $stageRunner,
            'url_replacement',
            'Replacing source URLs in imported data',
            fn(): string => $this->wpCliRunner->run(sprintf(
                'search-replace %s %s%s --precise --skip-columns=guid --url=%s',
                escapeshellarg($this->placeholderUrl),
                escapeshellarg($targetUrl),
                $tableArguments,
                escapeshellarg($targetUrl),
            )),
        );
        $this->runStage(
            $stageRunner,
            'site_url_normalization',
            'Normalizing target home and site URLs',
            fn(): null => $this->normalizeSiteUrls($targetUrl),
        );
    }

    private function normalizeSiteUrls(string $targetUrl): null
    {
        $normalizedTargetUrl = rtrim($targetUrl, '/');
        foreach (['home', 'siteurl'] as $optionName) {
            $this->wpCliRunner->run(sprintf(
                'option update %s %s --url=%s',
                escapeshellarg($optionName),
                escapeshellarg($normalizedTargetUrl),
                escapeshellarg($targetUrl),
            ));
        }

        foreach (['home', 'siteurl'] as $optionName) {
            $actualUrl = rtrim($this->wpCliRunner->run(sprintf(
                'option get %s --url=%s',
                escapeshellarg($optionName),
                escapeshellarg($targetUrl),
            )), '/');
            if ($actualUrl !== $normalizedTargetUrl) {
                throw new \RuntimeException(sprintf(
                    'Target option "%s" resolved to "%s" instead of "%s". Check WP_HOME, WP_SITEURL, and URL filters.',
                    $optionName,
                    $actualUrl,
                    $normalizedTargetUrl,
                ));
            }
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
