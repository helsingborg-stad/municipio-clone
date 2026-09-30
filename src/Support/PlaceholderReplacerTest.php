<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Support;

use Exception;
use MunicipioClone\Support\PlaceholderReplacer;
use PHPUnit\Framework\TestCase;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @covers \MunicipioClone\Support\PlaceholderReplacer
 */
class PlaceholderReplacerTest extends TestCase
{
    public function testReplaceUpdatesSerializedPayloadWithoutBreakingSerialization(): void
    {
        $wpService = new MutableWpService();
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
        $replacer = new PlaceholderReplacer(new MutableWpService());

        $result = $replacer->replace('https://source.example.test/path', 'https://source.example.test', 'https://target.example.test');

        $this->assertSame('https://target.example.test/path', $result);
    }

    public function testReplaceTraversesNestedArraysAndObjects(): void
    {
        $replacer = new PlaceholderReplacer(new MutableWpService());
        $payload = [
            'direct' => 'https://source.example.test/first',
            'object' => (object) ['url' => 'https://source.example.test/second'],
        ];

        $result = $replacer->replace($payload, 'https://source.example.test', 'https://target.example.test');

        $this->assertSame('https://target.example.test/first', $result['direct']);
        $this->assertSame('https://target.example.test/second', $result['object']->url);
    }

    #[TestDox('does not throw if nothing to unserialize')]
    public function testDoesNotThrowIfNothingToUnserialize(): void
    {
        $replacer = new PlaceholderReplacer(new MutableWpService());
        $payload = 'b:0;';

        try {
            $replacer->replace($payload, 'https://source.example.test', 'https://target.example.test');
        } catch(Exception $e) {
            static::fail('did not handle exception');
            return;
        }

        static::assertTrue(true, 'does not throw');
    }
}
