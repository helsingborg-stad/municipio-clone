<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Thin wrapper around WP-CLI command execution.
 */
class WpCliRunner
{
    /**
     * Imports the existing SQL file in a fresh WP-CLI process, enabling WP-CLI's file import optimizations.
     *
     * @param null|callable(): void $onWait Reports that the importer is still running.
     */
    public function importFromFile(string $path, ?callable $onWait = null): void
    {
        if (!class_exists('WP_CLI')) {
            throw new \RuntimeException('WP-CLI is required to import the SQL artifact.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Failed to open the SQL artifact for database import.');
        }

        $command = $this->importCommand($path);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start WP-CLI database import.');
        }

        $output = '';
        $completed = false;
        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            while (is_resource($pipes[1]) || is_resource($pipes[2])) {
                $read = [];
                foreach ([1, 2] as $descriptor) {
                    if (is_resource($pipes[$descriptor])) {
                        $read[] = $pipes[$descriptor];
                    }
                }
                $write = null;
                $except = null;
                $ready = stream_select($read, $write, $except, 1);
                if ($ready === false) {
                    throw new \RuntimeException('Failed to monitor WP-CLI database import.');
                }
                if ($onWait !== null) {
                    $onWait();
                }
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Failed to read WP-CLI database import output.');
                    }
                    $output = substr($output . $chunk, -65536);
                    if (feof($stream)) {
                        fclose($stream);
                    }
                }
            }

            $exitCode = proc_close($process);
            $completed = true;
            if ($exitCode !== 0) {
                throw new \RuntimeException(sprintf(
                    'WP-CLI database import failed with exit code %d%s',
                    $exitCode,
                    trim($output) !== '' ? ': ' . trim($output) : '.',
                ));
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (!$completed) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /**
     * Reuses the current WP-CLI executable, site selection, and root authorization.
     *
     * @return list<string>
     */
    protected function importCommand(string $path): array
    {
        $script = realpath((string) ($GLOBALS['argv'][0] ?? ''));
        if ($script === false) {
            throw new \RuntimeException('Could not locate the WP-CLI executable for database import.');
        }
        $command = [\WP_CLI::get_php_binary(), $script, 'db', 'import', $path];
        $path = \WP_CLI::get_config('path');
        if (is_string($path) && $path !== '') {
            $command[] = '--path=' . $path;
        }
        $url = \WP_CLI::get_config('url');
        if (is_string($url) && $url !== '') {
            $command[] = '--url=' . $url;
        }
        if (\WP_CLI::get_config('allow-root') === true) {
            $command[] = '--allow-root';
        }

        return $command;
    }

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
