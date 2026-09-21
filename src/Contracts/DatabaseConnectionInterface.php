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

    public function getRows(string $table): array;
}
