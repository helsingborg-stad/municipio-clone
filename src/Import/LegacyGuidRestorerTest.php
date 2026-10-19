<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\LegacyGuidRestorer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\LegacyGuidRestorer
 */
class LegacyGuidRestorerTest extends TestCase
{
    public function testRestoresOverlongLegacyGuidWithoutChangingOtherFieldsOrLines(): void
    {
        $sourceUrl = 'https://visithelsingborg.com';
        $placeholderUrl = 'https://municipio-clone-placeholder.invalid';
        $originalGuid = $sourceUrl . '/' . str_repeat('g', 255 - strlen($sourceUrl) - 1);
        $legacyGuid = str_replace($sourceUrl, $placeholderUrl, $originalGuid);
        $content = $placeholderUrl . "/news, with an escaped quote \\' and a " . str_repeat('x', 1024 * 1024);
        $sql = "-- exported data\n\n"
            . "INSERT INTO `hbg_3_posts` (`ID`, `post_content`, `guid`, `post_type`) VALUES (1, '{$content}', '{$legacyGuid}', 'post');\n\n"
            . "INSERT INTO `hbg_3_posts` (`ID`, `post_content`, `guid`, `post_type`) VALUES (2, '{$placeholderUrl}/event', 'urn:event:2', 'event');\n"
            . "INSERT INTO `hbg_3_options` (`option_name`, `option_value`) VALUES ('home', '{$placeholderUrl}');\n";
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_guid_test_');
        file_put_contents($path, $sql);
        $updates = [];

        try {
            (new LegacyGuidRestorer())->restoreFile(
                $path,
                $placeholderUrl,
                $sourceUrl,
                static function (int $processed, int $total) use (&$updates): void {
                    $updates[] = [$processed, $total];
                },
            );
            $restored = file_get_contents($path);

            $this->assertSame(255, strlen($originalGuid));
            $this->assertSame(270, strlen($legacyGuid));
            $this->assertSame(str_replace("'{$legacyGuid}'", "'{$originalGuid}'", $sql), $restored);
            $this->assertSame([0, strlen($sql)], $updates[0]);
            $this->assertSame([strlen($sql), strlen($sql)], end($updates));
        } finally {
            unlink($path);
        }
    }

    public function testDoesNotAlterGuidWithoutPlaceholderOrUnrelatedValues(): void
    {
        $sql = "INSERT INTO `wp_posts` (`guid`, `post_content`) VALUES ('https://source.test/post', 'https://municipio-clone-placeholder.invalid');\n"
            . "INSERT INTO `wp_posts` (`guid`, `post_content`) VALUES (NULL, 'https://municipio-clone-placeholder.invalid');\n";
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_guid_test_');
        file_put_contents($path, $sql);

        try {
            (new LegacyGuidRestorer())->restoreFile(
                $path,
                'https://municipio-clone-placeholder.invalid',
                'https://source.test',
            );

            $this->assertSame($sql, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testMalformedGuidFailsWithoutReplacingArtifact(): void
    {
        $directory = sys_get_temp_dir() . '/municipio_clone_guid_' . bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory . '/artifact.sql';
        $sql = "INSERT INTO `wp_posts` (`guid`) VALUES ('https://municipio-clone-placeholder.invalid'";
        file_put_contents($path, $sql);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not parse a SQL value while restoring a legacy GUID.');

            (new LegacyGuidRestorer())->restoreFile(
                $path,
                'https://municipio-clone-placeholder.invalid',
                'https://source.test',
            );
        } finally {
            $this->assertSame($sql, file_get_contents($path));
            $this->assertSame([$path], glob($directory . '/*'));
            unlink($path);
            rmdir($directory);
        }
    }
}
