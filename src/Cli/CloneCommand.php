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
    /**
     * @param callable(string):RemoteExportClient $remoteExportClientFactory
     */
    public function __construct(
        private TargetEnvironmentGuard $environmentGuard,
        private $remoteExportClientFactory,
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
        $apiKey = (string) ($associativeArguments['api-key'] ?? '');
        if ($sourceUrl === '' || $targetUrl === '' || $apiKey === '') {
            throw new \InvalidArgumentException('The --url, --target, and --api-key arguments are required.');
        }

        $force = array_key_exists('force', $associativeArguments) && (string) $associativeArguments['force'] !== 'false';

        $this->environmentGuard->assertSafe();
        $targetSite = $this->targetSiteManager->prepare($targetUrl);
        $remoteExportClient = ($this->remoteExportClientFactory)($apiKey);
        $manifest = $remoteExportClient->requestExport($sourceUrl, $force);
        $artifactPath = $remoteExportClient->downloadArtifact($manifest);
        $artifactPath = $this->tablePrefixRemapper->remapFile(
            $artifactPath,
            (string) ($manifest['source_table_prefix'] ?? 'wp_'),
            (string) $targetSite['table_prefix'],
        );
        $this->databaseImporter->import($artifactPath, (string) $targetSite['url']);
        $this->logger->info('municipio_clone_import_completed', [
            'source' => $sourceUrl,
            'target' => $targetSite['url'],
            'cache_status' => $manifest['cache_status'] ?? 'unknown',
        ]);

        if (class_exists('WP_CLI')) {
            \WP_CLI::success('Municipio clone import completed.');
        }
    }
}
