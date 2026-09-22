<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\TargetSiteManager;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
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

        $targetSite = (new TargetSiteManager($wpService))->prepare(
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
        $targetSiteManager = new TargetSiteManager($wpService);

        $targetSite = $targetSiteManager->prepare('http://localhost:8080/hbgtest', ['yes' => true]);

        $this->assertSame([
            ['Update and overwrite existing target subsite http://localhost:8080/hbgtest?', ['yes' => true]],
        ], \WP_CLI::$confirmations);
        $this->assertSame(3, $targetSite['blog_id']);
        $this->assertSame('wp_3_', $targetSite['table_prefix']);
        $this->assertSame('localhost:8080', $wpService->sites[0]->domain);
    }
}