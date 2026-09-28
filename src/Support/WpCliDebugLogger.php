<?php

declare(strict_types=1);

namespace MunicipioClone\Support;

use MunicipioClone\Contracts\LoggerInterface;

/**
 * Logger that emits structured JSON to the WP-CLI debug channel, visible with --debug=municipio-clone.
 */
class WpCliDebugLogger implements LoggerInterface
{
    public const DEBUG_GROUP = 'municipio-clone';

    /**
     * @param string $message Event name.
     * @param array<string, mixed> $context Structured event context.
     */
    public function info(string $message, array $context = []): void
    {
        \WP_CLI::debug((string) json_encode([
            'message' => $message,
            'context' => $context,
        ], JSON_THROW_ON_ERROR), self::DEBUG_GROUP);
    }
}
