<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\TargetLockManager;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\TargetLockManager
 */
class TargetLockManagerTest extends TestCase
{
    public function testPreventsConcurrentLocksForTheSameTarget(): void
    {
        $manager = new TargetLockManager(new MutableWpService(), 300);

        $this->assertTrue($manager->acquire('https://staging.example.test/site/'));
        $this->assertFalse($manager->acquire('https://staging.example.test/site'));
    }

    public function testReleasesLockAfterCloneOperation(): void
    {
        $manager = new TargetLockManager(new MutableWpService(), 300);

        $this->assertTrue($manager->acquire('https://staging.example.test/site'));
        $manager->release('https://staging.example.test/site');

        $this->assertTrue($manager->acquire('https://staging.example.test/site'));
    }
}