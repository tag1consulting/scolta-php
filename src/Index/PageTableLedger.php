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

    /**
     * Append-only record of allocations and releases since the last {@see self::save()}.
     *
     * The snapshot in FILENAME is only written when a build finishes. A build
     * that aborts part-way still has chunk files on disk that reference the
     * ordinals it handed out, and a resumed process that cannot see those
     * ordinals allocates the same numbers again — the merge then keeps one
     * page per ordinal and silently drops the rest, which is the corruption
     * this journal exists to make impossible.
     */
    public const JOURNAL_FILENAME = 'page-table-ledger.journal';

    /**
     * Max time to wait for the journal lock before giving up.
     *
     * Unlike the manifest's per-writer temp files, this journal is a fixed
     * shared path that genuinely needs mutual exclusion, so a blocking
     * flock() is not an option: on NFS-backed storage a process killed
     * ungracefully can leave the server believing the lock is still held,
     * and a blocking wait against that stale state hangs forever, even
     * across SIGKILL. Poll with a bounded timeout instead.
     */
    private const JOURNAL_LOCK_TIMEOUT_SECONDS = 60;

    /** Interval between lock-acquisition attempts while waiting. */
    private const JOURNAL_LOCK_POLL_MICROSECONDS = 250_000;

    /** Next never-used ordinal. */
    private int $next = 0;

    /**
     * Monotonic build counter, bumped once per fresh build.
     *
     * A row's `gen` records the build that last saw its id. It replaces
     * collecting every id of the corpus in an array to pass to
     * {@see self::releaseAllExcept()}: that array is O(corpus) in memory, and
     * across a resumed build it is worse than useless, because each process
     * only ever sees its own segment and would release every page the previous
     * segments committed.
     */
    private int $generation = 0;

    /**
     * Journal records not yet appended to disk.
     *
     * @var list<array{t: string, id: string, row?: array<string, mixed>, gen?: int, ordinal?: int}>
     */
    private array $pendingJournal = [];

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
     * **The key type here is a lie PHP tells.** PHP normalizes a decimal-integer
     * string array key to an int at insertion, so allocate('42', …) stores the
     * row under int `42` while allocate('42-es', …) stores it under string
     * `'42-es'`. Writes and lookups are unaffected, because the same
     * normalization applies to the subscript. Reading a key *back out* is where
     * it bites, and a Drupal node id is exactly the numeric case. Never iterate
     * this array's keys directly; go through {@see self::assignedIds()}, which
     * is the one place the type is restored.
     *
     * `gen` is optional because a snapshot written before it existed has rows
     * without one; those read as generation 0, which is older than any build.
     *
     * @var array<string, array{ordinal: int, url: string, filters: array<string, mixed>, sortable: array<string, mixed>, contentHash: string, gen?: int}>
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
        private readonly int $journalLockTimeoutSeconds = self::JOURNAL_LOCK_TIMEOUT_SECONDS,
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
            $changed  = $existing['url'] !== $url
                || $existing['filters'] !== $filters
                || $existing['sortable'] !== $sortable
                || $existing['contentHash'] !== $contentHash;
            $unseen = ($existing['gen'] ?? 0) !== $this->generation;

            if ($changed || $unseen) {
                $this->byId[$id]['url']         = $url;
                $this->byId[$id]['filters']     = $filters;
                $this->byId[$id]['sortable']    = $sortable;
                $this->byId[$id]['contentHash'] = $contentHash;
                $this->byId[$id]['gen']         = $this->generation;
                $this->dirty                    = true;
                // Journalled even when only `gen` moved: that stamp is what
                // tells a later segment of the same build that this page is
                // still in the corpus and must not be tombstoned.
                $this->pendingJournal[] = ['t' => 'a', 'id' => $id, 'row' => $this->byId[$id]];
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
            'gen'         => $this->generation,
        ];
        $this->dirty            = true;
        $this->pendingJournal[] = ['t' => 'a', 'id' => $id, 'row' => $this->byId[$id]];

        return $ordinal;
    }

    /**
     * Open a build against this ledger.
     *
     * A fresh build takes the next generation, so every row still carrying the
     * previous one is a page the corpus no longer yielded and
     * {@see self::releaseStaleRows()} can tombstone it. A resumed build keeps
     * the generation its earlier segments stamped, which is the whole point:
     * coverage is a property of the build, not of the process.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function beginBuild(bool $fresh): void
    {
        if (!$fresh) {
            return;
        }

        $this->generation++;
        $this->dirty            = true;
        $this->pendingJournal[] = ['t' => 'g', 'id' => '', 'gen' => $this->generation];
    }

    /**
     * The build generation rows allocated right now are stamped with.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * Release every row the current build did not see.
     *
     * The generation-stamped equivalent of {@see self::releaseAllExcept()},
     * and the only one that is correct across a resumed build.
     *
     * @return list<int> Ordinals released.
     * @since 1.2.0
     * @stability experimental
     */
    public function releaseStaleRows(): array
    {
        $released = [];
        foreach ($this->staleRowIds() as $id) {
            $ordinal = $this->release($id);
            if ($ordinal !== null) {
                $released[] = $ordinal;
            }
        }

        return $released;
    }

    /**
     * The ids {@see self::releaseStaleRows()} would release, without releasing
     * them.
     *
     * A caller that gathered only part of the corpus cannot let those rows go
     * — "this build did not yield it" means "it was out of scope" there, not
     * "it was deleted" — but it still has to know that the set is non-empty,
     * because a merge of its own chunks alone would publish tombstones in
     * place of every one of them. So the question and the release are separate
     * calls.
     *
     * @return list<string> Ids the current build has not committed.
     * @since 1.5.0
     * @stability experimental
     */
    public function staleRowIds(): array
    {
        $stale = [];
        foreach ($this->assignedIds() as $id) {
            if (($this->byId[$id]['gen'] ?? 0) === $this->generation) {
                continue;
            }
            $stale[] = $id;
        }

        return $stale;
    }

    /**
     * Flush pending allocations to the journal.
     *
     * Must be called before the chunk file that references these ordinals is
     * written, never after: an ordinal on disk without its chunk is re-used
     * for the same id on resume and costs nothing, whereas a chunk on disk
     * without its ordinal is the collision that corrupts the index.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function checkpoint(): void
    {
        if ($this->pendingJournal === []) {
            return;
        }

        $this->storage->makeDirectory($this->stateDir);

        // base64 per line: a filter or sortable value may contain any byte,
        // and a raw serialize() payload with a newline in it would make the
        // journal unparseable exactly on the corpora that need it most.
        $payload = '';
        foreach ($this->pendingJournal as $record) {
            $payload .= base64_encode(serialize($record)) . "\n";
        }

        $path = $this->stateDir . '/' . self::JOURNAL_FILENAME;

        $fp = fopen($path, 'a');
        if ($fp === false) {
            throw new \RuntimeException(
                "Failed to open the page-table journal at {$path}. "
                . 'Refusing to continue: the chunk about to be written would reference '
                . 'ordinals no resumed process could see.',
            );
        }

        $locked   = false;
        $deadline = microtime(true) + $this->journalLockTimeoutSeconds;
        do {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(self::JOURNAL_LOCK_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        if (!$locked) {
            fclose($fp);
            throw new \RuntimeException(
                'Failed to acquire the page-table journal lock after ' . $this->journalLockTimeoutSeconds . 's; '
                . 'a previous process may have died holding a stale NFS lock. If no other build is genuinely '
                . "running, the environment's storage/lock state may need administrator attention.",
            );
        }

        $written = fwrite($fp, $payload);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // A short write counts as a failure, not just an outright false. fwrite()
        // returns a byte count, and a truncated trailing line is precisely the
        // silent corruption this journal exists to prevent: the reader skips an
        // unparseable line, so the ordinals it described are handed out again on
        // resume and the merge drops every page but one.
        if ($written !== strlen($payload)) {
            throw new \RuntimeException(
                "Failed to append to the page-table journal at {$path}. "
                . 'Refusing to continue: the chunk about to be written would reference '
                . 'ordinals no resumed process could see.',
            );
        }

        $this->pendingJournal = [];
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
        // Journalled, which it was not before. A release that only lived in the
        // snapshot meant any commit that deleted had to write the whole table:
        // replaying an allocation journal over an older snapshot would resurrect
        // the deleted row. With the removal recorded, checkpoint() is enough.
        $this->pendingJournal[] = ['t' => 'r', 'id' => $id, 'ordinal' => $ordinal];

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
        foreach ($this->assignedIds() as $id) {
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
     * True when the current build already allocated and committed $id.
     *
     * A row only carries the current generation once its allocation reached
     * the journal, and the journal is written immediately before the chunk
     * that uses it — so this answers "an earlier segment of this build already
     * indexed that page" and nothing weaker.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function wasSeenThisBuild(string $id): bool
    {
        return isset($this->byId[$id]) && ($this->byId[$id]['gen'] ?? 0) === $this->generation;
    }

    /**
     * Every id the current build has already committed.
     *
     * A generator rather than an array: the caller wants a high-water mark to
     * restart a source query from, and materialising one string per page of
     * the corpus to compute it would reintroduce exactly the per-corpus
     * allocation the generation stamp removed.
     *
     * @return \Generator<int, string>
     * @since 1.2.0
     * @stability experimental
     */
    public function seenIdsThisBuild(): \Generator
    {
        foreach ($this->byId as $id => $row) {
            if (($row['gen'] ?? 0) === $this->generation) {
                yield (string) $id;
            }
        }
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
        foreach ($this->assignedIds() as $id) {
            $row                   = $this->byId[$id];
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
     * Unlike {@see self::assignedIds()} this needs no cast: $tombstones is
     * keyed by ordinal, which is already an int at the point of insertion, so
     * PHP's key normalization is a no-op here rather than a lossy conversion.
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
            'generation' => $this->generation,
        ]));
        rename($tmp, $file);

        // The snapshot now contains everything the journal was holding, and a
        // stale journal replayed over a newer snapshot would resurrect rows
        // this build released. Drop it only after the rename has landed.
        $this->pendingJournal = [];
        $journal              = $this->stateDir . '/' . self::JOURNAL_FILENAME;
        if ($this->storage->exists($journal)) {
            $this->storage->delete($journal);
        }

        $this->dirty = false;
    }

    /**
     * Persist an incremental commit: append to the journal, or snapshot.
     *
     * A full build ends with {@see self::save()}, which writes the whole table
     * and truncates the journal, and on a corpus of any size that snapshot is
     * the single largest thing an update does — 0.55 s on the reference corpus,
     * to record a change to one page. An append is O(the change).
     *
     * That was only possible once {@see self::release()} started journalling,
     * because a journal that records allocations but not removals replays into a
     * table where deleted rows come back. Now both are recorded, so the journal
     * is a complete description of what happened since the snapshot.
     *
     * The journal still has to be bounded, or a site that only ever runs
     * incremental updates grows one forever and pays for it on every load. Past
     * $compactBytes the ledger snapshots instead, which truncates it.
     *
     * @param int $compactBytes Journal size at which to snapshot instead.
     * @since 1.4.0
     * @stability experimental
     */
    public function commitIncremental(int $compactBytes = 8 * 1024 * 1024): void
    {
        $journal = $this->stateDir . '/' . self::JOURNAL_FILENAME;
        $size    = $this->storage->exists($journal) ? (int) @filesize($journal) : 0;

        if ($size >= $compactBytes) {
            $this->save();

            return;
        }

        $this->checkpoint();
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
        // Pending records describe assignments that no longer exist; a
        // checkpoint() after this would write them back out.
        $this->pendingJournal = [];
        $this->dirty          = true;
    }

    /**
     * Every assigned id, with its declared string type restored.
     *
     * This exists so that no code path can read a raw $byId key. PHP stores a
     * decimal-integer id like a Drupal node id under an int key, so
     * `array_keys($this->byId)` returns `int|string` however the property is
     * annotated — and handing that to any of the `string $id` methods here is a
     * TypeError, not a coercion, under strict_types. Routing every read through
     * one accessor makes that unrepresentable rather than a rule each new
     * caller has to remember.
     *
     * @return list<string>
     */
    private function assignedIds(): array
    {
        return array_map(strval(...), array_keys($this->byId));
    }

    private function loadFromDisk(): void
    {
        $path = $this->stateDir . '/' . self::FILENAME;
        if ($this->storage->exists($path)) {
            try {
                $raw  = $this->storage->get($path);
                $data = @unserialize($raw, ['allowed_classes' => false]);
            } catch (\Throwable) {
                $data = null;
            }

            if (is_array($data)) {
                $this->next       = (int) ($data['next'] ?? 0);
                $this->byId       = is_array($data['byId'] ?? null) ? $data['byId'] : [];
                $this->free       = is_array($data['free'] ?? null) ? array_values($data['free']) : [];
                $this->tombstones = is_array($data['tombstones'] ?? null) ? $data['tombstones'] : [];
                $this->generation = (int) ($data['generation'] ?? 0);
            }
        }

        $this->replayJournal();
    }

    /**
     * Replay allocations an interrupted build appended after the last snapshot.
     *
     * Each record is applied exactly as {@see self::allocate()} applied it, so
     * a resumed process sees the same assignment the aborted one handed to the
     * chunk files already on disk.
     */
    private function replayJournal(): void
    {
        $path = $this->stateDir . '/' . self::JOURNAL_FILENAME;
        if (!$this->storage->exists($path)) {
            return;
        }

        try {
            $raw = $this->storage->get($path);
        } catch (\Throwable) {
            return;
        }

        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = base64_decode($line, true);
            if ($decoded === false) {
                continue;
            }

            $record = @unserialize($decoded, ['allowed_classes' => false]);
            if (!is_array($record)) {
                continue;
            }

            if (($record['t'] ?? '') === 'g') {
                $this->generation = max($this->generation, (int) ($record['gen'] ?? 0));
                continue;
            }

            if (($record['t'] ?? '') === 'r') {
                // Mirror release(): drop the row, put the ordinal back on the
                // free list, tombstone it. Read from the record rather than
                // from $byId, because the row may already be absent — the
                // snapshot this is replayed over can predate the allocation
                // that the release removed.
                $id      = (string) ($record['id'] ?? '');
                $ordinal = (int) ($record['ordinal'] ?? -1);
                if ($id === '' || $ordinal < 0) {
                    continue;
                }
                unset($this->byId[$id]);
                if (!in_array($ordinal, $this->free, true)) {
                    $this->free[] = $ordinal;
                }
                $this->tombstones[$ordinal] = true;
                if ($ordinal >= $this->next) {
                    $this->next = $ordinal + 1;
                }
                continue;
            }

            if (($record['t'] ?? '') !== 'a' || !is_array($record['row'] ?? null)) {
                continue;
            }

            $id  = (string) ($record['id'] ?? '');
            $row = $record['row'];
            if ($id === '' || !isset($row['ordinal'])) {
                continue;
            }

            // Rebuilt field by field rather than assigned wholesale: this data
            // came off disk from a process that died, and a malformed row must
            // not be able to reshape the table every posting list indexes into.
            $ordinal         = (int) $row['ordinal'];
            $this->byId[$id] = [
                'ordinal'     => $ordinal,
                'url'         => (string) ($row['url'] ?? ''),
                'filters'     => is_array($row['filters'] ?? null) ? $row['filters'] : [],
                'sortable'    => is_array($row['sortable'] ?? null) ? $row['sortable'] : [],
                'contentHash' => (string) ($row['contentHash'] ?? ''),
                'gen'         => (int) ($row['gen'] ?? 0),
            ];
            $this->generation = max($this->generation, (int) ($row['gen'] ?? 0));

            // Mirror allocate(): a reused ordinal leaves the free list and
            // loses its tombstone, and a fresh one advances the high-water mark.
            $this->free = array_values(array_filter($this->free, static fn(int $o): bool => $o !== $ordinal));
            unset($this->tombstones[$ordinal]);
            if ($ordinal >= $this->next) {
                $this->next = $ordinal + 1;
            }
        }

        // Replayed state differs from the snapshot on disk; a save() that
        // short-circuited on !dirty here would lose every replayed row.
        $this->dirty = true;
    }
}
