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
        $content = str_replace(sprintf('`%s', $sourcePrefix), sprintf('`%s', $targetPrefix), $content);
        $content = str_replace(sprintf(' %s', $sourcePrefix), sprintf(' %s', $targetPrefix), $content);
        file_put_contents($path, $content);

        return $path;
    }
}
