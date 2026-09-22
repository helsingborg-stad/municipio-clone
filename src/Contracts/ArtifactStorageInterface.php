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

    /**
     * @param string $contentPath Path to a file containing the plaintext export content; the content is
     *                            streamed from disk rather than passed in memory to support large exports.
     */
    public function store(string $cacheKey, string $contentPath, array $metadata): ArtifactManifest;

    public function retrieveContent(string $artifactId): string;
}
