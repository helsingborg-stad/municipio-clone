<?php

declare(strict_types=1);

namespace WpService\Implementations;

use WpService\WpService;

/**
 * Fake service used for unit testing.
 */
class FakeWpService implements WpService
{
    public array $actions = [];
    public array $routes = [];
    public array $filters = [];
    public array $transients = [];
    public array $roles = [];
    public array $users = [];
    public array $userMeta = [];
    public array $sites = [];
    public array $blogPrefixes = [1 => 'wp_'];
    public bool $multisite = false;
    public int $currentBlogId = 1;
    public string $homeUrl = 'https://source.example.test';
    public array $capabilities = [];
    public array $superAdmins = [];

    public function addAction(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $this->actions[] = [$hookName, $callback, $priority, $acceptedArgs];

        return true;
    }

    public function registerRestRoute(string $routeNamespace, string $route, array $args = [], bool $override = false): bool
    {
        $this->routes[] = [$routeNamespace, $route, $args, $override];

        return true;
    }

    public function currentUserCan(string $capability, mixed ...$args): bool
    {
        return $this->capabilities['current'][$capability] ?? false;
    }

    public function userCan(int|object $user, string $capability, mixed ...$args): bool
    {
        $userId = is_object($user) ? (int) ($user->ID ?? 0) : $user;

        return $this->capabilities[$userId][$capability] ?? false;
    }

    public function isSuperAdmin(int|false $userId = false): bool
    {
        return $userId !== false && in_array($userId, $this->superAdmins, true);
    }

    public function getRole(string $role): object|null
    {
        return $this->roles[$role] ?? null;
    }

    public function getCurrentBlogId(): int
    {
        return $this->currentBlogId;
    }

    public function getHomeUrl(int|null $blogId = null, string $path = '', string|null $scheme = null): string
    {
        return rtrim($this->homeUrl, '/') . $path;
    }

    public function isMultisite(): bool
    {
        return $this->multisite;
    }

    public function wpInsertSite(array $data): int|object
    {
        $id = count($this->sites) + 1;
        $site = (object) array_merge(['blog_id' => $id], $data);
        $this->sites[] = $site;

        return $id;
    }

    public function getSites(string|array $args = []): array|int
    {
        return $this->sites;
    }

    public function restUrl(string $path = '', string $scheme = 'rest'): string
    {
        return 'https://source.example.test/wp-json/' . ltrim($path, '/');
    }

    public function applyFilters(string $hookName, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$hookName])) {
            return $value;
        }

        foreach ($this->filters[$hookName] as $filter) {
            $value = $filter($value, ...$args);
        }

        return $value;
    }

    public function maybeSerialize(string|array|object $data): mixed
    {
        return is_array($data) || is_object($data) ? serialize($data) : $data;
    }

    public function maybeUnserialize(string $data): mixed
    {
        $result = @unserialize($data, ['allowed_classes' => false]);

        return $result === false && $data !== 'b:0;' ? $data : $result;
    }

    public function deleteTransient(string $transient): bool
    {
        unset($this->transients[$transient]);

        return true;
    }

    public function getTransient(string $transient): mixed
    {
        return $this->transients[$transient] ?? false;
    }

    public function setTransient(string $transient, mixed $value, int $expiration = 0): bool
    {
        $this->transients[$transient] = $value;

        return true;
    }

    public function switchToBlog(int $newBlogId, bool $deprecated = null): bool
    {
        $this->currentBlogId = $newBlogId;

        return true;
    }

    public function restoreCurrentBlog(): bool
    {
        $this->currentBlogId = 1;

        return true;
    }

    public function getUsers(array $args = []): array
    {
        return $this->users;
    }

    public function getUserMeta(int $userId, string $key = '', bool $single = false): mixed
    {
        return $this->userMeta[$userId][$key] ?? ($single ? null : []);
    }

    public function updateUserMeta(int $userId, string $metaKey, mixed $metaValue, mixed $prevValue = ''): int|bool
    {
        $this->userMeta[$userId][$metaKey] = $metaValue;

        return true;
    }
}
