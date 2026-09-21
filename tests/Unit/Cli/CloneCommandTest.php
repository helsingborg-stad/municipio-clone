<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Unit\Cli;

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
            $this->createMock(RemoteExportClient::class),
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
        $command->handle([], ['url' => 'https://source.example.test', 'target' => 'https://target.example.test']);
    }

    public function testHandleDownloadsRemapsAndImportsArtifact(): void
    {
        $previousEnvironment = getenv('WP_ENVIRONMENT_TYPE');
        putenv('WP_ENVIRONMENT_TYPE=local');
        $artifactPath = tempnam(sys_get_temp_dir(), 'municipio-clone-test-');
        file_put_contents($artifactPath, <<<'SQL'
CREATE TABLE `wp_7_posts` ();
INSERT INTO `wp_7_options` (`option_name`, `option_value`) VALUES ('wp_7_user_roles', 'wp_7_capabilities');
SQL);

        $client = $this->createMock(RemoteExportClient::class);
        $client->method('requestExport')->willReturn([
            'download_url' => 'https://source.example.test/download',
            'checksum' => hash('sha256', <<<'SQL'
CREATE TABLE `wp_7_posts` ();
INSERT INTO `wp_7_options` (`option_name`, `option_value`) VALUES ('wp_7_user_roles', 'wp_7_capabilities');
SQL),
            'source_table_prefix' => 'wp_7_',
            'cache_status' => 'generated',
        ]);
        $client->method('downloadArtifact')->willReturn($artifactPath);

        $siteManager = $this->createMock(TargetSiteManager::class);
        $siteManager->method('prepare')->willReturn([
            'url' => 'https://target.example.test/site',
            'blog_id' => 3,
            'table_prefix' => 'wp_3_',
        ]);

        $importer = $this->createMock(DatabaseImporter::class);
        $importer->expects($this->once())
            ->method('import')
            ->with($artifactPath, 'https://target.example.test/site');

        $command = new CloneCommand(
            new TargetEnvironmentGuard(),
            $client,
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
            $command->handle([], ['url' => 'https://source.example.test', 'target' => 'https://target.example.test/site']);
        } finally {
            putenv($previousEnvironment === false ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $previousEnvironment);
        }

        $this->assertStringContainsString('wp_3_posts', (string) file_get_contents($artifactPath));
        $this->assertStringContainsString('wp_3_user_roles', (string) file_get_contents($artifactPath));
        $this->assertStringContainsString('wp_3_capabilities', (string) file_get_contents($artifactPath));
    }
}
