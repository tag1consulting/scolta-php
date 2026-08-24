<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Decide what an index needs and which path is cheaper, before doing any of it.
 *
 * Every adapter currently answers "what changed?" by starting a build and
 * letting the pipeline discover it: the gatherer compares each entity's
 * timestamp against the manifest, yields a body or a cached reference, and the
 * orchestrator works out the rest as it goes. That is fine for a full build and
 * useless for deciding *whether* to run one. An adapter that wants to route a
 * small change to {@see IncrementalIndexUpdater} has to know the size of the
 * change first, and there was nowhere to ask.
 *
 * This is that question, separated from the answer. It reads the two pieces of
 * state that already know — {@see TimestampManifest} for what was indexed and
 * when, {@see PageTableLedger} for which item ids the index actually holds — and
 * does no I/O of its own beyond those two objects. Nothing here loads a body,
 * touches the index directory, or writes anything, so an adapter can plan, log
 * the plan, show it to an operator, and only then act.
 *
 * ## The three routes
 *
 * `none` when nothing moved. `incremental` when few enough entities changed that
 * the updater is cheaper. `full` above the threshold, and also when there is no
 * index to update against — a planner that routed the first ever build to the
 * incremental path would hand the adapter a plan that can only throw.
 *
 * The threshold is the caller's, because the crossover is a property of the
 * corpus and the host rather than of this code: an update costs roughly a fixed
 * amount per changed page, a full build costs roughly a fixed amount per page in
 * the corpus, and where those lines cross depends on both.
 *
 * ## Item ids and entity keys are not the same thing
 *
 * The manifest is keyed by entity key, one per entity. The ledger is keyed by
 * item id, and one entity can produce several — a node with three translations
 * is three pages with three ids. So the planner cannot derive deletions by
 * subtracting one set from the other; it needs the adapter's own convention for
 * turning an entity key into the ids it owns, which arrives as a callback.
 * Getting this wrong deletes the translations of every entity that is still
 * published, which is why it is a parameter rather than a guess.
 *
 * @since 1.4.0
 * @stability experimental
 */
final class ChangeSetPlanner
{
    /**
     * @param \Closure(string): list<string> $itemIdsForEntityKey The adapter's
     *        item-id convention: entity key => every item id it owns. For a
     *        one-page-per-entity adapter this is `fn($k) => [$k]`.
     * @param int $fullRebuildThreshold Changed entities at or above which a full
     *        build is planned instead. Zero or less disables the full route, so
     *        every non-empty change set is incremental.
     */
    public function __construct(
        private readonly TimestampManifest $manifest,
        private readonly PageTableLedger $ledger,
        private readonly \Closure $itemIdsForEntityKey,
        private readonly int $fullRebuildThreshold = 1_000,
    ) {}

    /**
     * Plan the work for a corpus described as `[entityKey, changedTimestamp]`.
     *
     * The input is every *published* entity, which is what makes deletions
     * derivable: an id the ledger holds whose entity key never appeared has gone
     * from the source. Passing a filtered or paged subset would therefore plan
     * the deletion of everything left out, so the iterable has to be complete.
     *
     * A timestamp that is merely *different* counts as changed, not only a newer
     * one. A source whose timestamp went backwards — a restored revision, a
     * botched migration — has content the index does not match, and treating
     * that as unchanged is how an index quietly stops reflecting the site.
     *
     * @param iterable<array{0: string, 1: int}> $published Entity key and its changed timestamp.
     * @since 1.4.0
     * @stability experimental
     */
    public function plan(iterable $published): ChangeSet
    {
        $upserts   = [];
        $unchanged = 0;
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];

        foreach ($published as $row) {
            $entityKey = (string) $row[0];
            $timestamp = (int) $row[1];

            $seenKeys[$entityKey] = true;

            $known = $this->manifest->get($entityKey);
            if ($known === null || (int) $known['ts'] !== $timestamp) {
                $upserts[] = $entityKey;
                continue;
            }

            $unchanged++;
        }

        // An id the index holds whose owning entity is no longer published.
        // Built from the adapter's convention rather than by comparing the two
        // key spaces, because one entity can own several ids.
        $deletes     = [];
        $liveItemIds = [];
        foreach (array_keys($seenKeys) as $entityKey) {
            foreach (($this->itemIdsForEntityKey)((string) $entityKey) as $itemId) {
                $liveItemIds[(string) $itemId] = true;
            }
        }
        foreach ($this->ledgerItemIds() as $itemId) {
            if (!isset($liveItemIds[$itemId])) {
                $deletes[] = $itemId;
            }
        }

        $changed = count($upserts) + count($deletes);
        $route   = $this->route($changed);

        return new ChangeSet($upserts, $deletes, $unchanged, $route);
    }

    /**
     * Which route $changed pages calls for.
     *
     * A corpus with no ledger has no index to update, so there is nothing for
     * the incremental path to apply changes to and the only honest answer is a
     * full build.
     */
    private function route(int $changed): string
    {
        if ($this->ledger->isEmpty()) {
            return $changed === 0 ? ChangeSet::ROUTE_NONE : ChangeSet::ROUTE_FULL;
        }

        if ($changed === 0) {
            return ChangeSet::ROUTE_NONE;
        }

        if ($this->fullRebuildThreshold > 0 && $changed >= $this->fullRebuildThreshold) {
            return ChangeSet::ROUTE_FULL;
        }

        return ChangeSet::ROUTE_INCREMENTAL;
    }

    /**
     * Every item id the index currently holds.
     *
     * @return list<string>
     */
    private function ledgerItemIds(): array
    {
        $ids = [];
        foreach ($this->ledger->rowsByOrdinal() as $row) {
            $ids[] = (string) $row['id'];
        }

        return $ids;
    }
}
