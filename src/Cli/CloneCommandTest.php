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
        $command = new CloneCommand(
            $this->createMock(TargetEnvironmentGuard::class),
            static fn(string $username, string $applicationPassword): RemoteExportClient => throw new \RuntimeException('should not build client'),
            $this->createMock(TargetSiteManager::class),
            $this->createMock(TablePrefixRemapper::class),
            $this->createMock(DatabaseImporter::class),
            new class() implements LoggerInterface {
                public function info(string $message, array $context = []): void
                {
                }
            },
        );

        $guard = new class() extends TargetEnvironmentGuard {
            public function assertSafe(): void
            {
                throw new \RuntimeException('blocked');
            }
        };

        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('environmentGuard');
        $property->setAccessible(true);
        $property->setValue($command, $guard);

        $this->expectException(\RuntimeException::class);
        $command->handle([], ['source-url' => 'https://source.example.test', 'target' => 'https://target.example.test', 'username' => 'admin', 'application-password' => 'app-password']);
    }

    public function testHandleRequiresApiKey(): void
    {
        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            fn(string $username, string $applicationPassword): RemoteExportClient => $this->createMock(RemoteExportClient::class),
            $this->createMock(TargetSiteManager::class),
            $this->createMock(TablePrefixRemapper::class),
            $this->createMock(DatabaseImporter::class),
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
            ]);

        $importer = $this->createMock(DatabaseImporter::class);
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
            )
            ->willReturnCallback(static function (string $path, string $targetUrl, int $blogId, string $tablePrefix, callable $stageRunner, string $sourceUrl, bool $keepRemoteMediaUrls, string $sourceMediaBaseUrl): void {
                self::assertSame('https://source.example.test', $sourceUrl);
                self::assertTrue($keepRemoteMediaUrls);
                self::assertSame('https://media.example.test/uploads', $sourceMediaBaseUrl);
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
            '[municipio-clone] Starting: Validating target environment',
            '[municipio-clone] Starting: Preparing target site',
            '[municipio-clone] Starting: Requesting remote export',
            '[municipio-clone] Starting: Downloading export artifact',
            '[municipio-clone] Starting: Remapping database table prefixes',
            '[municipio-clone] Starting: Importing SQL into the target database',
            '[municipio-clone] Starting: Discovering imported database tables',
            '[municipio-clone] Starting: Keeping remote media URLs',
            '[municipio-clone] Starting: Replacing source URLs in imported data',
            '[municipio-clone] Starting: Normalizing target home and site URLs',
            '[municipio-clone] Starting: Configuring remote media URLs',
        ], array_values(array_filter(
            \WP_CLI::$logMessages,
            static fn(string $message): bool => str_contains($message, 'Starting:'),
        )));
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

        $this->assertContains(
            '[municipio-clone] Failed during "Requesting remote export": Remote request failed.',
            \WP_CLI::$warningMessages,
        );
        $failureEntries = array_values(array_filter(
            $logger->entries,
            static fn(array $entry): bool => $entry[0] === 'municipio_clone_stage_failed',
        ));
        $this->assertCount(1, $failureEntries);
        $this->assertSame('remote_export', $failureEntries[0][1]['stage']);
    }
}
