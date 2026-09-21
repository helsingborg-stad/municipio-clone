<?php

declare(strict_types=1);

namespace MunicipioClone\Support;

use MunicipioClone\Contracts\LoggerInterface;

/**
 * Logger that emits structured JSON to PHP error logs.
 */
class PhpErrorLogger implements LoggerInterface
{
    public function info(string $message, array $context = []): void
    {
        error_log((string) json_encode([
            'channel' => 'municipio-clone',
            'message' => $message,
            'context' => $context,
        ], JSON_THROW_ON_ERROR));
    }
}
