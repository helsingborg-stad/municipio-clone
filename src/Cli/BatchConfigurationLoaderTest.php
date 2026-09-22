<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Cli;

use MunicipioClone\Cli\BatchConfigurationLoader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Cli\BatchConfigurationLoader
 */
class BatchConfigurationLoaderTest extends TestCase
{
    public function testLoadsMappingsAndResolvesCredentialsFromEnvironment(): void
    {
        $path = $this->createConfigurationFile([
            'mappings' => [[
                'source_url' => 'https://production.example.test/',
                'target' => 'https://staging.example.test/',
                'username_env' => 'MUNICIPIO_CLONE_TEST_USERNAME',
                'application_password_env' => 'MUNICIPIO_CLONE_TEST_PASSWORD',
                'force' => true,
                'keep_remote_media_urls' => true,
            ]],
        ]);
        putenv('MUNICIPIO_CLONE_TEST_USERNAME=clone-user');
        putenv('MUNICIPIO_CLONE_TEST_PASSWORD=clone-password');

        try {
            $mappings = (new BatchConfigurationLoader())->load($path);
            $arguments = $mappings[0]->toAssociativeArguments();
        } finally {
            putenv('MUNICIPIO_CLONE_TEST_USERNAME');
            putenv('MUNICIPIO_CLONE_TEST_PASSWORD');
            @unlink($path);
        }

        $this->assertCount(1, $mappings);
        $this->assertSame([
            'source-url' => 'https://production.example.test',
            'target' => 'https://staging.example.test',
            'username' => 'clone-user',
            'application-password' => 'clone-password',
            'force' => true,
            'keep-remote-media-urls' => true,
            'yes' => true,
        ], $arguments);
    }

    public function testRejectsLiteralCredentialFields(): void
    {
        $path = $this->createConfigurationFile([
            'mappings' => [[
                'source_url' => 'https://production.example.test',
                'target' => 'https://staging.example.test',
                'username' => 'clone-user',
                'application_password' => 'clone-password',
            ]],
        ]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('unsupported keys: username, application_password');
            (new BatchConfigurationLoader())->load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsDuplicateTargets(): void
    {
        $path = $this->createConfigurationFile([
            'mappings' => [
                [
                    'source_url' => 'https://production-one.example.test',
                    'target' => 'https://staging.example.test',
                    'username_env' => 'ONE_USER',
                    'application_password_env' => 'ONE_PASSWORD',
                ],
                [
                    'source_url' => 'https://production-two.example.test',
                    'target' => 'https://staging.example.test',
                    'username_env' => 'TWO_USER',
                    'application_password_env' => 'TWO_PASSWORD',
                ],
            ],
        ]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('duplicate target');
            (new BatchConfigurationLoader())->load($path);
        } finally {
            @unlink($path);
        }
    }

    private function createConfigurationFile(array $configuration): string
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio-clone-batch-');
        if ($path === false) {
            $this->fail('Failed to create temporary configuration file.');
        }
        file_put_contents($path, json_encode($configuration, JSON_THROW_ON_ERROR));

        return $path;
    }
}