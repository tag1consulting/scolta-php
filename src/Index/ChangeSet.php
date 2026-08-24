<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * What an adapter has to do to bring an index up to date, and how.
 *
 * The output of {@see ChangeSetPlanner}. It is a plain value object on purpose:
 * an adapter decides whether to act on it, and a plan that performed I/O of its
 * own would be impossible to log, test or show an operator before it ran.
 *
 * @since 1.4.0
 * @stability experimental
 */
final class ChangeSet
{
    /** No work: every published entity is already indexed at its current timestamp. */
    public const ROUTE_NONE = 'none';

    /** Few enough changes that {@see IncrementalIndexUpdater} is the cheaper path. */
    public const ROUTE_INCREMENTAL = 'incremental';

    /** Enough changes that a full build costs less than the updates would. */
    public const ROUTE_FULL = 'full';

    /**
     * @param list<string> $upsertEntityKeys Entities whose timestamp moved, or that the index has never seen.
     * @param list<string> $deleteItemIds    Item ids the ledger holds whose entity is no longer published.
     * @param int          $unchangedCount   Entities that need no work at all.
     * @param string       $route            One of the ROUTE_* constants.
     */
    public function __construct(
        public readonly array $upsertEntityKeys,
        public readonly array $deleteItemIds,
        public readonly int $unchangedCount,
        public readonly string $route,
    ) {}

    /**
     * How to apply this change set: `none`, `incremental` or `full`.
     *
     * A method as well as a property because the route is the question callers
     * actually ask, and `$plan->route()` reads as a decision where
     * `$plan->route` reads as a field.
     *
     * @since 1.4.0
     * @stability experimental
     */
    public function route(): string
    {
        return $this->route;
    }

    /**
     * Total pages this change set touches, upserts plus deletes.
     *
     * @since 1.4.0
     * @stability experimental
     */
    public function changedCount(): int
    {
        return count($this->upsertEntityKeys) + count($this->deleteItemIds);
    }

    /**
     * True when there is nothing to do.
     *
     * @since 1.4.0
     * @stability experimental
     */
    public function isEmpty(): bool
    {
        return $this->route === self::ROUTE_NONE;
    }
}
