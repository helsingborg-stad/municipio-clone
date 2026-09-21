<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Thin wrapper around WP-CLI command execution.
 */
class WpCliRunner
{
    public function run(string $command): void
    {
        if (!class_exists('WP_CLI')) {
            throw new \RuntimeException('WP-CLI is required to run municipio clone.');
        }

        \WP_CLI::runcommand($command, ['launch' => false, 'return' => true]);
    }
}
