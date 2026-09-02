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
    private function __construct(
        private readonly string $mode,
        private readonly ?int $totalPages,
        private readonly MemoryBudget $memoryBudget,
        private readonly array $sourceMeta,
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
     * @since 1.0.0
     * @stability stable
     */
    public static function restart(int $totalPages, MemoryBudget $budget, array $sourceMeta = []): self
    {
        return new self('restart', $totalPages, $budget, $sourceMeta, true);
    }

    /**
     * This intent with the page-table ledger reset requested.
     *
     * The escape hatch for a corrupt ledger under a build that is not a
     * restart — an operator's --reset-ledger. Renumbering invalidates every
     * fragment filename in the index, so it is only meaningful on a build that
     * rewrites the whole index; asking for it on a resume is refused rather
     * than honoured, because a resume's chunks on disk already hold ordinals
     * the reset would hand out a second time.
     *
     * @throws \LogicException When called on a resume intent.
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

        return new self($this->mode, $this->totalPages, $this->memoryBudget, $this->sourceMeta, true);
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
