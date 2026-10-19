<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Cli;

use MunicipioClone\Cli\CloneCommand;
use MunicipioClone\Contracts\LoggerInterface;
use MunicipioClone\Import\DatabaseImporter;
use MunicipioClone\Import\RemoteExportClient;
use MunicipioClone\Import\TablePrefixRemapper;
use MunicipioClone\Import\TargetEnvironmentGuard;
use MunicipioClone\Import\TargetSiteManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Cli\CloneCommand
 */
class CloneCommandTest extends TestCase
{
    public function testHandleRejectsUnsafeEnvironment(): void
    {
        $guard = new class() extends TargetEnvironmentGuard {
            public function assertSafe(): void
            {
                throw new \RuntimeException('blocked');
            }
        };

        $command = new CloneCommand(
            $guard,
            static fn(string $username, string $applicationPassword): RemoteExportClient => throw new \RuntimeException('should not build client'),
            $this->createStub(TargetSiteManager::class),
            $this->createStub(TablePrefixRemapper::class),
            $this->createStub(DatabaseImporter::class),
            new class() implements LoggerInterface {
                public function info(string $message, array $context = []): void
                {
                }
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $command->handle([], ['source-url' => 'https://source.example.test', 'target' => 'https://target.example.test', 'username' => 'admin', 'application-password' => 'app-password']);
    }

    public function testHandleRequiresApiKey(): void
    {
        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            fn(string $username, string $applicationPassword): RemoteExportClient => $this->createStub(RemoteExportClient::class),
            $this->createStub(TargetSiteManager::class),
            $this->createStub(TablePrefixRemapper::class),
            $this->createStub(DatabaseImporter::class),
            new class() implements LoggerInterface {
                public function info(string $message, array $context = []): void
                {
                }
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $command->handle([], ['source-url' => 'https://source.example.test', 'target' => 'https://target.example.test']);
    }

    public function testHandleDownloadsRemapsAndImportsArtifact(): void
    {
        \WP_CLI::$logMessages = [];
        \WP_CLI::$warningMessages = [];
        $previousEnvironment = getenv('WP_ENVIRONMENT_TYPE');
        putenv('WP_ENVIRONMENT_TYPE=local');
        $artifactPath = tempnam(sys_get_temp_dir(), 'municipio-clone-test-');
        file_put_contents($artifactPath, <<<'SQL'
CREATE TABLE `wp_7_posts` ();
INSERT INTO `wp_7_options` (`option_name`, `option_value`) VALUES ('wp_7_user_roles', 'wp_7_capabilities');
INSERT INTO `wp_7_posts` (`post_content`) VALUES ('wp_7_posts');
SQL);

        $client = $this->createStub(RemoteExportClient::class);
        $client->method('requestExport')->willReturn([
            'download_url' => 'https://source.example.test/download',
            'checksum' => hash('sha256', <<<'SQL'
CREATE TABLE `wp_7_posts` ();
INSERT INTO `wp_7_options` (`option_name`, `option_value`) VALUES ('wp_7_user_roles', 'wp_7_capabilities');
INSERT INTO `wp_7_posts` (`post_content`) VALUES ('wp_7_posts');
SQL),
            'source_table_prefix' => 'wp_7_',
            'source_blog_id' => 7,
            'source_media_base_url' => 'https://media.example.test/uploads',
            'cache_status' => 'generated',
        ]);
        $client->method('downloadArtifact')->willReturn($artifactPath);

        $siteManager = $this->createMock(TargetSiteManager::class);
        $siteManager->expects($this->once())
            ->method('prepare')
            ->with('https://target.example.test/site', [
                'source-url' => 'https://source.example.test',
                'target' => 'https://target.example.test/site',
                'username' => 'admin',
                'application-password' => 'app-password',
                'yes' => true,
                'keep-remote-media-urls' => true,
            ])
            ->willReturn([
                'url' => 'https://target.example.test/site',
                'blog_id' => 3,
                'table_prefix' => 'wp_3_',
                'siteurl' => 'https://target.example.test/site/wp',
            ]);

        $importer = $this->createMock(DatabaseImporter::class);
        $importer->expects($this->once())
            ->method('flushTargetRewriteRules')
            ->with('https://target.example.test/site');
        $importer->expects($this->once())
            ->method('import')
            ->with(
                $this->callback(static function (string $path): bool {
                    $content = (string) file_get_contents($path);

                    return str_contains($content, 'wp_3_posts')
                        && str_contains($content, 'wp_3_user_roles')
                        && str_contains($content, 'wp_3_capabilities')
                        && str_contains($content, "'wp_7_posts'");
                }),
                'https://target.example.test/site',
                3,
                'wp_3_',
                $this->isCallable(),
                'https://source.example.test',
                true,
                'https://media.example.test/uploads',
                'https://target.example.test/site/wp',
            )
            ->willReturnCallback(static function (string $path, string $targetUrl, int $blogId, string $tablePrefix, callable $stageRunner, string $sourceUrl, bool $keepRemoteMediaUrls, string $sourceMediaBaseUrl, string $targetSiteUrl): void {
                self::assertSame('https://source.example.test', $sourceUrl);
                self::assertTrue($keepRemoteMediaUrls);
                self::assertSame('https://media.example.test/uploads', $sourceMediaBaseUrl);
                self::assertSame('https://target.example.test/site/wp', $targetSiteUrl);
                $stageRunner('database_import', 'Importing SQL into the target database', static fn(): null => null);
                $stageRunner('table_discovery', 'Discovering imported database tables', static fn(): null => null);
                $stageRunner('remote_media_url_restoration', 'Keeping remote media URLs', static fn(): null => null);
                $stageRunner('url_replacement', 'Replacing source URLs in imported data', static fn(): null => null);
                $stageRunner('site_url_normalization', 'Normalizing target home and site URLs', static fn(): null => null);
                $stageRunner('remote_media_url_configuration', 'Configuring remote media URLs', static fn(): null => null);
            });

        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            static fn(string $username, string $applicationPassword): RemoteExportClient => $client,
            $siteManager,
            new TablePrefixRemapper(),
            $importer,
            new class() implements LoggerInterface {
                public function info(string $message, array $context = []): void
                {
                }
            },
        );

        try {
            $command->handle([], ['source-url' => 'https://source.example.test', 'target' => 'https://target.example.test/site', 'username' => 'admin', 'application-password' => 'app-password', 'yes' => true, 'keep-remote-media-urls' => true]);
        } finally {
            putenv($previousEnvironment === false ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $previousEnvironment);
        }

        $this->assertFileDoesNotExist($artifactPath);
        $this->assertSame([
            'Validating target environment...',
            'Preparing target site...',
            'Requesting remote export...',
            'Downloading export artifact...',
            'Remapping database table prefixes...',
            'Importing SQL into the target database...',
            'Discovering imported database tables...',
            'Keeping remote media URLs...',
            'Replacing source URLs in imported data...',
            'Normalizing target home and site URLs...',
            'Configuring remote media URLs...',
            'Flushing target rewrite rules...',
        ], \WP_CLI::$logMessages);
        $this->assertSame([], \WP_CLI::$warningMessages);
    }

    public function testFlushFailureWarnsWithoutFailingCompletedClone(): void
    {
        \WP_CLI::$warningMessages = [];
        \WP_CLI::$successMessages = [];
        $previousEnvironment = getenv('WP_ENVIRONMENT_TYPE');
        putenv('WP_ENVIRONMENT_TYPE=local');
        $artifactPath = tempnam(sys_get_temp_dir(), 'municipio-clone-test-');
        file_put_contents($artifactPath, 'CREATE TABLE `wp_posts` ();');
        $client = $this->createStub(RemoteExportClient::class);
        $client->method('requestExport')->willReturn(['source_table_prefix' => 'wp_']);
        $client->method('downloadArtifact')->willReturn($artifactPath);
        $siteManager = $this->createStub(TargetSiteManager::class);
        $siteManager->method('prepare')->willReturn([
            'url' => 'http://localhost:8080/dunkerskulturhus',
            'blog_id' => 3,
            'table_prefix' => 'wp_3_',
        ]);
        $importer = $this->createMock(DatabaseImporter::class);
        $importer->expects($this->once())->method('import');
        $importer->expects($this->once())
            ->method('flushTargetRewriteRules')
            ->with('http://localhost:8080/dunkerskulturhus')
            ->willThrowException(new \RuntimeException('Rewrite command exited with status 1.'));
        $logger = new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            static fn(string $username, string $applicationPassword): RemoteExportClient => $client,
            $siteManager,
            new TablePrefixRemapper(),
            $importer,
            $logger,
        );

        try {
            $command->handle([], [
                'source-url' => 'https://source.example.test',
                'target' => 'http://localhost:8080/dunkerskulturhus',
                'username' => 'admin',
                'application-password' => 'app-password',
            ]);
        } finally {
            putenv($previousEnvironment === false ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $previousEnvironment);
        }

        $this->assertFileDoesNotExist($artifactPath);
        $this->assertSame([
            'Cloned https://source.example.test to http://localhost:8080/dunkerskulturhus, but could not flush its rewrite rules: Rewrite command exited with status 1.',
        ], \WP_CLI::$warningMessages);
        $this->assertCount(1, \WP_CLI::$successMessages);
        $failedStages = array_values(array_filter(
            $logger->entries,
            static fn(array $entry): bool => $entry[0] === 'municipio_clone_stage_failed',
        ));
        $this->assertCount(1, $failedStages);
        $this->assertSame('rewrite_flush', $failedStages[0][1]['stage']);
        $this->assertSame('municipio_clone_import_completed', $logger->entries[array_key_last($logger->entries)][0]);
    }

    public function testFailedImportNeverFlushesTargetRewriteRules(): void
    {
        \WP_CLI::$warningMessages = [];
        $previousEnvironment = getenv('WP_ENVIRONMENT_TYPE');
        putenv('WP_ENVIRONMENT_TYPE=local');
        $artifactPath = tempnam(sys_get_temp_dir(), 'municipio-clone-test-');
        file_put_contents($artifactPath, 'CREATE TABLE `wp_posts` ();');
        $client = $this->createStub(RemoteExportClient::class);
        $client->method('requestExport')->willReturn(['source_table_prefix' => 'wp_']);
        $client->method('downloadArtifact')->willReturn($artifactPath);
        $siteManager = $this->createStub(TargetSiteManager::class);
        $siteManager->method('prepare')->willReturn([
            'url' => 'http://localhost:8080/dunkerskulturhus',
            'blog_id' => 3,
            'table_prefix' => 'wp_3_',
        ]);
        $importer = $this->createMock(DatabaseImporter::class);
        $importer->expects($this->once())
            ->method('import')
            ->willThrowException(new \RuntimeException('SQL import failed.'));
        $importer->expects($this->never())->method('flushTargetRewriteRules');
        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            static fn(string $username, string $applicationPassword): RemoteExportClient => $client,
            $siteManager,
            new TablePrefixRemapper(),
            $importer,
            new class() implements LoggerInterface {
                public function info(string $message, array $context = []): void
                {
                }
            },
        );

        try {
            $command->handle([], [
                'source-url' => 'https://source.example.test',
                'target' => 'http://localhost:8080/dunkerskulturhus',
                'username' => 'admin',
                'application-password' => 'app-password',
            ]);
            $this->fail('Expected the import to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SQL import failed.', $exception->getMessage());
        } finally {
            putenv($previousEnvironment === false ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $previousEnvironment);
        }

        $this->assertFileDoesNotExist($artifactPath);
        $this->assertSame([], \WP_CLI::$warningMessages);
    }

    public function testHandleReportsTheStageThatFailed(): void
    {
        \WP_CLI::$warningMessages = [];
        $previousEnvironment = getenv('WP_ENVIRONMENT_TYPE');
        putenv('WP_ENVIRONMENT_TYPE=local');
        $client = $this->createStub(RemoteExportClient::class);
        $client->method('requestExport')->willThrowException(new \RuntimeException('Remote request failed.'));
        $siteManager = $this->createStub(TargetSiteManager::class);
        $siteManager->method('prepare')->willReturn([
            'url' => 'https://target.example.test',
            'blog_id' => 1,
            'table_prefix' => 'wp_',
        ]);
        $logger = new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            static fn(string $username, string $applicationPassword): RemoteExportClient => $client,
            $siteManager,
            $this->createStub(TablePrefixRemapper::class),
            $this->createStub(DatabaseImporter::class),
            $logger,
        );

        try {
            $command->handle([], [
                'source-url' => 'https://source.example.test',
                'target' => 'https://target.example.test',
                'username' => 'admin',
                'application-password' => 'app-password',
            ]);
            $this->fail('Expected the remote export request to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Remote request failed.', $exception->getMessage());
        } finally {
            putenv($previousEnvironment === false ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $previousEnvironment);
        }

        $this->assertSame([], \WP_CLI::$warningMessages);
        $this->assertSame(
            'Failed during "Requesting remote export": Remote request failed.',
            $command->failureMessage(new \RuntimeException('Remote request failed.')),
        );
        $failureEntries = array_values(array_filter(
            $logger->entries,
            static fn(array $entry): bool => $entry[0] === 'municipio_clone_stage_failed',
        ));
        $this->assertCount(1, $failureEntries);
        $this->assertSame('remote_export', $failureEntries[0][1]['stage']);
    }

    public function testRunReportsOneStageErrorAndKeepsTraceInDebugLog(): void
    {
        \WP_CLI::$errorMessages = [];
        \WP_CLI::$warningMessages = [];
        $logger = new class() implements LoggerInterface {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = [$message, $context];
            }
        };
        $guard = new class() extends TargetEnvironmentGuard {
            public function assertSafe(): void
            {
                throw new \RuntimeException('Unsafe target.');
            }
        };
        $command = new CloneCommand(
            $guard,
            static fn(string $username, string $applicationPassword): RemoteExportClient => throw new \RuntimeException('Client must not be built.'),
            $this->createStub(TargetSiteManager::class),
            $this->createStub(TablePrefixRemapper::class),
            $this->createStub(DatabaseImporter::class),
            $logger,
        );

        try {
            $command->run([], [
                'source-url' => 'https://source.example.test',
                'target' => 'https://target.example.test',
                'username' => 'admin',
                'application-password' => 'app-password',
            ]);
            $this->fail('Expected WP-CLI to exit with an error.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('WP-CLI exited with an error.', $exception->getMessage());
        }

        $this->assertSame(['Failed during "Validating target environment": Unsafe target.'], \WP_CLI::$errorMessages);
        $this->assertSame([], \WP_CLI::$warningMessages);
        $this->assertSame('municipio_clone_command_failed', $logger->entries[2][0]);
        $this->assertStringContainsString('testRunReportsOneStageErrorAndKeepsTraceInDebugLog', $logger->entries[2][1]['error_trace']);
    }

    public function testRunReportsMissingArgumentsWithoutStageOrTrace(): void
    {
        \WP_CLI::$errorMessages = [];
        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            static fn(string $username, string $applicationPassword): RemoteExportClient => throw new \RuntimeException('Client must not be built.'),
            $this->createStub(TargetSiteManager::class),
            $this->createStub(TablePrefixRemapper::class),
            $this->createStub(DatabaseImporter::class),
            new class() implements LoggerInterface {
                public function info(string $message, array $context = []): void
                {
                }
            },
        );

        try {
            $command->run([], []);
            $this->fail('Expected WP-CLI to exit with an error.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('WP-CLI exited with an error.', $exception->getMessage());
        }

        $this->assertSame([
            'The --source-url, --target, --username, and --application-password arguments are required.',
        ], \WP_CLI::$errorMessages);
    }
}
