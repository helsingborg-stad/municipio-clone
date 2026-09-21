<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Thin wrapper around WP-CLI command execution.
 */
class WpCliRunner
{
    public function run(string $command): string
    {
        if (!class_exists('WP_CLI')) {
            throw new \RuntimeException('WP-CLI is required to run municipio clone.');
        }

        try {
            $result = \WP_CLI::runcommand($command, ['launch' => false, 'return' => true, 'exit_error' => false]);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(sprintf('WP-CLI command failed: %s', $command), 0, $throwable);
        }

        if ($result === false) {
            throw new \RuntimeException(sprintf('WP-CLI command failed: %s', $command));
        }

        if (is_array($result)) {
            $exitCode = $result['return_code'] ?? $result['exit_code'] ?? 0;
            if ((int) $exitCode !== 0) {
                $output = trim((string) (($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '')));
                throw new \RuntimeException(sprintf('WP-CLI command failed with exit code %d: %s%s', (int) $exitCode, $command, $output !== '' ? ' - ' . $output : ''));
            }

            return trim((string) (($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '')));
        }

        return (string) $result;
    }
}
