<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Unit\Export;

use MunicipioClone\Export\DataWasher;
use MunicipioClone\Export\FakeDataGenerator;
use MunicipioClone\Export\WasherRegistry;
use PHPUnit\Framework\TestCase;
use WpService\Implementations\FakeWpService;

/**
 * @covers \MunicipioClone\Export\DataWasher
 * @covers \MunicipioClone\Export\WasherRegistry
 */
class DataWasherTest extends TestCase
{
    public function testUsersTableIsExcluded(): void
    {
        $rules = (new WasherRegistry(new FakeWpService()))->all();
        $washer = new DataWasher($rules, new FakeDataGenerator());

        $this->assertTrue($washer->shouldExcludeTable('wp_users'));
    }

    public function testCommentEmailIsFaked(): void
    {
        $rules = (new WasherRegistry(new FakeWpService()))->all();
        $washer = new DataWasher($rules, new FakeDataGenerator());

        $row = $washer->washRow('wp_comments', ['comment_author_email' => 'person@example.com']);

        $this->assertStringEndsWith('@example.test', (string) $row['comment_author_email']);
        $this->assertNotSame('person@example.com', $row['comment_author_email']);
    }

    public function testBillingPostmetaValueIsFakedConditionally(): void
    {
        $rules = (new WasherRegistry(new FakeWpService()))->all();
        $washer = new DataWasher($rules, new FakeDataGenerator());

        $row = $washer->washRow('wp_postmeta', ['meta_key' => '_billing_email', 'meta_value' => 'person@example.com']);

        $this->assertStringEndsWith('@example.test', (string) $row['meta_value']);
    }
}
