<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\TestDoubles;

use WpService\Implementations\FakeWpService;

/**
 * Package-backed fake with mutable state for focused unit tests.
 */
class MutableWpService extends FakeWpService
{
    public array $actions = [];
    public array $routes = [];
    public array $filters = [];
    public array $transients = [];
    public array $roles = [];
    public array $users = [];
    public array $userMeta = [];
    public array $sites = [];
    public array $options = [];
    public bool $multisite = false;
    public int $currentBlogId = 1;
    public string $homeUrl = 'https://source.example.test';
    public array $capabilities = [];
    public array $superAdmins = [];

    public function addAction(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        $this->actions[] = [$hookName, $callback, $priority, $acceptedArgs];

        return true;
    }

    public function removeAction(string $hookName, callable|string|array $callback, int $priority = 10): bool
    {
        return true;
    }

    public function addFilter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        $this->filters[$hookName][] = $callback;

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

    public function userCan(int|\WP_User $user, string $capability, mixed ...$args): bool
    {
        $userId = $user instanceof \WP_User ? (int) ($user->ID ?? 0) : $user;

        return $this->capabilities[$userId][$capability] ?? false;
    }

    public function isSuperAdmin(int|false $userId = false): bool
    {
        return $userId !== false && in_array($userId, $this->superAdmins, true);
    }

    public function getRole(string $role): \WP_Role|null
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

    public function wpInsertSite(array $data): int|\WP_Error
    {
        $id = count($this->sites) + 1;
        $site = (object) array_merge(['blog_id' => $id], $data);
        $this->sites[] = $site;

        return $id;
    }

    public function wpUpdateSite(int $siteId, array $data): int|\WP_Error
    {
        foreach ($this->sites as $site) {
            if ((int) ($site->blog_id ?? 0) !== $siteId) {
                continue;
            }

            foreach ($data as $key => $value) {
                $site->{$key} = $value;
            }

            return $siteId;
        }

        return new \WP_Error('site_not_found', 'Site not found.');
    }

    public function getSites(string|array $args = []): array|int
    {
        if (!is_array($args) || $args === []) {
            return $this->sites;
        }

        return array_values(array_filter($this->sites, static function (object $site) use ($args): bool {
            foreach ($args as $key => $value) {
                if ($key === 'number') {
                    continue;
                }

                if (($site->{$key} ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function restUrl(string $path = '', string|null $scheme = 'rest'): string
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

    public function switchToBlog(int $newBlogId, bool $deprecated = null): true
    {
        $this->currentBlogId = $newBlogId;

        return true;
    }

    public function restoreCurrentBlog(): bool
    {
        $this->currentBlogId = 1;

        return true;
    }

    public function updateOption(string $option, mixed $value, bool|null $autoload = null): bool
    {
        $this->options[$this->currentBlogId][$option] = $value;

        return true;
    }

    public function getOption(string $option, mixed $defaultValue = false): mixed
    {
        return $this->options[$this->currentBlogId][$option] ?? $defaultValue;
    }

    public function getUsers(array $args = []): array
    {
        if (!isset($args['meta_key'], $args['meta_value'])) {
            return $this->users;
        }

        return array_values(array_filter($this->users, function (object $user) use ($args): bool {
            $values = $this->userMeta[(int) ($user->ID ?? 0)][$args['meta_key']] ?? [];
            $values = is_array($values) ? $values : [$values];

            return in_array($args['meta_value'], $values, true);
        }));
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
