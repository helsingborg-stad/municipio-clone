<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Thin wrapper around WP-CLI command execution.
 */
class WpCliRunner
{
    /**
     * Streams SQL into a fresh WP-CLI process without buffering the artifact in memory.
     *
     * @param callable(int, int, bool): void $onProgress Bytes sent, total bytes and whether input has finished.
     */
    public function importFromStream(string $path, callable $onProgress): void
    {
        if (!class_exists('WP_CLI')) {
            throw new \RuntimeException('WP-CLI is required to import the SQL artifact.');
        }

        $command = $this->importCommand();
        $source = fopen($path, 'rb');
        $fileInfo = $source === false ? false : fstat($source);
        $totalBytes = $fileInfo['size'] ?? false;
        if ($source === false || $totalBytes === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            throw new \RuntimeException('Failed to open the SQL artifact for database import.');
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            fclose($source);
            throw new \RuntimeException('Failed to start WP-CLI database import.');
        }

        $sentBytes = 0;
        $pending = '';
        $output = '';
        $inputOpen = true;
        $completed = false;
        try {
            stream_set_blocking($pipes[0], false);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $onProgress(0, $totalBytes, $totalBytes === 0);
            while ($inputOpen || is_resource($pipes[1]) || is_resource($pipes[2])) {
                if ($inputOpen && $pending === '' && !feof($source)) {
                    $chunk = fread($source, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Failed to read the SQL artifact during database import.');
                    }
                    $pending = $chunk;
                }
                if ($inputOpen && $pending === '' && feof($source)) {
                    fclose($pipes[0]);
                    $inputOpen = false;
                    $onProgress($sentBytes, $totalBytes, true);
                }

                $read = [];
                foreach ([1, 2] as $descriptor) {
                    if (is_resource($pipes[$descriptor])) {
                        $read[] = $pipes[$descriptor];
                    }
                }
                $write = $inputOpen && $pending !== '' ? [$pipes[0]] : [];
                if ($read === [] && $write === []) {
                    break;
                }
                $except = null;
                $ready = stream_select($read, $write, $except, 1);
                if ($ready === false) {
                    throw new \RuntimeException('Failed to monitor WP-CLI database import.');
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
                if ($write !== []) {
                    $written = @fwrite($pipes[0], $pending);
                    if ($written === false) {
                        fclose($pipes[0]);
                        $inputOpen = false;
                    } elseif ($written > 0) {
                        $sentBytes += $written;
                        $pending = substr($pending, $written);
                    }
                }
                $onProgress($sentBytes, $totalBytes, !$inputOpen);
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
            if ($sentBytes !== $totalBytes) {
                throw new \RuntimeException(sprintf('WP-CLI database import accepted %d of %d SQL bytes.', $sentBytes, $totalBytes));
            }
        } finally {
            fclose($source);
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
    protected function importCommand(): array
    {
        $script = realpath((string) ($GLOBALS['argv'][0] ?? ''));
        if ($script === false) {
            throw new \RuntimeException('Could not locate the WP-CLI executable for database import.');
        }
        $command = [\WP_CLI::get_php_binary(), $script, 'db', 'import', '-'];
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
