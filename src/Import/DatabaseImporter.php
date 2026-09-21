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
        $this->wpCliRunner->run(sprintf(
            'search-replace %s %s --precise --all-tables-with-prefix --skip-columns=guid --url=%s',
            escapeshellarg($this->placeholderUrl),
            escapeshellarg($targetUrl),
            escapeshellarg($targetUrl),
        ));
    }
}
