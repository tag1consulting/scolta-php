<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Immutable value object describing what kind of index build to run.
 *
 * Framework adapters construct a BuildIntent from CLI flags and pass it to
 * BuildCoordinator::prepare() and IndexBuildOrchestrator::build().
 */
final class BuildIntent
{
    /** The pages handed to build() are every page the corpus has. */
    public const SCOPE_FULL = 'full';

    /** The pages handed to build() are a subset the caller chose. */
    public const SCOPE_PARTIAL = 'partial';

    /**
     * Why a scoped build may not also renumber the page table.
     *
     * Held as a constant because the refusal is symmetric: either order of
     * {@see self::withPartialScope()} and {@see self::withPageTableReset()}
     * has to give the same answer.
     */
    private const SCOPED_RESET_REFUSAL
        = 'Cannot discard the page-table ledger on a scope-limited build. Whether a scoped build may '
        . 'publish is decided by asking the ledger which of its rows this run did not cover, and a '
        . 'reset empties the ledger before that question can be asked — so the guard would find nothing '
        . 'outside the scope and publish an index holding only the pages this run gathered, deleting '
        . 'the rest of the site. The answer is not knowable before the gather, which is why this is '
        . 'refused up front rather than at the merge. Re-run the restart without --bundle/--entity-ids. '
        . 'If this site genuinely indexes only a subset, delete page-table-ledger.php and '
        . 'page-table-ledger.journal from the build state directory and re-run the scoped build: with '
        . 'no ledger at all there are no rows outside the scope to lose.';

    private function __construct(
        private readonly string $mode,
        private readonly ?int $totalPages,
        private readonly MemoryBudget $memoryBudget,
        private readonly array $sourceMeta,
        private readonly string $scope = self::SCOPE_FULL,
        private readonly bool $resetsPageTable = false,
    ) {}

    /**
     * Start a clean build, wiping any existing state directory.
     *
     * @param int          $totalPages Total pages that will be indexed.
     * @param MemoryBudget $budget     Memory profile for this build.
     * @param array        $sourceMeta Arbitrary per-build metadata (language, fingerprint, …).
     * @since 1.0.0
     * @stability stable
     */
    public static function fresh(int $totalPages, MemoryBudget $budget, array $sourceMeta = []): self
    {
        return new self('fresh', $totalPages, $budget, $sourceMeta);
    }

    /**
     * Resume an interrupted build from the last completed chunk.
     *
     * Total pages and source meta are read from the existing manifest.
     *
     * @since 1.0.0
     * @stability stable
     */
    public static function resume(MemoryBudget $budget): self
    {
        return new self('resume', null, $budget, []);
    }

    /**
     * Rebuild from scratch, discarding the page-table ledger.
     *
     * This is the difference between restart and fresh. A fresh build keeps
     * the ledger, because ordinal continuity across builds is what lets an
     * incremental update reuse the fragment files a full build wrote. A
     * restart renumbers from zero, which is what "rebuild from scratch" has
     * to mean for the advice printed by the merge's duplicate-ordinal check
     * and the orchestrator's integrity checks to be actionable: the condition
     * those errors report lives in the ledger, so a restart that inherited it
     * would fail again the same way, and did.
     *
     * Only the ledger goes. The token cache and the timestamp manifest are
     * content-hash keyed and expensive to rebuild, and neither can carry a
     * bad ordinal.
     *
     * A restart is therefore always full-scope, and {@see self::withPartialScope()}
     * refuses to narrow it.
     *
     * @since 1.0.0
     * @stability stable
     */
    public static function restart(int $totalPages, MemoryBudget $budget, array $sourceMeta = []): self
    {
        return new self('restart', $totalPages, $budget, $sourceMeta, self::SCOPE_FULL, true);
    }

    /**
     * This intent with the page-table ledger reset requested.
     *
     * The escape hatch for a corrupt ledger under a build that is not a
     * restart — an operator's --reset-ledger. Renumbering invalidates every
     * fragment filename in the index, so it is only meaningful on a build that
     * rewrites the whole index; asking for it on a resume is refused rather
     * than honoured, because a resume's chunks on disk already hold ordinals
     * the reset would hand out a second time. A partial scope is refused for a
     * different reason — see {@see self::SCOPED_RESET_REFUSAL}.
     *
     * @throws \LogicException When called on a resume or partial-scope intent.
     * @since 1.5.0
     * @stability experimental
     */
    public function withPageTableReset(): self
    {
        if ($this->mode === 'resume') {
            throw new \LogicException(
                'Cannot reset the page-table ledger on a resumed build: the chunk files already on disk '
                . 'reference the ordinals it holds, and a reset would hand those same ordinals to different '
                . 'pages. Run a restart instead.',
            );
        }

        if ($this->isPartial()) {
            throw new \LogicException(self::SCOPED_RESET_REFUSAL);
        }

        return new self(
            $this->mode,
            $this->totalPages,
            $this->memoryBudget,
            $this->sourceMeta,
            $this->scope,
            true,
        );
    }

    /**
     * "fresh" | "resume" | "restart"
     *
     * @since 1.0.0
     * @stability stable
     */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Total pages to index, or null for resume (read from manifest).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function totalPages(): ?int
    {
        return $this->totalPages;
    }

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function memoryBudget(): MemoryBudget
    {
        return $this->memoryBudget;
    }

    /**
     * Arbitrary metadata stored in the build manifest.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function sourceMeta(): array
    {
        return $this->sourceMeta;
    }

    /**
     * True for fresh and restart — both wipe existing state.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function isFresh(): bool
    {
        return $this->mode === 'fresh' || $this->mode === 'restart';
    }

    /**
     * Declare that the pages handed to build() are a subset of the corpus.
     *
     * Nothing downstream can work this out for itself, and the default is the
     * dangerous way round: several stages treat "the build never yielded this"
     * as "the source no longer has this", which is sound only when the build
     * walked everything. {@see PageTableLedger::releaseStaleRows()} frees the
     * ordinal of every id the run did not yield and the merge pads the hole
     * with a tombstone; the token cache and the timestamp manifest drop every
     * entry the run did not look up. A build scoped to one bundle therefore
     * deleted the rest of the site from the index — 1,518 live pages published
     * inside a 16,166-ordinal page table — because it had no way to say that
     * the other 14,648 pages were never in its remit.
     *
     * This is that way. A caller that filters what it gathers — by bundle, by
     * an explicit id list, by anything — must set it.
     *
     * @throws \LogicException When the intent already resets the page table.
     * @since 1.5.0
     * @stability experimental
     */
    public function withPartialScope(): self
    {
        if ($this->resetsPageTable) {
            throw new \LogicException(self::SCOPED_RESET_REFUSAL);
        }

        return new self(
            $this->mode,
            $this->totalPages,
            $this->memoryBudget,
            $this->sourceMeta,
            self::SCOPE_PARTIAL,
            $this->resetsPageTable,
        );
    }

    /**
     * "full" | "partial"
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function scope(): string
    {
        return $this->scope;
    }

    /**
     * True when the caller declared the pages a subset of the corpus.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function isPartial(): bool
    {
        return $this->scope === self::SCOPE_PARTIAL;
    }

    /**
     * True when this build must discard the page-table ledger first.
     *
     * Always true for restart; true for fresh only when an operator asked for
     * it via {@see self::withPageTableReset()}.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function resetsPageTable(): bool
    {
        return $this->resetsPageTable;
    }
}
