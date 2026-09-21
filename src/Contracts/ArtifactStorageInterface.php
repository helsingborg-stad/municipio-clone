<?php

declare(strict_types=1);

namespace MunicipioClone\Contracts;

use MunicipioClone\Export\ArtifactManifest;

/**
 * Storage contract for encrypted export artifacts.
 */
interface ArtifactStorageInterface
{
    public function getFresh(string $cacheKey): ?ArtifactManifest;

    public function store(string $cacheKey, string $content, array $metadata): ArtifactManifest;

    public function retrieveContent(string $artifactId): string;
}
