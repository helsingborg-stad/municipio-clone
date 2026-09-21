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
        $environment = defined('WP_ENVIRONMENT_TYPE') ? (string) WP_ENVIRONMENT_TYPE : (string) (getenv('WP_ENVIRONMENT_TYPE') ?: '');
        if (!in_array($environment, ['local', 'development', 'staging'], true)) {
            throw new \RuntimeException('Municipio Clone only allows imports into local, development, or staging environments.');
        }
    }
}
