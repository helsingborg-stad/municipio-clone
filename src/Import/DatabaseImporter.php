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

    public function import(string $artifactPath, string $targetUrl): void
    {
        $this->wpCliRunner->run(sprintf('db import %s', escapeshellarg($artifactPath)));
        $tables = $this->extractTables($artifactPath);
        $tableArguments = $tables !== [] ? ' ' . implode(' ', array_map('escapeshellarg', $tables)) : '';
        $this->wpCliRunner->run(sprintf(
            'search-replace %s %s%s --precise --skip-columns=guid --url=%s',
            escapeshellarg($this->placeholderUrl),
            escapeshellarg($targetUrl),
            $tableArguments,
            escapeshellarg($targetUrl),
        ));
    }

    /**
     * @return string[]
     */
    private function extractTables(string $artifactPath): array
    {
        $content = (string) file_get_contents($artifactPath);
        preg_match_all('/(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([A-Za-z0-9_]+)`/', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
