<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\TablePrefixRemapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\TablePrefixRemapper
 */
class TablePrefixRemapperTest extends TestCase
{
    public function testRemapsTablesAndSiteScopedKeysWithoutChangingContentValues(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_remap_test_');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary SQL artifact for the test.');
        }
        file_put_contents($path, implode("\n", [
            'CREATE TABLE `wp_7_posts` ();',
            "INSERT INTO `wp_7_options` VALUES ('wp_7_user_roles', 'value');",
            "INSERT INTO `wp_7_usermeta` VALUES ('wp_7_capabilities', 'value');",
            "INSERT INTO `wp_7_posts` VALUES ('wp_7_posts');",
        ]));

        try {
            (new TablePrefixRemapper())->remapFile($path, 'wp_7_', 'wp_3_');
            $content = (string) file_get_contents($path);

            $this->assertStringContainsString('`wp_3_posts`', $content);
            $this->assertStringContainsString("'wp_3_user_roles'", $content);
            $this->assertStringContainsString("'wp_3_capabilities'", $content);
            $this->assertStringContainsString("'wp_7_posts'", $content);
        } finally {
            @unlink($path);
        }
    }

    public function testLeavesArtifactUnchangedWhenPrefixesMatch(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_remap_test_');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary SQL artifact for the test.');
        }
        file_put_contents($path, 'CREATE TABLE `wp_posts` ();');

        try {
            (new TablePrefixRemapper())->remapFile($path, 'wp_', 'wp_');

            $this->assertSame('CREATE TABLE `wp_posts` ();', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testRemapsSiteScopedKeyBeyondFirstChunk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'municipio_clone_remap_test_');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary SQL artifact for the test.');
        }
        $padding = str_repeat('x', (1024 * 1024) + 100);
        file_put_contents($path, sprintf("INSERT INTO `wp_7_options` VALUES ('payload', '%s', 'wp_7_user_roles');", $padding));

        try {
            (new TablePrefixRemapper())->remapFile($path, 'wp_7_', 'wp_3_');
            $content = (string) file_get_contents($path);

            $this->assertStringContainsString('`wp_3_options`', $content);
            $this->assertStringContainsString("'wp_3_user_roles'", $content);
        } finally {
            @unlink($path);
        }
    }
}