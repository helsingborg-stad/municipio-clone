<?php

declare(strict_types=1);

namespace MunicipioClone\Contracts;

/**
 * Database read abstraction for export generation.
 */
interface DatabaseConnectionInterface
{
    public function getSiteContext(): array;

    public function getSiteTables(int $blogId): array;

    public function getCreateTableStatement(string $table): string;

    /**
     * @return iterable<array<string, mixed>> Rows must be streamed rather than buffered in full to avoid excessive memory use on large tables.
     */
    public function getRows(string $table): iterable;
}
