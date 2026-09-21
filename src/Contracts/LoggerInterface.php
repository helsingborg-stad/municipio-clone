<?php

declare(strict_types=1);

namespace MunicipioClone\Contracts;

/**
 * Structured logger contract.
 */
interface LoggerInterface
{
    public function info(string $message, array $context = []): void;
}
