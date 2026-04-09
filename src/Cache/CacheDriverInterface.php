<?php

declare(strict_types=1);

namespace Tag1\Scolta\Cache;

/**
 * Thin cache abstraction for AI endpoint response caching.
 *
 * Each platform adapter implements this with its native cache backend:
 *   - Drupal: CacheBackendInterface
 *   - Laravel: Cache facade
 *   - WordPress: get_transient/set_transient
 *
 * @since 0.2.0
 * @stability experimental
 */
interface CacheDriverInterface
{
    /**
     * Retrieve a cached value by key.
     *
     * @param string $key The cache key.
     * @return mixed The cached value, or null if not found.
     */
    public function get(string $key): mixed;

    /**
     * Store a value in the cache.
     *
     * @param string $key        The cache key.
     * @param mixed  $value      The value to cache.
     * @param int    $ttlSeconds Time-to-live in seconds.
     */
    public function set(string $key, mixed $value, int $ttlSeconds): void;
}
