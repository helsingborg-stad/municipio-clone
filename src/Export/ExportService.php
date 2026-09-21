<?php

declare(strict_types=1);

namespace MunicipioClone\Export;

use MunicipioClone\Contracts\ArtifactStorageInterface;
use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Support\RateLimiter;

/**
 * Orchestrates cache lookups and sanitized export generation.
 */
class ExportService
{
    public function __construct(
        private ArtifactStorageInterface $artifactStorage,
        private SqlExportGenerator $exportGenerator,
        private RateLimiter $rateLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function create(string $sourceIdentifier, bool $force, int $requesterId): array
    {
        $forcedRegeneration = $force && $this->rateLimiter->acquire($sourceIdentifier);
        $cached = $this->artifactStorage->getFresh($sourceIdentifier);
        if ($cached !== null && !$forcedRegeneration) {
            $this->logger->info('municipio_clone_export_cache_hit', [
                'source' => $sourceIdentifier,
                'requester_id' => $requesterId,
                'forced' => $force,
            ]);

            return ['manifest' => $cached, 'cache_status' => 'hit'];
        }

        $generated = $this->exportGenerator->generate();
        $manifest = $this->artifactStorage->store($sourceIdentifier, (string) $generated['content'], $generated);
        $this->logger->info('municipio_clone_export_generated', [
            'source' => $sourceIdentifier,
            'requester_id' => $requesterId,
            'forced' => $forcedRegeneration,
            'checksum' => $manifest->checksum,
        ]);

        return ['manifest' => $manifest, 'cache_status' => $forcedRegeneration ? 'forced' : 'generated'];
    }
}
