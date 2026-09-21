<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Unit\Support;

use MunicipioClone\Support\PlaceholderReplacer;
use PHPUnit\Framework\TestCase;
use WpService\Implementations\FakeWpService;

/**
 * @covers \MunicipioClone\Support\PlaceholderReplacer
 */
class PlaceholderReplacerTest extends TestCase
{
    public function testReplaceUpdatesSerializedPayloadWithoutBreakingSerialization(): void
    {
        $wpService = new FakeWpService();
        $replacer = new PlaceholderReplacer($wpService);
        $serialized = serialize(['url' => 'https://source.example.test/path']);

        $result = $replacer->replace($serialized, 'https://source.example.test', 'https://target.example.test');

        $this->assertIsString($result);
        $this->assertSame(
            ['url' => 'https://target.example.test/path'],
            unserialize($result, ['allowed_classes' => false]),
        );
    }
}
