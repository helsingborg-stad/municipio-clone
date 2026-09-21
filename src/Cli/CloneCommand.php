<?php

declare(strict_types=1);

namespace MunicipioClone\Cli;

use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Import\DatabaseImporter;
use MunicipioClone\Import\RemoteExportClient;
use MunicipioClone\Import\TablePrefixRemapper;
use MunicipioClone\Import\TargetEnvironmentGuard;
use MunicipioClone\Import\TargetSiteManager;

/**
 * Implements the wp municipio clone command.
 */
class CloneCommand
{
    public function __construct(
        private TargetEnvironmentGuard $environmentGuard,
        private RemoteExportClient $remoteExportClient,
        private TargetSiteManager $targetSiteManager,
        private TablePrefixRemapper $tablePrefixRemapper,
        private DatabaseImporter $databaseImporter,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(array $arguments, array $associativeArguments): void
    {
        $sourceUrl = (string) ($associativeArguments['url'] ?? '');
        $targetUrl = (string) ($associativeArguments['target'] ?? '');
        if ($sourceUrl === '' || $targetUrl === '') {
            throw new \InvalidArgumentException('Both --url and --target are required.');
        }

        $force = array_key_exists('force', $associativeArguments) && (string) $associativeArguments['force'] !== 'false';

        $this->environmentGuard->assertSafe();
        $targetSite = $this->targetSiteManager->prepare($targetUrl);
        $manifest = $this->remoteExportClient->requestExport($sourceUrl, $force);
        $artifactPath = $this->remoteExportClient->downloadArtifact($manifest);
        $artifactPath = $this->tablePrefixRemapper->remapFile(
            $artifactPath,
            (string) ($manifest['source_table_prefix'] ?? 'wp_'),
            (string) $targetSite['table_prefix'],
        );
        $this->databaseImporter->import($artifactPath, $targetUrl);
        $this->logger->info('municipio_clone_import_completed', [
            'source' => $sourceUrl,
            'target' => $targetUrl,
            'cache_status' => $manifest['cache_status'] ?? 'unknown',
        ]);

        if (class_exists('WP_CLI')) {
            \WP_CLI::success('Municipio clone import completed.');
        }
    }
}
