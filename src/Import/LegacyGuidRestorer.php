<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Restores source GUIDs in exports created before GUIDs were exempted from URL replacement.
 */
class LegacyGuidRestorer
{
    /**
     * @param null|callable(int, int): void $onProgress Consumed and total artifact bytes.
     */
    public function restoreFile(string $path, string $placeholderUrl, string $sourceUrl, ?callable $onProgress = null): string
    {
        if ($placeholderUrl === '' || $sourceUrl === '') {
            throw new \InvalidArgumentException('The source and placeholder URLs are required to restore legacy GUIDs.');
        }

        $source = fopen($path, 'rb');
        $fileInfo = $source === false ? false : fstat($source);
        $totalBytes = $fileInfo['size'] ?? false;
        if ($source === false || $totalBytes === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            throw new \RuntimeException('Failed to open the SQL artifact for GUID restoration.');
        }

        $temporaryPath = tempnam(dirname($path), 'municipio_clone_guid_');
        if ($temporaryPath === false) {
            fclose($source);
            throw new \RuntimeException('Failed to create a temporary SQL artifact for GUID restoration.');
        }
        $destination = fopen($temporaryPath, 'wb');
        if ($destination === false) {
            fclose($source);
            @unlink($temporaryPath);
            throw new \RuntimeException('Failed to open the temporary SQL artifact for GUID restoration.');
        }

        $completed = false;
        try {
            if ($onProgress !== null) {
                $onProgress(0, $totalBytes);
            }
            while (($line = fgets($source)) !== false) {
                $restored = $this->restoreGuid($line, $placeholderUrl, $sourceUrl);
                $written = fwrite($destination, $restored);
                if ($written === false || $written !== strlen($restored)) {
                    throw new \RuntimeException('Failed to write the SQL artifact during GUID restoration.');
                }
                $position = ftell($source);
                if ($position === false) {
                    throw new \RuntimeException('Failed to measure SQL artifact GUID restoration progress.');
                }
                if ($onProgress !== null) {
                    $onProgress($position, $totalBytes);
                }
            }
            if (!feof($source)) {
                throw new \RuntimeException('Failed to read the SQL artifact during GUID restoration.');
            }
            $completed = true;
        } finally {
            fclose($source);
            fclose($destination);
            if (!$completed) {
                @unlink($temporaryPath);
            }
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Failed to replace the SQL artifact after GUID restoration.');
        }

        return $path;
    }

    /**
     * Changes only the quoted GUID value, leaving all other SQL fields untouched.
     */
    private function restoreGuid(string $line, string $placeholderUrl, string $sourceUrl): string
    {
        if (!str_starts_with($line, 'INSERT INTO `') || !str_contains($line, $placeholderUrl)) {
            return $line;
        }
        if (preg_match('/^INSERT INTO `[^`]+` \(([^)]*)\) VALUES \(/', $line, $matches) !== 1) {
            return $line;
        }
        $guidIndex = array_search('`guid`', explode(', ', $matches[1]), true);
        if ($guidIndex === false) {
            return $line;
        }

        $offset = strlen($matches[0]);
        for ($index = 0; $index <= $guidIndex; ++$index) {
            $end = $this->findValueEnd($line, $offset);
            if ($index === $guidIndex) {
                $value = substr($line, $offset, $end - $offset);
                if (!str_contains($value, $placeholderUrl)) {
                    return $line;
                }
                if (!str_starts_with($value, "'") || !str_ends_with($value, "'")) {
                    throw new \RuntimeException('Cannot restore an unquoted legacy GUID value.');
                }
                $escapedSourceUrl = strtr($sourceUrl, [
                    "\\" => "\\\\",
                    "'" => "\\'",
                    "\0" => "\\0",
                    "\n" => "\\n",
                    "\r" => "\\r",
                    "\t" => "\\t",
                    "\x1a" => "\\Z",
                ]);

                return substr($line, 0, $offset)
                    . str_replace($placeholderUrl, $escapedSourceUrl, $value)
                    . substr($line, $end);
            }
            $offset = $end + 2;
        }

        return $line;
    }

    /**
     * Locates a SQL field boundary without treating commas inside quoted values as separators.
     */
    private function findValueEnd(string $line, int $offset): int
    {
        $quoted = false;
        $escaped = false;
        $length = strlen($line);
        for ($index = $offset; $index < $length - 1; ++$index) {
            $character = $line[$index];
            if ($escaped) {
                $escaped = false;
            } elseif ($quoted && $character === '\\') {
                $escaped = true;
            } elseif ($character === "'") {
                $quoted = !$quoted;
            } elseif (!$quoted && (
                ($character === ',' && $line[$index + 1] === ' ')
                || ($character === ')' && $line[$index + 1] === ';')
            )) {
                return $index;
            }
        }

        throw new \RuntimeException('Could not parse a SQL value while restoring a legacy GUID.');
    }
}
