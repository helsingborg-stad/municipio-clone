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
        $statements = [sprintf('-- Municipio Clone export for %s', $context['source_url'])];

        foreach ($this->databaseConnection->getSiteTables((int) $context['blog_id']) as $table) {
            if ($this->dataWasher->shouldExcludeTable($table)) {
                continue;
            }

            $createStatement = $this->databaseConnection->getCreateTableStatement($table);
            if ($createStatement === '') {
                continue;
            }

            $statements[] = sprintf('DROP TABLE IF EXISTS `%s`;', $table);
            $statements[] = rtrim($createStatement, ';') . ';';

            foreach ($this->databaseConnection->getRows($table) as $row) {
                $sanitizedRow = $this->dataWasher->washRow($table, $row);
                $sanitizedRow = $this->placeholderReplacer->replace($sanitizedRow, (string) $context['source_url'], $this->placeholderUrl);
                $statements[] = $this->buildInsertStatement($table, $sanitizedRow);
            }
        }

        return [
            'content' => implode("\n\n", array_filter($statements)),
            'source_url' => (string) $context['source_url'],
            'source_blog_id' => (int) $context['blog_id'],
            'source_table_prefix' => (string) $context['table_prefix'],
        ];
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
