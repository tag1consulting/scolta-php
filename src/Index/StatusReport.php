<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Immutable result returned by IndexBuildOrchestrator::build().
 *
 * Contains enough information for framework adapters to render a meaningful
 * success/failure summary and for telemetry / monitoring tools.
 */
final class StatusReport
{
    /**
     * Error value meaning "this run yielded on memory pressure; resume it".
     *
     * A build that returns this has committed its work and stopped on purpose.
     * It is the only failure a caller may answer by running another segment;
     * every other error means the build is broken and the chain must stop.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public const MEMORY_ABORT = 'memory_abort';

    public function __construct(
        public readonly string $version,
        public readonly string $pagefindVersion,
        public readonly string $resolvedIndexer,
        public readonly int $pagesProcessed,
        public readonly int $chunksWritten,
        public readonly int $peakMemoryBytes,
        public readonly int $memoryBudgetBytes,
        public readonly float $durationSeconds,
        public readonly string $outputDir,
        public readonly ?string $warnings = null,
        public readonly bool $success = true,
        public readonly ?string $error = null,
        public readonly bool $lockHeld = false,
    ) {}

    /**
     * Convert to a BuildResult for callers that still use the legacy return type.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function toBuildResult(): BuildResult
    {
        $peakMb  = round($this->peakMemoryBytes / 1_048_576, 1);
        $message = $this->success
            ? "Built index for {$this->pagesProcessed} pages ({$this->chunksWritten} chunks, peak {$peakMb} MB)"
            : ($this->error ?? 'Build failed');

        return new BuildResult(
            success: $this->success,
            message: $message,
            pageCount: $this->pagesProcessed,
            fileCount: $this->chunksWritten,
            elapsedSeconds: $this->durationSeconds,
            error: $this->error,
        );
    }

    /**
     * Whether this run stopped to be resumed rather than because it failed.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function isMemoryAbort(): bool
    {
        return !$this->success && $this->error === self::MEMORY_ABORT;
    }

    /**
     * Whether this run was refused because another process holds the build lock.
     *
     * Not a failure of the build on disk: nothing was run and nothing was
     * recorded, so a driver should try again once the lock is free rather
     * than count it against the build or start a fresh one.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function isLockContention(): bool
    {
        return !$this->success && $this->lockHeld;
    }

    /**
     * Megabytes as a human-readable string, e.g. "42.3 MB".
     *
     * @since 1.0.0
     * @stability stable
     */
    public function peakMemoryMb(): string
    {
        return round($this->peakMemoryBytes / 1_048_576, 1) . ' MB';
    }
}
