<?php

declare(strict_types=1);

namespace MunicipioClone\Export;

/**
 * Produces deterministic but non-sensitive replacement values.
 */
class FakeDataGenerator
{
    public function generate(string $type, string $table, string $column, mixed $value): mixed
    {
        $hash = substr(hash('sha256', $table . '|' . $column . '|' . (string) $value), 0, 12);

        return match ($type) {
            'email' => sprintf('sanitized+%s@example.test', $hash),
            'name' => sprintf('Sanitized %s', strtoupper(substr($hash, 0, 6))),
            'phone' => '070000' . substr(preg_replace('/\D+/', '', $hash), 0, 4),
            'address' => sprintf('Sanitized Address %s', strtoupper(substr($hash, 0, 4))),
            'ip' => sprintf('198.51.100.%d', hexdec(substr($hash, 0, 2)) % 250),
            default => sprintf('sanitized-%s', $hash),
        };
    }
}
