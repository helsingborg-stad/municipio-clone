<?php

declare(strict_types=1);

namespace MunicipioClone;

use MunicipioClone\Capability\CapabilityRegistrar;
use MunicipioClone\Cli\BatchCloneCommand;
use MunicipioClone\Cli\BatchConfigurationLoader;
use MunicipioClone\Cli\CloneCommand;
use MunicipioClone\Database\WordPressDatabaseConnection;
use MunicipioClone\Export\DataWasher;
use MunicipioClone\Export\ExportService;
use MunicipioClone\Export\FakeDataGenerator;
use MunicipioClone\Export\SqlExportGenerator;
use MunicipioClone\Export\WasherRegistry;
use MunicipioClone\Import\DatabaseImporter;
use MunicipioClone\Import\RemoteMediaUrlRewriter;
use MunicipioClone\Import\RemoteExportClient;
use MunicipioClone\Import\TablePrefixRemapper;
use MunicipioClone\Import\TargetEnvironmentGuard;
use MunicipioClone\Import\TargetLockManager;
use MunicipioClone\Import\TargetSiteManager;
use MunicipioClone\Import\WpCliRunner;
use MunicipioClone\Rest\ExportController;
use MunicipioClone\Storage\EncryptedArtifactStorage;
use MunicipioClone\Support\Config;
use MunicipioClone\Support\PhpErrorLogger;
use MunicipioClone\Support\PlaceholderReplacer;
use MunicipioClone\Support\RateLimiter;
use WpService\WpService;

/**
 * Main plugin bootstrapper.
 */
class MunicipioClone
{
    public function __construct(private WpService $wpService, private CapabilityRegistrar $capabilityRegistrar)
    {
    }

    public function boot(): void
    {
        $this->capabilityRegistrar->register();
        $remoteMediaUrlRewriter = new RemoteMediaUrlRewriter($this->wpService);
        $this->wpService->addFilter('wp_get_attachment_url', [$remoteMediaUrlRewriter, 'filterAttachmentUrl'], 10, 2);
        $logger = new PhpErrorLogger();
        $artifactStorage = new EncryptedArtifactStorage(
            Config::storageDirectory(),
            Config::encryptionKey(),
            Config::cacheTtl(),
        );
        $dataWasher = new DataWasher((new WasherRegistry($this->wpService))->all(), new FakeDataGenerator());
        $exportService = new ExportService(
            $artifactStorage,
            new SqlExportGenerator(
                new WordPressDatabaseConnection($this->wpService),
                $dataWasher,
                new PlaceholderReplacer($this->wpService),
                Config::placeholderUrl(),
            ),
            new RateLimiter($this->wpService, Config::forceWindow()),
            $logger,
        );
        $controller = new ExportController(
            $this->wpService,
            $exportService,
            $artifactStorage,
        );
        $this->wpService->addAction('rest_api_init', [$controller, 'registerRoutes']);

        if (class_exists('WP_CLI')) {
            $command = new CloneCommand(
                new TargetEnvironmentGuard(),
                static fn(string $username, string $applicationPassword): RemoteExportClient => new RemoteExportClient($username, $applicationPassword),
                new TargetSiteManager($this->wpService),
                new TablePrefixRemapper(),
                new DatabaseImporter(
                    new WpCliRunner(),
                    Config::placeholderUrl(),
                    $this->wpService,
                    new WordPressDatabaseConnection($this->wpService),
                    $remoteMediaUrlRewriter,
                ),
                $logger,
            );
            \WP_CLI::add_command('municipio clone', [$command, 'handle']);
            $batchCommand = new BatchCloneCommand(
                $command,
                new BatchConfigurationLoader(),
                new TargetLockManager($this->wpService, Config::targetLockTtl()),
                $logger,
            );
            \WP_CLI::add_command('municipio clone-batch', [$batchCommand, 'handle']);
        }
    }
}
