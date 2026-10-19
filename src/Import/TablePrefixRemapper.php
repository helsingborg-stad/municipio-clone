<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Rewrites SQL table prefixes for multisite subsite imports.
 */
class TablePrefixRemapper
{
    private const CHUNK_SIZE = 1024 * 1024;

    /**
     * @param null|callable(int, int): void $onProgress Consumed and total input bytes.
     */
    public function remapFile(string $path, string $sourcePrefix, string $targetPrefix, ?callable $onProgress = null): string
    {
        if ($sourcePrefix === $targetPrefix) {
            return $path;
        }

        $source = fopen($path, 'rb');
        $fileInfo = $source === false ? false : fstat($source);
        $totalBytes = $fileInfo['size'] ?? false;
        $temporaryPath = tempnam(dirname($path), 'municipio_clone_remap_');
        if ($source === false || $totalBytes === false || $temporaryPath === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if ($temporaryPath !== false) {
                @unlink($temporaryPath);
            }
            throw new \RuntimeException('Failed to open the SQL artifact for prefix remapping.');
        }

        $destination = fopen($temporaryPath, 'wb');
        if ($destination === false) {
            fclose($source);
            @unlink($temporaryPath);
            throw new \RuntimeException('Failed to open the remapped SQL artifact for writing.');
        }

        $remapCompleted = false;
        try {
            if ($onProgress !== null) {
                $onProgress(0, $totalBytes);
            }
            $buffer = '';
            $remapSiteKeys = false;
            $lineStarted = false;
            while (($chunk = fgets($source, self::CHUNK_SIZE)) !== false) {
                $bytesRead = ftell($source);
                if ($bytesRead === false) {
                    throw new \RuntimeException('Failed to measure SQL artifact prefix remapping progress.');
                }
                if (!$lineStarted) {
                    $remapSiteKeys = preg_match('/^INSERT INTO `[^`]+_(options|usermeta)` /', $chunk) === 1;
                    $lineStarted = true;
                }

                $buffer .= $chunk;
                $lineComplete = str_ends_with($chunk, "\n") || feof($source);
                $patterns = $this->replacementPatterns($sourcePrefix, $targetPrefix, $remapSiteKeys);
                $writeLength = $lineComplete ? strlen($buffer) : $this->safeReplacementLength($buffer, array_keys($patterns));
                if ($writeLength > 0) {
                    $this->writeOrFail($destination, str_replace(array_keys($patterns), array_values($patterns), substr($buffer, 0, $writeLength)));
                    $buffer = substr($buffer, $writeLength);
                }

                if ($lineComplete) {
                    $lineStarted = false;
                }
                if ($onProgress !== null) {
                    $onProgress($bytesRead, $totalBytes);
                }
            }

            if ($buffer !== '') {
                $patterns = $this->replacementPatterns($sourcePrefix, $targetPrefix, $remapSiteKeys);
                $this->writeOrFail($destination, str_replace(array_keys($patterns), array_values($patterns), $buffer));
            }

            if (!feof($source)) {
                throw new \RuntimeException('Failed to read the SQL artifact while remapping prefixes.');
            }
            $remapCompleted = true;
        } finally {
            fclose($source);
            fclose($destination);
            if (!$remapCompleted) {
                @unlink($temporaryPath);
            }
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Failed to replace the SQL artifact after prefix remapping.');
        }

        return $path;
    }

    private function replacementPatterns(string $sourcePrefix, string $targetPrefix, bool $remapSiteKeys): array
    {
        $patterns = ['`' . $sourcePrefix => '`' . $targetPrefix];
        if ($remapSiteKeys) {
            foreach (['user_roles', 'capabilities', 'user_level'] as $key) {
                $patterns["'" . $sourcePrefix . $key . "'"] = "'" . $targetPrefix . $key . "'";
            }
        }

        return $patterns;
    }

    /**
     * Returns a boundary that cannot split any replacement pattern.
     */
    private function safeReplacementLength(string $buffer, array $patterns): int
    {
        $maximumPatternLength = max(array_map('strlen', $patterns));
        $boundary = max(0, strlen($buffer) - $maximumPatternLength + 1);

        do {
            $previousBoundary = $boundary;
            foreach ($patterns as $pattern) {
                $searchOffset = max(0, $boundary - strlen($pattern) + 1);
                $position = strpos($buffer, $pattern, $searchOffset);
                while ($position !== false && $position < $boundary) {
                    if ($position + strlen($pattern) > $boundary) {
                        $boundary = $position;
                        break;
                    }
                    $position = strpos($buffer, $pattern, $position + 1);
                }
            }
        } while ($boundary !== $previousBoundary);

        return $boundary;
    }

    private function writeOrFail($handle, string $content): void
    {
        $bytesWritten = fwrite($handle, $content);
        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            throw new \RuntimeException('Failed to persist the remapped SQL artifact.');
        }
    }
}
