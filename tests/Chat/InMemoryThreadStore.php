<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Chat;

use Tag1\Scolta\Chat\ThreadStoreInterface;

/**
 * Thread storage in an array, with a hook that runs before a load returns,
 * so a test can land a second writer between a read and a write.
 */
class InMemoryThreadStore implements ThreadStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $data = [];

    /** @var array<string, int> */
    public array $ttls = [];

    /** @var (\Closure(string): void)|null */
    public ?\Closure $beforeLoad = null;

    public function load(string $key): ?array
    {
        if ($this->beforeLoad !== null) {
            ($this->beforeLoad)($key);
        }

        return $this->data[$key] ?? null;
    }

    public function save(string $key, array $data, int $ttl): void
    {
        $this->data[$key] = $data;
        $this->ttls[$key] = $ttl;
    }

    public function delete(string $key): void
    {
        unset($this->data[$key], $this->ttls[$key]);
    }
}
