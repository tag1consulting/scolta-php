<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Disk-backed entity timestamp manifest for incremental rebuild optimization.
 *
 * Maps entity key → ['ts' => int, 'items' => [...]] so that subsequent builds
 * can skip entity loading entirely for unchanged entities by comparing the stored
 * changed_time with the current value from a lightweight timestamp query.
 *
 * Each 'items' entry holds pre-computed data needed to reconstruct a chunk
 * entry without loading the entity body:
 *   ['hash' => string, 'id' => string, 'url' => string, 'date' => string,
 *    'siteName' => string, 'language' => string, 'filters' => array]
 *
 * Lifecycle:
 *  1. Constructed at build start — loads existing manifest from disk.
 *  2. Gatherer calls get() per entity to check if it is unchanged.
 *     - Unchanged: gatherer yields CachedContentReference(s), orchestrator
 *       calls markSeen() on cache hit.
 *     - Changed: gatherer loads entity, yields ContentItem, calls put().
 *  3. After build, pruneAndSave() removes entries for deleted entities and
 *     persists the updated manifest atomically.
 *
 * @since 0.3.12
 */
final class TimestampManifest
{
    private const FILENAME = 'timestamp-manifest.php';

    /** @var array<string, array{ts: int, items: list<array<string, mixed>>}> */
    private array $data = [];

    /** @var array<string, true> */
    private array $seen = [];

    private bool $dirty = false;

    public function __construct(
        private readonly string $stateDir,
        private readonly StorageDriverInterface $storage,
    ) {
        $this->loadFromDisk();
    }

    /**
     * Get the stored entry for an entity key.
     *
     * @return array{ts: int, items: list<array<string, mixed>>}|null
     * @since 1.0.0
     * @stability stable
     */
    public function get(string $entityKey): ?array
    {
        return $this->data[$entityKey] ?? null;
    }

    /**
     * Store or update an entry. Also marks the entity as seen so it survives pruning.
     *
     * @param list<array<string, mixed>> $items One entry per translation/variant:
     *   [['hash' => string, 'id' => string, 'url' => string, 'date' => string,
     *     'siteName' => string, 'language' => string, 'filters' => array], ...]
     *
     * @since 1.0.0
     * @stability stable
     */
    public function put(string $entityKey, int $ts, array $items): void
    {
        $this->data[$entityKey] = ['ts' => $ts, 'items' => $items];
        $this->seen[$entityKey] = true;
        $this->dirty            = true;
    }

    /**
     * Mark an entity key as seen so it survives pruning.
     *
     * Called by the orchestrator when it successfully processes a
     * CachedContentReference (i.e. token cache hit).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function markSeen(string $entityKey): void
    {
        $this->seen[$entityKey] = true;
    }

    /**
     * Remove entries for entities no longer present, then save atomically.
     *
     * Should be called in the same places as PageWordCache::pruneAndSave().
     *
     * @since 1.0.0
     * @stability stable
     */
    public function pruneAndSave(): void
    {
        foreach (array_keys($this->data) as $key) {
            if (!isset($this->seen[$key])) {
                unset($this->data[$key]);
                $this->dirty = true;
            }
        }

        if ($this->dirty) {
            $this->saveToDisk();
            $this->dirty = false;
        }
    }

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function count(): int
    {
        return count($this->data);
    }

    private function loadFromDisk(): void
    {
        $path = $this->stateDir . '/' . self::FILENAME;
        if (!$this->storage->exists($path)) {
            return;
        }

        try {
            $raw = $this->storage->get($path);
        } catch (\Throwable) {
            return;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($data)) {
            $this->data = $data;
        }
    }

    private function saveToDisk(): void
    {
        $this->storage->makeDirectory($this->stateDir);
        $file = $this->stateDir . '/' . self::FILENAME;
        $tmp  = $file . '.tmp.' . getmypid();
        $this->storage->put($tmp, serialize($this->data));
        rename($tmp, $file);
    }
}
