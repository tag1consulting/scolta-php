<?php

declare(strict_types=1);

namespace Tag1\Scolta\Cache;

/**
 * No-op cache driver for when caching is disabled (cacheTtl <= 0).
 *
 * @since 0.2.0
 * @stability experimental
 */
class NullCacheDriver implements CacheDriverInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        // Intentionally empty.
    }
}
