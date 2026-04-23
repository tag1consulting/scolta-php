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
    ) {
    }

    /**
     * Start a clean build, wiping any existing state directory.
     *
     * @param int          $totalPages Total pages that will be indexed.
     * @param MemoryBudget $budget     Memory profile for this build.
     * @param array        $sourceMeta Arbitrary per-build metadata (language, fingerprint, …).
     */
    public static function fresh(int $totalPages, MemoryBudget $budget, array $sourceMeta = []): self
    {
        return new self('fresh', $totalPages, $budget, $sourceMeta);
    }

    /**
     * Resume an interrupted build from the last completed chunk.
     *
     * Total pages and source meta are read from the existing manifest.
     */
    public static function resume(MemoryBudget $budget): self
    {
        return new self('resume', null, $budget, []);
    }

    /**
     * Restart with fresh content, preserving the source manifest if it exists.
     */
    public static function restart(int $totalPages, MemoryBudget $budget, array $sourceMeta = []): self
    {
        return new self('restart', $totalPages, $budget, $sourceMeta);
    }

    /** "fresh" | "resume" | "restart" */
    public function mode(): string
    {
        return $this->mode;
    }

    /** Total pages to index, or null for resume (read from manifest). */
    public function totalPages(): ?int
    {
        return $this->totalPages;
    }

    public function memoryBudget(): MemoryBudget
    {
        return $this->memoryBudget;
    }

    /** Arbitrary metadata stored in the build manifest. */
    public function sourceMeta(): array
    {
        return $this->sourceMeta;
    }

    /** True for fresh and restart — both wipe existing state. */
    public function isFresh(): bool
    {
        return $this->mode === 'fresh' || $this->mode === 'restart';
    }
}
