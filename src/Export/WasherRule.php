<?php

declare(strict_types=1);

namespace MunicipioClone\Export;

/**
 * Defines a table or column washing rule.
 */
class WasherRule
{
    /**
     * @param string[] $conditionValues
     */
    public function __construct(
        private string $tablePattern,
        private string $strategy,
        private ?string $columnPattern = null,
        private ?string $type = null,
        private ?string $conditionColumn = null,
        private array $conditionValues = [],
    ) {
    }

    public function matchesTable(string $table): bool
    {
        return preg_match($this->tablePattern, $table) === 1;
    }

    public function matchesColumn(string $table, string $column, array $row): bool
    {
        if (!$this->matchesTable($table)) {
            return false;
        }

        if ($this->columnPattern === null || preg_match($this->columnPattern, $column) !== 1) {
            return false;
        }

        if ($this->conditionColumn === null) {
            return true;
        }

        return isset($row[$this->conditionColumn]) && in_array((string) $row[$this->conditionColumn], $this->conditionValues, true);
    }

    public function isTableExclusion(): bool
    {
        return $this->columnPattern === null && $this->strategy === 'exclude';
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function getType(): ?string
    {
        return $this->type;
    }
}
