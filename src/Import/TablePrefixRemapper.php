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
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            static function (array $matches) use ($sourcePrefix, $targetPrefix): string {
                $updated = preg_replace(
                    '/\\b' . preg_quote($sourcePrefix, '/') . '(user_roles|capabilities|user_level)\\b/',
                    $targetPrefix . '$1',
                    $matches[1],
                );

                return sprintf("'%s'", $updated ?? $matches[1]);
            },
            $content,
        ) ?? $content;
        file_put_contents($path, $content);

        return $path;
    }
}
