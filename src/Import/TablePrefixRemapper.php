<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Rewrites SQL table prefixes for multisite subsite imports.
 */
class TablePrefixRemapper
{
    public function remapFile(string $path, string $sourcePrefix, string $targetPrefix): string
    {
        if ($sourcePrefix === $targetPrefix) {
            return $path;
        }

        $content = (string) file_get_contents($path);
        $content = preg_replace(
            '/`' . preg_quote($sourcePrefix, '/') . '([A-Za-z0-9_]+)`/',
            sprintf('`%s$1`', $targetPrefix),
            $content,
        ) ?? $content;
        $lines = explode("\n", $content);
        foreach ($lines as $index => $line) {
            if (preg_match('/INSERT INTO `[^`]+_(options|usermeta)` \(([^)]+)\) VALUES \((.+)\);$/', $line, $matches) !== 1) {
                continue;
            }

            $lines[$index] = preg_replace(
                "/'" . preg_quote($sourcePrefix, '/') . "(user_roles|capabilities|user_level)'/",
                sprintf("'%s\$1'", $targetPrefix),
                $line,
            ) ?? $line;
        }

        $bytesWritten = file_put_contents($path, implode("\n", $lines));
        if ($bytesWritten === false) {
            throw new \RuntimeException('Failed to persist the remapped SQL artifact.');
        }

        return $path;
    }
}
