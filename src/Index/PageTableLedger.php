<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Durable assignment of page ordinals to content-item ids.
 *
 * A page's ordinal is load-bearing in four places at once: it names the
 * fragment file (`hash10($ordinal . $url)`), it indexes `pf_meta[1]`, and it
 * appears as a raw integer in every `pf_filter` posting list and in every
 * `scolta.facets` posting body. Today it is a running counter over the
 * gather order, which means it is a function of the whole corpus — insert one
 * page near the front and every later page renumbers, so every fragment file
 * and every posting list changes.
 *
 * This makes the assignment durable instead. An id keeps its ordinal across
 * builds; a deleted id's ordinal goes on a free list and is handed to the next
 * new id rather than triggering a renumber.
 *
 * The property that matters for testing: **the full build reads this ledger
 * too**. A full rebuild therefore reproduces an incrementally-updated index's
 * numbering exactly, which is what makes "the two outputs are byte identical"
 * a well-defined assertion rather than a weakened search-equivalence check.
 *
 * Append-only with a free list, and a deliberate compaction, rather than
 * silent renumbering: renumbering is the one operation that invalidates every
 * artifact at once, so it is an operation an operator asks for, never a
 * side effect of a delete.
 *
 * @since 1.2.0
 * @stability experimental
 */
final class PageTableLedger
{
    public const FILENAME = 'page-table-ledger.php';

    /** Next never-used ordinal. */
    private int $next = 0;

    /**
     * Content-item id => assignment.
     *
     * The url is stored alongside the ordinal because the fragment filename
     * hashes both. A url change at a stable ordinal is therefore a fragment
     * *rename*, and without the previous url the old file is never deleted and
     * the index accumulates orphans that `pf_meta` no longer references.
     *
     * `filters` and `sortable` are carried because the small whole-corpus
     * artifacts are rewritten in full on every update and cannot be rebuilt
     * from the index alone: `pf_filter` drops single-value dimensions, and
     * `pf_meta[4]` stores the sort *order* but not the values it was sorted
     * by, so there is nothing to insert a changed value against.
     *
     * This is a derived duplicate of what the index already implies, and it can
     * in principle drift from it. That is an accepted trade rather than an
     * oversight: the alternative reconstructs sort position by binary-searching
     * neighbours' fragments, which is subtler than storing the value, and the
     * differential test against a full rebuild is precisely the check that
     * catches drift.
     *
     * @var array<string, array{ordinal: int, url: string, filters: array<string, mixed>, sortable: array<string, mixed>, contentHash: string}>
     */
    private array $byId = [];

    /**
     * Ordinals released by deletes, available for reuse.
     *
     * @var list<int>
     */
    private array $free = [];

    /**
     * Ordinals currently holding a tombstone (released and not yet reused).
     *
     * Tracked separately from $free because $free is consumed by allocate()
     * while the tombstone ratio has to survive until a compaction actually
     * removes the dead rows.
     *
     * @var array<int, true>
     */
    private array $tombstones = [];

    private bool $dirty = false;

    public function __construct(
        private readonly string $stateDir,
        private readonly StorageDriverInterface $storage,
    ) {
        $this->loadFromDisk();
    }

    /**
     * Return the ordinal for $id, allocating one if it has none.
     *
     * Reuses a freed ordinal before taking a fresh one, so a delete followed
     * by an append does not grow the page table.
     *
     * @param array<string, mixed> $filters  Merged filter map, from InvertedIndexBuilder::effectiveFilters().
     * @param array<string, mixed> $sortable Merged sortable map, from InvertedIndexBuilder::effectiveSortable().
     * @since 1.2.0
     * @stability experimental
     */
    public function allocate(
        string $id,
        string $url,
        array $filters = [],
        array $sortable = [],
        string $contentHash = '',
    ): int {
        if (isset($this->byId[$id])) {
            $existing = $this->byId[$id];
            if ($existing['url'] !== $url
                || $existing['filters'] !== $filters
                || $existing['sortable'] !== $sortable
                || $existing['contentHash'] !== $contentHash) {
                $this->byId[$id]['url']         = $url;
                $this->byId[$id]['filters']     = $filters;
                $this->byId[$id]['sortable']    = $sortable;
                $this->byId[$id]['contentHash'] = $contentHash;
                $this->dirty                    = true;
            }

            return $existing['ordinal'];
        }

        if ($this->free !== []) {
            $ordinal = array_shift($this->free);
        } else {
            $ordinal = $this->next;
            $this->next++;
        }

        unset($this->tombstones[$ordinal]);
        $this->byId[$id] = [
            'ordinal'     => $ordinal,
            'url'         => $url,
            'filters'     => $filters,
            'sortable'    => $sortable,
            'contentHash' => $contentHash,
        ];
        $this->dirty = true;

        return $ordinal;
    }

    /**
     * Release $id's ordinal to the free list and mark it as a tombstone.
     *
     * @return int|null The released ordinal, or null when $id was not assigned.
     * @since 1.2.0
     * @stability experimental
     */
    public function release(string $id): ?int
    {
        if (!isset($this->byId[$id])) {
            return null;
        }

        $ordinal = $this->byId[$id]['ordinal'];
        unset($this->byId[$id]);
        $this->free[]              = $ordinal;
        $this->tombstones[$ordinal] = true;
        $this->dirty               = true;

        return $ordinal;
    }

    /**
     * Release every assigned id that is not in $seenIds.
     *
     * A full build streams the corpus as it is now, so any id still in the
     * ledger afterwards has been deleted at the source. Its ordinal is
     * released and tombstoned rather than renumbered away, because renumbering
     * would invalidate every fragment filename in the index.
     *
     * @param array<string, true>|list<string> $seenIds Ids present in this build.
     * @return list<int> Ordinals released.
     * @since 1.2.0
     * @stability experimental
     */
    public function releaseAllExcept(array $seenIds): array
    {
        $seen = array_is_list($seenIds) ? array_fill_keys($seenIds, true) : $seenIds;

        $released = [];
        foreach (array_keys($this->byId) as $id) {
            if (!isset($seen[$id])) {
                $ordinal = $this->release($id);
                if ($ordinal !== null) {
                    $released[] = $ordinal;
                }
            }
        }

        return $released;
    }

    /**
     * The ordinal assigned to $id, or null when it has none.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function ordinalFor(string $id): ?int
    {
        return $this->byId[$id]['ordinal'] ?? null;
    }

    /**
     * The url recorded against $id at its last allocation, or null.
     *
     * Callers compare this with the incoming url to decide whether the
     * fragment file needs renaming.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function urlFor(string $id): ?string
    {
        return $this->byId[$id]['url'] ?? null;
    }

    /**
     * Filter values recorded against $id, or an empty array.
     *
     * @return array<string, mixed>
     * @since 1.2.0
     * @stability experimental
     */
    public function filtersFor(string $id): array
    {
        return $this->byId[$id]['filters'] ?? [];
    }

    /**
     * Sortable values recorded against $id, or an empty array.
     *
     * @return array<string, mixed>
     * @since 1.2.0
     * @stability experimental
     */
    public function sortableFor(string $id): array
    {
        return $this->byId[$id]['sortable'] ?? [];
    }

    /**
     * The content hash recorded against $id at its last allocation.
     *
     * An edit needs the page's *previous* term list to remove its stale
     * postings, and PageWordCache is keyed by exactly this hash — so the old
     * token data is already on disk and no separate per-page term store is
     * needed. Returns '' when unknown, which the updater treats as "cannot do
     * this incrementally" rather than guessing.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function contentHashFor(string $id): string
    {
        return $this->byId[$id]['contentHash'] ?? '';
    }

    /**
     * Every live row keyed by ordinal, for rebuilding the whole-corpus artifacts.
     *
     * @return array<int, array{id: string, url: string, filters: array<string, mixed>, sortable: array<string, mixed>, contentHash: string}>
     *         Keyed by ordinal, ascending. Tombstoned ordinals are absent.
     * @since 1.2.0
     * @stability experimental
     */
    public function rowsByOrdinal(): array
    {
        $rows = [];
        foreach ($this->byId as $id => $row) {
            $rows[$row['ordinal']] = [
                'id'          => $id,
                'url'         => $row['url'],
                'filters'     => $row['filters'],
                'sortable'    => $row['sortable'],
                'contentHash' => $row['contentHash'],
            ];
        }
        ksort($rows, SORT_NUMERIC);

        return $rows;
    }

    /**
     * Total size of the page table, live rows plus tombstones.
     *
     * This is the length `pf_meta[1]` must have and the `pageCount` the facet
     * index writer needs: the table stays dense across deletes so nothing
     * downstream grows a hole case.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function pageTableSize(): int
    {
        return $this->next;
    }

    /**
     * Ordinals that currently hold a tombstone.
     *
     * @return list<int>
     * @since 1.2.0
     * @stability experimental
     */
    public function tombstones(): array
    {
        return array_keys($this->tombstones);
    }

    /**
     * Fraction of the page table occupied by tombstones, 0.0 to 1.0.
     *
     * Exposed so an operator can decide when to compact. Deliberately not
     * wired to an automatic trigger: an unattended compaction is a full
     * rebuild, which is exactly the hour-long surprise incremental updates
     * exist to remove.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function tombstoneRatio(): float
    {
        if ($this->next === 0) {
            return 0.0;
        }

        return count($this->tombstones) / $this->next;
    }

    /**
     * Number of live (non-tombstone) pages.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function liveCount(): int
    {
        return count($this->byId);
    }

    /**
     * True when no assignment has ever been made.
     *
     * A build with an empty ledger numbers from zero in gather order, which is
     * byte-for-byte what the pipeline did before this class existed.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function isEmpty(): bool
    {
        return $this->byId === [] && $this->next === 0;
    }

    /**
     * Persist atomically, if anything changed.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->storage->makeDirectory($this->stateDir);
        $file = $this->stateDir . '/' . self::FILENAME;
        $tmp  = $file . '.tmp.' . getmypid();
        $this->storage->put($tmp, serialize([
            'next'       => $this->next,
            'byId'       => $this->byId,
            'free'       => $this->free,
            'tombstones' => $this->tombstones,
        ]));
        rename($tmp, $file);

        $this->dirty = false;
    }

    /**
     * Discard every assignment.
     *
     * This is compaction: the next full build renumbers from zero in gather
     * order and drops every tombstone. It is the only operation that renumbers
     * and it invalidates every fragment filename in the index, so the caller
     * must follow it with a full build before serving the result.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function reset(): void
    {
        $this->next       = 0;
        $this->byId       = [];
        $this->free       = [];
        $this->tombstones = [];
        $this->dirty      = true;
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
        if (!is_array($data)) {
            return;
        }

        $this->next       = (int) ($data['next'] ?? 0);
        $this->byId       = is_array($data['byId'] ?? null) ? $data['byId'] : [];
        $this->free       = is_array($data['free'] ?? null) ? array_values($data['free']) : [];
        $this->tombstones = is_array($data['tombstones'] ?? null) ? $data['tombstones'] : [];
    }
}
