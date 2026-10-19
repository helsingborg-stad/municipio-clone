<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\TargetSiteManager;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
use MunicipioClone\Tests\TestDoubles\FakeStoredSiteOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\TargetSiteManager
 */
class TargetSiteManagerTest extends TestCase
{
    public function testPrepareReusesSiteWithMatchingPortAndPath(): void
    {
        \WP_CLI::$confirmations = [];
        $wpService = new MutableWpService();
        $wpService->multisite = true;
        $wpService->sites[] = (object) [
            'blog_id' => 3,
            'domain' => 'localhost:8080',
            'path' => '/hbgtest/',
        ];

        $targetSite = (new TargetSiteManager($wpService, new FakeStoredSiteOptions($wpService)))->prepare(
            'http://localhost:8080/hbgtest',
            ['yes' => true],
        );

        $this->assertSame(3, $targetSite['blog_id']);
        $this->assertSame('http://localhost:8080/hbgtest', $targetSite['url']);
        $this->assertSame([
            ['Overwrite existing target subsite http://localhost:8080/hbgtest?', ['yes' => true]],
        ], \WP_CLI::$confirmations);
        $this->assertSame('localhost:8080', $wpService->sites[0]->domain);
    }

    /**
     * Ensures that WP-CLI can honor the non-interactive overwrite flag.
     */
    public function testPreparePassesYesFlagToExistingSiteConfirmation(): void
    {
        \WP_CLI::$confirmations = [];
        $wpService = new MutableWpService();
        $wpService->multisite = true;
        $wpService->sites[] = (object) [
            'blog_id' => 3,
            'domain' => 'localhost',
            'path' => '/hbgtest/',
        ];
        $targetSiteManager = new TargetSiteManager($wpService, new FakeStoredSiteOptions($wpService));

        $targetSite = $targetSiteManager->prepare('http://localhost:8080/hbgtest', ['yes' => true]);

        $this->assertSame([
            ['Update and overwrite existing target subsite http://localhost:8080/hbgtest?', ['yes' => true]],
        ], \WP_CLI::$confirmations);
        $this->assertSame(3, $targetSite['blog_id']);
        $this->assertSame('wp_3_', $targetSite['table_prefix']);
        $this->assertSame('localhost:8080', $wpService->sites[0]->domain);
    }

    public function testPreservesInstallationPathAcrossChangedDomainSchemePortAndPath(): void
    {
        $wpService = new MutableWpService();
        $wpService->multisite = true;
        $wpService->sites[] = (object) ['blog_id' => 3, 'domain' => 'new.example.test', 'path' => '/department/'];
        $wpService->options[3] = [
            'home' => 'http://old.example.test:8080/old',
            'siteurl' => 'http://old.example.test:8080/old/wp',
        ];

        $result = (new TargetSiteManager($wpService, new FakeStoredSiteOptions($wpService)))
            ->prepare('https://new.example.test/department', ['yes' => true]);

        $this->assertSame('https://new.example.test/department/wp', $result['siteurl']);
        $this->assertSame('http://old.example.test:8080/old/wp', $wpService->options[3]['siteurl']);
    }

    public function testNewSiteDefaultsToPublicUrlAndOverrideWorksOnRerun(): void
    {
        $wpService = new MutableWpService();
        $wpService->multisite = true;
        $manager = new TargetSiteManager($wpService, new FakeStoredSiteOptions($wpService));

        $created = $manager->prepare('https://department.example.test');
        $rerun = $manager->prepare('https://department.example.test', ['yes' => true, 'target-siteurl' => 'https://department.example.test/wp/']);

        $this->assertSame('https://department.example.test', $created['siteurl']);
        $this->assertSame('https://department.example.test/wp', $rerun['siteurl']);
    }

    public function testRejectsUnrelatedStoredInstallationUrlWithoutOverride(): void
    {
        $wpService = new MutableWpService();
        $wpService->multisite = true;
        $wpService->sites[] = (object) ['blog_id' => 3, 'domain' => 'department.example.test', 'path' => '/'];
        $wpService->options[3] = ['home' => 'https://old.example.test', 'siteurl' => 'https://other.example.test/wp'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pass --target-siteurl');
        (new TargetSiteManager($wpService, new FakeStoredSiteOptions($wpService)))
            ->prepare('https://department.example.test', ['yes' => true]);
    }

    public function testSingleSitePreservesItsDistinctInstallationUrl(): void
    {
        $wpService = new MutableWpService();
        $wpService->options[1] = [
            'home' => 'http://old.example.test',
            'siteurl' => 'http://old.example.test/wp',
        ];

        $result = (new TargetSiteManager($wpService, new FakeStoredSiteOptions($wpService)))
            ->prepare('https://new.example.test:8443');

        $this->assertSame('https://new.example.test:8443/wp', $result['siteurl']);
        $this->assertSame(1, $result['blog_id']);
    }

    public function testRejectsInvalidExplicitInstallationUrl(): void
    {
        $wpService = new MutableWpService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target siteurl must be an absolute HTTP(S) URL');
        (new TargetSiteManager($wpService, new FakeStoredSiteOptions($wpService)))
            ->prepare('https://department.example.test', ['target-siteurl' => 'javascript:alert(1)']);
    }
}