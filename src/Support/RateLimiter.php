<?php

declare(strict_types=1);

namespace MunicipioClone\Support;

use WpService\WpService;

/**
 * Handles per-source forced regeneration rate limits.
 */
class RateLimiter
{
    public function __construct(private WpService $wpService, private int $window)
    {
    }

    public function acquire(string $key): bool
    {
        $transientKey = 'municipio_clone_force_' . hash('sha256', $key);
        if ($this->wpService->getTransient($transientKey) !== false) {
            return false;
        }

        $this->wpService->setTransient($transientKey, time(), $this->window);

        return true;
    }
}
