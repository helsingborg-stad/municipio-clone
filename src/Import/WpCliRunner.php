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
        return $this->execute($command, ['launch' => false, 'return' => true, 'exit_error' => false]);
    }

    /**
     * Boots a new WP-CLI process so --url selects the target site before rewrite registration.
     */
    public function runInFreshProcess(string $command): string
    {
        return $this->execute($command, ['launch' => true, 'return' => 'all', 'exit_error' => false]);
    }

    private function execute(string $command, array $options): string
    {
        if (!class_exists('WP_CLI')) {
            throw new \RuntimeException('WP-CLI is required to run municipio clone.');
        }

        try {
            $result = \WP_CLI::runcommand($command, $options);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(sprintf('WP-CLI command failed: %s', $command), 0, $throwable);
        }

        if ($result === false) {
            throw new \RuntimeException(sprintf('WP-CLI command failed: %s', $command));
        }

        if (is_object($result)) {
            $result = get_object_vars($result);
        }

        if (is_array($result)) {
            $exitCode = $result['return_code'] ?? $result['exit_code'] ?? null;
            if ($exitCode === null && $options['launch'] === true) {
                throw new \RuntimeException(sprintf('WP-CLI command did not report an exit code: %s', $command));
            }
            $exitCode ??= 0;
            if ((int) $exitCode !== 0) {
                $output = trim((string) (($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '')));
                throw new \RuntimeException(sprintf('WP-CLI command failed with exit code %d: %s%s', (int) $exitCode, $command, $output !== '' ? ' - ' . $output : ''));
            }

            return trim((string) (($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '')));
        }

        if ($options['launch'] === true) {
            throw new \RuntimeException(sprintf('WP-CLI command did not report an exit code: %s', $command));
        }

        return (string) $result;
    }
}
