<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Simple in-memory cache driver for testing.
 */
class InMemoryCacheDriver implements CacheDriverInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store[$key] = $value;
    }
}
