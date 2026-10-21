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
    private const MAX_INSERT_ROWS = 256;
    private const MAX_INSERT_BYTES = 1024 * 1024;

    public function __construct(
        private DatabaseConnectionInterface $databaseConnection,
        private DataWasher $dataWasher,
        private PlaceholderReplacer $placeholderReplacer,
        private string $placeholderUrl,
    ) {
    }

    /**
     * @return array{content_path: string, source_url: string, source_blog_id: int, source_table_prefix: string, source_media_base_url: string, guid_preserved: bool}
     *         The caller owns the returned content_path and must delete it once it has been stored.
     */
    public function generate(): array
    {
        $context = $this->databaseConnection->getSiteContext();

        $tempFilePath = tempnam(sys_get_temp_dir(), 'municipio_clone_export_');
        if ($tempFilePath === false) {
            throw new \RuntimeException('Failed to create a temporary file for the export.');
        }

        try {
            $this->writeExportToFile($tempFilePath, $context);
        } catch (\Throwable $throwable) {
            @unlink($tempFilePath);
            throw $throwable;
        }

        return [
            'content_path' => $tempFilePath,
            'source_url' => (string) $context['source_url'],
            'source_blog_id' => (int) $context['blog_id'],
            'source_table_prefix' => (string) $context['table_prefix'],
            'source_media_base_url' => (string) ($context['media_base_url'] ?? ''),
            'guid_preserved' => true,
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
            $this->writeSql($handle, sprintf('-- Municipio Clone export for %s', $context['source_url']));

            foreach ($this->databaseConnection->getSiteTables((int) $context['blog_id']) as $table) {
                if ($this->dataWasher->shouldExcludeTable($table)) {
                    continue;
                }

                $createStatement = $this->databaseConnection->getCreateTableStatement($table);
                if ($createStatement === '') {
                    continue;
                }

                $this->writeSql($handle, sprintf("\n\nDROP TABLE IF EXISTS `%s`;\n%s", $table, rtrim($createStatement, ';') . ';'));

                $statement = '';
                $statementBytes = 0;
                $columns = [];
                $rowCount = 0;
                foreach ($this->databaseConnection->getRows($table) as $row) {
                    $sanitizedRow = $this->dataWasher->washRow($table, $row);
                    foreach ($sanitizedRow as $column => $value) {
                        if ($column !== 'guid') {
                            $sanitizedRow[$column] = $this->placeholderReplacer->replace($value, (string) $context['source_url'], $this->placeholderUrl);
                        }
                    }
                    $rowColumns = array_keys($sanitizedRow);
                    $values = '(' . implode(', ', array_map($this->formatValue(...), array_values($sanitizedRow))) . ')';
                    if ($rowCount > 0 && (
                        $columns !== $rowColumns
                        || $rowCount >= self::MAX_INSERT_ROWS
                        || $statementBytes + 2 + strlen($values) + 1 > self::MAX_INSERT_BYTES
                    )) {
                        $this->writeSql($handle, "\n\n" . $statement . ';');
                        $rowCount = 0;
                    }
                    if ($rowCount === 0) {
                        $columns = $rowColumns;
                        $statement = sprintf(
                            'INSERT INTO `%s` (%s) VALUES %s',
                            $table,
                            implode(', ', array_map(static fn(string $column): string => sprintf('`%s`', $column), $columns)),
                            $values,
                        );
                        $statementBytes = strlen($statement);
                    } else {
                        $statement .= ', ' . $values;
                        $statementBytes += 2 + strlen($values);
                    }
                    ++$rowCount;
                }
                if ($rowCount > 0) {
                    $this->writeSql($handle, "\n\n" . $statement . ';');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Writes the complete SQL fragment or fails rather than publishing a truncated export.
     *
     * @param resource $handle
     */
    private function writeSql($handle, string $sql): void
    {
        $length = strlen($sql);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($handle, substr($sql, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed to write the SQL export.');
            }
            $offset += $written;
        }
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
