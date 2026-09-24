<?php

declare(strict_types=1);

namespace MunicipioClone\Export;

/**
 * Describes a cached export artifact.
 */
class ArtifactManifest
{
    public function __construct(
        public readonly string $artifactId,
        public readonly string $checksum,
        public readonly int $generatedAt,
        public readonly int $expiresAt,
        public readonly string $sourceUrl,
        public readonly int $sourceBlogId,
        public readonly string $sourceTablePrefix,
        public readonly string $cacheKey,
        public readonly string $sourceMediaBaseUrl = '',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['artifact_id'],
            (string) $data['checksum'],
            (int) $data['generated_at'],
            (int) $data['expires_at'],
            (string) $data['source_url'],
            (int) $data['source_blog_id'],
            (string) $data['source_table_prefix'],
            (string) $data['cache_key'],
            (string) ($data['source_media_base_url'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'artifact_id' => $this->artifactId,
            'checksum' => $this->checksum,
            'generated_at' => $this->generatedAt,
            'expires_at' => $this->expiresAt,
            'source_url' => $this->sourceUrl,
            'source_blog_id' => $this->sourceBlogId,
            'source_table_prefix' => $this->sourceTablePrefix,
            'cache_key' => $this->cacheKey,
            'source_media_base_url' => $this->sourceMediaBaseUrl,
        ];
    }
}
