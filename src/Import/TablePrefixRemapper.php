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
        $content = preg_replace_callback(
            '/INSERT INTO `[^`]+_(options|usermeta)` .*?;/s',
            static function (array $matches) use ($sourcePrefix, $targetPrefix): string {
                return preg_replace(
                    "/'" . preg_quote($sourcePrefix, '/') . "(user_roles|capabilities|user_level)'/",
                    sprintf("'%s\$1'", $targetPrefix),
                    $matches[0],
                ) ?? $matches[0];
            },
            $content,
        ) ?? $content;

        $bytesWritten = file_put_contents($path, $content);
        if ($bytesWritten === false) {
            throw new \RuntimeException('Failed to persist the remapped SQL artifact.');
        }

        return $path;
    }
}
