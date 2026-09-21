<?php

declare(strict_types=1);

namespace WpService\Implementations;

use WpService\WpService;

/**
 * Native WordPress-backed service implementation.
 */
class NativeWpService implements WpService
{
    public function addAction(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        if (!function_exists('add_action')) {
            return false;
        }

        add_action($hookName, $callback, $priority, $acceptedArgs);

        return true;
    }

    public function registerRestRoute(string $routeNamespace, string $route, array $args = [], bool $override = false): bool
    {
        if (!function_exists('register_rest_route')) {
            return false;
        }

        return register_rest_route($routeNamespace, $route, $args, $override);
    }

    public function currentUserCan(string $capability, mixed ...$args): bool
    {
        return function_exists('current_user_can') ? current_user_can($capability, ...$args) : false;
    }

    public function userCan(int|object $user, string $capability, mixed ...$args): bool
    {
        return function_exists('user_can') ? user_can($user, $capability, ...$args) : false;
    }

    public function isSuperAdmin(int|false $userId = false): bool
    {
        return function_exists('is_super_admin') ? is_super_admin($userId) : false;
    }

    public function getRole(string $role): object|null
    {
        return function_exists('get_role') ? get_role($role) : null;
    }

    public function getCurrentBlogId(): int
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
    }

    public function getHomeUrl(int|null $blogId = null, string $path = '', string|null $scheme = null): string
    {
        return function_exists('get_home_url') ? (string) get_home_url($blogId, $path, $scheme) : $path;
    }

    public function isMultisite(): bool
    {
        return function_exists('is_multisite') ? is_multisite() : false;
    }

    public function wpInsertSite(array $data): int|object
    {
        return function_exists('wp_insert_site') ? wp_insert_site($data) : 0;
    }

    public function getSites(string|array $args = []): array|int
    {
        return function_exists('get_sites') ? get_sites($args) : [];
    }

    public function restUrl(string $path = '', string $scheme = 'rest'): string
    {
        return function_exists('rest_url') ? (string) rest_url($path, $scheme) : $path;
    }

    public function applyFilters(string $hookName, mixed $value, mixed ...$args): mixed
    {
        return function_exists('apply_filters') ? apply_filters($hookName, $value, ...$args) : $value;
    }

    public function maybeSerialize(string|array|object $data): mixed
    {
        if (function_exists('maybe_serialize')) {
            return maybe_serialize($data);
        }

        return is_array($data) || is_object($data) ? serialize($data) : $data;
    }

    public function maybeUnserialize(string $data): mixed
    {
        if (function_exists('maybe_unserialize')) {
            return maybe_unserialize($data);
        }

        $result = @unserialize($data, ['allowed_classes' => false]);

        return $result === false && $data !== 'b:0;' ? $data : $result;
    }

    public function deleteTransient(string $transient): bool
    {
        return function_exists('delete_transient') ? delete_transient($transient) : true;
    }

    public function getTransient(string $transient): mixed
    {
        return function_exists('get_transient') ? get_transient($transient) : false;
    }

    public function setTransient(string $transient, mixed $value, int $expiration = 0): bool
    {
        return function_exists('set_transient') ? set_transient($transient, $value, $expiration) : true;
    }

    public function switchToBlog(int $newBlogId, bool $deprecated = null): bool
    {
        return function_exists('switch_to_blog') ? switch_to_blog($newBlogId) : true;
    }

    public function restoreCurrentBlog(): bool
    {
        return function_exists('restore_current_blog') ? restore_current_blog() : true;
    }

    public function getUsers(array $args = []): array
    {
        return function_exists('get_users') ? get_users($args) : [];
    }

    public function getUserMeta(int $userId, string $key = '', bool $single = false): mixed
    {
        return function_exists('get_user_meta') ? get_user_meta($userId, $key, $single) : null;
    }

    public function updateUserMeta(int $userId, string $metaKey, mixed $metaValue, mixed $prevValue = ''): int|bool
    {
        return function_exists('update_user_meta') ? update_user_meta($userId, $metaKey, $metaValue, $prevValue) : true;
    }
}
