<?php

declare(strict_types=1);

namespace MunicipioClone\Export;

use MunicipioClone\Contracts\DatabaseConnectionInterface;
use MunicipioClone\Support\PlaceholderReplacer;

/**
 * Generates a sanitized SQL export for the current site.
 */
class SqlExportGenerator
{
    public function __construct(
        private DatabaseConnectionInterface $databaseConnection,
        private DataWasher $dataWasher,
        private PlaceholderReplacer $placeholderReplacer,
        private string $placeholderUrl,
    ) {
    }

    public function generate(): array
    {
        $context = $this->databaseConnection->getSiteContext();

        $tempFilePath = tempnam(sys_get_temp_dir(), 'municipio_clone_export_');
        if ($tempFilePath === false) {
            throw new \RuntimeException('Failed to create a temporary file for the export.');
        }

        try {
            $this->writeExportToFile($tempFilePath, $context);
            $content = (string) file_get_contents($tempFilePath);
        } finally {
            @unlink($tempFilePath);
        }

        return [
            'content' => $content,
            'source_url' => (string) $context['source_url'],
            'source_blog_id' => (int) $context['blog_id'],
            'source_table_prefix' => (string) $context['table_prefix'],
        ];
    }

    /**
     * Writes SQL statements to disk as they are generated instead of holding
     * the entire dump in memory, since large tables can exceed available RAM.
     */
    private function writeExportToFile(string $tempFilePath, array $context): void
    {
        $handle = fopen($tempFilePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open a temporary file for the export.');
        }

        try {
            fwrite($handle, sprintf('-- Municipio Clone export for %s', $context['source_url']));

            foreach ($this->databaseConnection->getSiteTables((int) $context['blog_id']) as $table) {
                if ($this->dataWasher->shouldExcludeTable($table)) {
                    continue;
                }

                $createStatement = $this->databaseConnection->getCreateTableStatement($table);
                if ($createStatement === '') {
                    continue;
                }

                fwrite($handle, sprintf("\n\nDROP TABLE IF EXISTS `%s`;\n%s", $table, rtrim($createStatement, ';') . ';'));

                foreach ($this->databaseConnection->getRows($table) as $row) {
                    $sanitizedRow = $this->dataWasher->washRow($table, $row);
                    $sanitizedRow = $this->placeholderReplacer->replace($sanitizedRow, (string) $context['source_url'], $this->placeholderUrl);
                    fwrite($handle, "\n\n" . $this->buildInsertStatement($table, $sanitizedRow));
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function buildInsertStatement(string $table, array $row): string
    {
        $columns = array_map(static fn(string $column): string => sprintf('`%s`', $column), array_keys($row));
        $values = array_map(fn(mixed $value): string => $this->formatValue($value), array_values($row));

        return sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s);',
            $table,
            implode(', ', $columns),
            implode(', ', $values),
        );
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $escapedValue = strtr((string) $value, [
            "\\" => "\\\\",
            "'" => "\\'",
            "\0" => "\\0",
            "\n" => "\\n",
            "\r" => "\\r",
            "\t" => "\\t",
            "\x1a" => "\\Z",
        ]);

        return sprintf("'%s'", $escapedValue);
    }
}
