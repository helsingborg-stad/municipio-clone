<?php

declare(strict_types=1);

namespace MunicipioClone\Support;

/**
 * Configuration helpers for Municipio Clone.
 */
class Config
{
    public static function cacheTtl(): int
    {
        return defined('MUNICIPIO_CLONE_CACHE_TTL') ? (int) MUNICIPIO_CLONE_CACHE_TTL : 3600;
    }

    public static function forceWindow(): int
    {
        return defined('MUNICIPIO_CLONE_FORCE_WINDOW') ? (int) MUNICIPIO_CLONE_FORCE_WINDOW : 600;
    }

    public static function targetLockTtl(): int
    {
        return defined('MUNICIPIO_CLONE_TARGET_LOCK_TTL') ? max(1, (int) MUNICIPIO_CLONE_TARGET_LOCK_TTL) : 3600;
    }

    /**
     * Seconds allowed for a single export request to run before PHP's execution
     * time limit kills it. 0 means unlimited.
     */
    public static function exportTimeLimit(): int
    {
        return defined('MUNICIPIO_CLONE_EXPORT_TIME_LIMIT') ? (int) MUNICIPIO_CLONE_EXPORT_TIME_LIMIT : 300;
    }

    public static function placeholderUrl(): string
    {
        return defined('MUNICIPIO_CLONE_PLACEHOLDER_URL') ? (string) MUNICIPIO_CLONE_PLACEHOLDER_URL : 'https://municipio-clone-placeholder.invalid';
    }

    public static function storageDirectory(): string
    {
        if (defined('MUNICIPIO_CLONE_STORAGE_PATH')) {
            return (string) MUNICIPIO_CLONE_STORAGE_PATH;
        }

        if (defined('ABSPATH')) {
            return dirname((string) ABSPATH) . '/municipio-clone-artifacts';
        }

        return sys_get_temp_dir() . '/municipio-clone-artifacts';
    }

    public static function encryptionKey(): string
    {
        if (defined('MUNICIPIO_CLONE_ENCRYPTION_KEY')) {
            return (string) MUNICIPIO_CLONE_ENCRYPTION_KEY;
        }

        if (defined('AUTH_KEY')) {
            return hash('sha256', (string) AUTH_KEY, true);
        }

        throw new \RuntimeException('Municipio Clone requires MUNICIPIO_CLONE_ENCRYPTION_KEY or AUTH_KEY to encrypt cached artifacts.');
    }
}
