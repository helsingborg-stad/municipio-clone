<?php

declare(strict_types=1);

namespace WpService;

/**
 * Minimal WordPress service abstraction used by Municipio Clone.
 */
interface WpService
{
    public function addAction(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool;

    public function registerRestRoute(string $routeNamespace, string $route, array $args = [], bool $override = false): bool;

    public function currentUserCan(string $capability, mixed ...$args): bool;

    public function userCan(int|object $user, string $capability, mixed ...$args): bool;

    public function isSuperAdmin(int|false $userId = false): bool;

    public function getRole(string $role): object|null;

    public function getCurrentBlogId(): int;

    public function getHomeUrl(int|null $blogId = null, string $path = '', string|null $scheme = null): string;

    public function isMultisite(): bool;

    public function wpInsertSite(array $data): int|object;

    public function getSites(string|array $args = []): array|int;

    public function restUrl(string $path = '', string $scheme = 'rest'): string;

    public function applyFilters(string $hookName, mixed $value, mixed ...$args): mixed;

    public function maybeSerialize(string|array|object $data): mixed;

    public function maybeUnserialize(string $data): mixed;

    public function deleteTransient(string $transient): bool;

    public function getTransient(string $transient): mixed;

    public function setTransient(string $transient, mixed $value, int $expiration = 0): bool;

    public function switchToBlog(int $newBlogId, bool $deprecated = null): bool;

    public function restoreCurrentBlog(): bool;

    public function getUsers(array $args = []): array;

    public function getUserMeta(int $userId, string $key = '', bool $single = false): mixed;

    public function updateUserMeta(int $userId, string $metaKey, mixed $metaValue, mixed $prevValue = ''): int|bool;
}
