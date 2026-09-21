<?php

declare(strict_types=1);

namespace MunicipioClone\Export;

/**
 * Applies washer rules to exported rows.
 */
class DataWasher
{
    /**
     * @param WasherRule[] $rules
     */
    public function __construct(private array $rules, private FakeDataGenerator $fakeDataGenerator)
    {
    }

    public function shouldExcludeTable(string $table): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->isTableExclusion() && $rule->matchesTable($table)) {
                return true;
            }
        }

        return false;
    }

    public function washRow(string $table, array $row): array
    {
        foreach ($row as $column => $value) {
            foreach ($this->rules as $rule) {
                if (!$rule->matchesColumn($table, (string) $column, $row)) {
                    continue;
                }

                $row[$column] = match ($rule->getStrategy()) {
                    'fake' => $this->fakeDataGenerator->generate((string) $rule->getType(), $table, (string) $column, $value),
                    'hash' => hash('sha256', (string) $value),
                    'null' => null,
                    'exclude' => null,
                    default => $value,
                };
            }
        }

        return $row;
    }
}
