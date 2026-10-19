<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\TestDoubles;

use MunicipioClone\Database\StoredSiteOptions;

/**
 * Models database rows independently from filtered WordPress option reads.
 */
class FakeStoredSiteOptions extends StoredSiteOptions
{
    public bool $failWrites = false;

    /**
     * Keeps fake rows alongside the mutable service's options state.
     */
    public function __construct(private MutableWpService $wpService)
    {
    }

    /**
     * Returns an unfiltered fake row.
     */
    public function get(int $blogId, string $name): ?string
    {
        return $this->wpService->options[$blogId][$name] ?? null;
    }

    /**
     * Stores a fake row unless database writes are disabled.
     */
    public function set(int $blogId, string $name, string $value): void
    {
        if ($this->failWrites) {
            throw new \RuntimeException(sprintf('Could not store target option "%s". Check database permissions.', $name));
        }
        $this->wpService->options[$blogId][$name] = $value;
    }
}
