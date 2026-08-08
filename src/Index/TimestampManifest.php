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
 *    'siteName' => string, 'language' => string, 'filters' => array,
 *    'sortable' => array, 'metadata' => array]
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
 * Known-empty content hashes are tracked alongside the entries, in their own
 * file. An entity whose body is too short to index is still recorded by the
 * gatherer's put(), because the gatherer never sees the exporter's minimum
 * length gate — so on the next build it returns as a CachedContentReference
 * whose token cache lookup can only miss, and a miss used to mean "prune the
 * entry and re-gather". For an entity that never had tokens that re-gather
 * repeats forever. ContentExporter::filterItems() records the hash it drops
 * here, and the orchestrator consults isKnownEmpty() before treating a miss as
 * an eviction, so the entry survives and the entity is gathered once, not once
 * per build.
 *
 * The record is keyed by content hash, so editing an entity invalidates it
 * without any explicit clearing: new body, new hash, no match. Lowering the
 * exporter's minimum content length does NOT invalidate it — hashes recorded
 * under the old threshold stay known-empty until a --force build rewrites the
 * manifest.
 *
 * @since 0.3.12
 */
final class TimestampManifest
{
    private const FILENAME = 'timestamp-manifest.php';

    private const EMPTY_FILENAME = 'timestamp-manifest-empty.php';

    /** @var array<string, array{ts: int, items: list<array<string, mixed>>}> */
    private array $data = [];

    /** @var array<string, true> */
    private array $seen = [];

    /**
     * Content hashes known to produce no indexable page.
     *
     * @var array<string, true>
     */
    private array $empty = [];

    /** @var array<string, true> */
    private array $emptySeen = [];

    private bool $dirty = false;

    private bool $emptyDirty = false;

    public function __construct(
        private readonly string $stateDir,
        private readonly StorageDriverInterface $storage,
    ) {
        $this->loadFromDisk();
        $this->loadEmptyFromDisk();
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
     *     'siteName' => string, 'language' => string, 'filters' => array,
     *     'sortable' => array, 'metadata' => array], ...]
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
     * Record that a content hash produces no indexable page.
     *
     * Called by ContentExporter::filterItems() as it drops an item, which is
     * the one place the decision is made against a body that is actually in
     * memory. Recording it anywhere else risks a second, drifting definition
     * of "empty" — and a hash wrongly recorded here is a page that silently
     * stops being indexed.
     *
     * @since 1.2.1
     * @stability experimental
     */
    public function markEmpty(string $contentHash): void
    {
        $this->emptySeen[$contentHash] = true;

        if (isset($this->empty[$contentHash])) {
            return;
        }

        $this->empty[$contentHash] = true;
        $this->emptyDirty          = true;
    }

    /**
     * Whether a content hash is known to produce no indexable page.
     *
     * Also marks the hash as still in use, so it survives pruneAndSave().
     *
     * @since 1.2.1
     * @stability experimental
     */
    public function isKnownEmpty(string $contentHash): bool
    {
        if (!isset($this->empty[$contentHash])) {
            return false;
        }

        $this->emptySeen[$contentHash] = true;

        return true;
    }

    /**
     * How many content hashes are recorded as producing no indexable page.
     *
     * @since 1.2.1
     * @stability experimental
     */
    public function knownEmptyCount(): int
    {
        return count($this->empty);
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

        foreach (array_keys($this->empty) as $hash) {
            if (!isset($this->emptySeen[$hash])) {
                unset($this->empty[$hash]);
                $this->emptyDirty = true;
            }
        }

        // A fresh build's prepare() calls BuildState::cleanup(), which unlinks
        // every file in the state directory — this manifest included. Both
        // copies live in memory by then, so the build runs correctly and the
        // loss is invisible until the NEXT build finds no manifest and
        // re-gathers the whole corpus. Saving only when something changed is
        // therefore not enough: a build in which every entity is unchanged has
        // nothing to mark dirty, and is exactly the build that can least afford
        // to lose the manifest. Write whenever the file is missing.
        if ($this->dirty || ($this->data !== [] && !$this->storage->exists($this->path(self::FILENAME)))) {
            $this->saveToDisk();
            $this->dirty = false;
        }

        if ($this->emptyDirty || ($this->empty !== [] && !$this->storage->exists($this->path(self::EMPTY_FILENAME)))) {
            $this->saveEmptyToDisk();
            $this->emptyDirty = false;
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

    private function path(string $filename): string
    {
        return $this->stateDir . '/' . $filename;
    }

    private function loadFromDisk(): void
    {
        $path = $this->path(self::FILENAME);
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

    /**
     * Load the known-empty hashes.
     *
     * Kept in its own file rather than folded into the entry manifest so that a
     * manifest written by an older version stays readable as-is: the set simply
     * starts out absent, and the first build that drops an item writes it.
     */
    private function loadEmptyFromDisk(): void
    {
        $path = $this->path(self::EMPTY_FILENAME);
        if (!$this->storage->exists($path)) {
            return;
        }

        try {
            $raw = $this->storage->get($path);
        } catch (\Throwable) {
            return;
        }

        $hashes = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($hashes)) {
            return;
        }

        foreach ($hashes as $hash) {
            if (is_string($hash)) {
                $this->empty[$hash] = true;
            }
        }
    }

    private function saveToDisk(): void
    {
        $this->storage->makeDirectory($this->stateDir);
        $file = $this->path(self::FILENAME);
        $tmp  = $file . '.tmp.' . getmypid();
        $this->storage->put($tmp, serialize($this->data));
        rename($tmp, $file);
    }

    private function saveEmptyToDisk(): void
    {
        $this->storage->makeDirectory($this->stateDir);
        $file = $this->path(self::EMPTY_FILENAME);
        $tmp  = $file . '.tmp.' . getmypid();
        $this->storage->put($tmp, serialize(array_keys($this->empty)));
        rename($tmp, $file);
    }
}
