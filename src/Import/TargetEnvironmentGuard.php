<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

/**
 * Prevents imports into production-like environments.
 */
class TargetEnvironmentGuard
{
    public function assertSafe(): void
    {
        $environment = function_exists('wp_get_environment_type')
            ? (string) wp_get_environment_type()
            : (string) (getenv('WP_ENVIRONMENT_TYPE') ?: '');

        if (!in_array($environment, ['local', 'development', 'staging'], true)) {
            throw new \RuntimeException('Municipio Clone only allows imports into local, development, or staging environments.');
        }
    }
}
