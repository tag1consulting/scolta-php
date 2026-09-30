<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Cache driver that tracks how many times get() and set() are called.
 */
class TrackingCacheDriver implements CacheDriverInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;

    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        $this->getCalls++;

        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->setCalls++;
        $this->store[$key] = $value;
    }
}
