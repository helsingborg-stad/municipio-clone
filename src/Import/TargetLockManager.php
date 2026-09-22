<?php

declare(strict_types=1);

namespace MunicipioClone\Import;

use WpService\WpService;

/**
 * Prevents concurrent clone imports from writing to the same target site.
 */
class TargetLockManager
{
    /**
    * @param WpService $wpService WordPress option operations.
     * @param int $ttlSeconds Maximum time a target remains locked.
     */
    public function __construct(private WpService $wpService, private int $ttlSeconds)
    {
    }

    /**
     * Attempts to lock a target URL for one clone operation.
     *
     * @param string $targetUrl The target site URL.
     * @return bool True when the lock was acquired.
     */
    public function acquire(string $targetUrl): bool
    {
        $lockKey = $this->getLockKey($targetUrl);
        $expiresAt = time() + $this->ttlSeconds;
        if ($this->wpService->addOption($lockKey, $expiresAt, '', false)) {
            return true;
        }

        $existingExpiry = (int) $this->wpService->getOption($lockKey, 0);
        if ($existingExpiry >= time()) {
            return false;
        }

        $this->wpService->deleteOption($lockKey);

        return $this->wpService->addOption($lockKey, $expiresAt, '', false);
    }

    /**
     * Releases a previously acquired target URL lock.
     *
     * @param string $targetUrl The target site URL.
     */
    public function release(string $targetUrl): void
    {
        $this->wpService->deleteOption($this->getLockKey($targetUrl));
    }

    private function getLockKey(string $targetUrl): string
    {
        return 'municipio_clone_target_lock_' . hash('sha256', strtolower(rtrim($targetUrl, '/')));
    }
}