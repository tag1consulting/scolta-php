<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * Where chat threads live. Adapters back it with what their platform has
 * (Drupal's expirable key value store, WordPress transients, a cache).
 *
 * Keys come from ChatOwner and are opaque to the store. Nothing here may
 * start a session.
 *
 * @since 2.0.0
 * @stability experimental
 */
interface ThreadStoreInterface
{
    /**
     * The stored data, or null when there is none or it expired.
     *
     * @return array<string, mixed>|null
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function load(string $key): ?array;

    /**
     * Store data under a key for $ttl seconds.
     *
     * @param array<string, mixed> $data
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function save(string $key, array $data, int $ttl): void;

    /**
     * Remove a key. Removing a key that does not exist is not an error.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function delete(string $key): void;
}
