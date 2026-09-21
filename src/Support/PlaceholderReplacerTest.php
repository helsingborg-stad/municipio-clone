<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Support;

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

    public function testReplaceUpdatesPlainStrings(): void
    {
        $replacer = new PlaceholderReplacer(new FakeWpService());

        $result = $replacer->replace('https://source.example.test/path', 'https://source.example.test', 'https://target.example.test');

        $this->assertSame('https://target.example.test/path', $result);
    }

    public function testReplaceTraversesNestedArraysAndObjects(): void
    {
        $replacer = new PlaceholderReplacer(new FakeWpService());
        $payload = [
            'direct' => 'https://source.example.test/first',
            'object' => (object) ['url' => 'https://source.example.test/second'],
        ];

        $result = $replacer->replace($payload, 'https://source.example.test', 'https://target.example.test');

        $this->assertSame('https://target.example.test/first', $result['direct']);
        $this->assertSame('https://target.example.test/second', $result['object']->url);
    }
}
