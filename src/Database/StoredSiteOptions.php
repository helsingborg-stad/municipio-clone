<?php

declare(strict_types=1);

namespace MunicipioClone\Database;

/**
 * Reads and writes unfiltered site options in the target site's options table.
 */
class StoredSiteOptions
{
    public function get(int $blogId, string $name): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            sprintf('SELECT option_value FROM `%s` WHERE option_name = %%s', $this->table($blogId)),
            $name,
        ));

        return $value === null ? null : (string) $value;
    }

    public function set(int $blogId, string $name, string $value): void
    {
        global $wpdb;

        $table = $this->table($blogId);
        $result = $this->get($blogId, $name) === null
            ? $wpdb->insert($table, ['option_name' => $name, 'option_value' => $value, 'autoload' => 'yes'])
            : $wpdb->update($table, ['option_value' => $value], ['option_name' => $name]);

        if ($result === false || $this->get($blogId, $name) !== $value) {
            throw new \RuntimeException(sprintf('Could not store target option "%s" in %s. Check database permissions.', $name, $table));
        }
    }

    private function table(int $blogId): string
    {
        global $wpdb;

        $table = (string) $wpdb->get_blog_prefix($blogId) . 'options';
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \RuntimeException('Invalid target options table name.');
        }

        return $table;
    }
}
