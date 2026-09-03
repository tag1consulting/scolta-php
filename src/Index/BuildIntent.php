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

    private function __construct(
        private readonly string $mode,
        private readonly ?int $totalPages,
        private readonly MemoryBudget $memoryBudget,
        private readonly array $sourceMeta,
        private readonly string $scope = self::SCOPE_FULL,
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
     * Restart with fresh content, preserving the source manifest if it exists.
     *
     * @since 1.0.0
     * @stability stable
     */
    public static function restart(int $totalPages, MemoryBudget $budget, array $sourceMeta = []): self
    {
        return new self('restart', $totalPages, $budget, $sourceMeta);
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
     * @since 1.5.0
     * @stability experimental
     */
    public function withPartialScope(): self
    {
        return new self(
            $this->mode,
            $this->totalPages,
            $this->memoryBudget,
            $this->sourceMeta,
            self::SCOPE_PARTIAL,
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
}
