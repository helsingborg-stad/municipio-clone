<?php

declare(strict_types=1);

namespace MunicipioClone\Capability;

use WpService\WpService;

/**
 * Registers the custom export capability.
 */
class CapabilityRegistrar
{
    public const CAPABILITY = 'municipio_clone_export';

    public function __construct(private WpService $wpService)
    {
    }

    public function register(): void
    {
        $administratorRole = $this->wpService->getRole('administrator');
        if ($administratorRole !== null && method_exists($administratorRole, 'add_cap')) {
            $administratorRole->add_cap(self::CAPABILITY);
        }
    }
}
