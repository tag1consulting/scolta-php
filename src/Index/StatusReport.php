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
    ) {
    }

    /**
     * Convert to a BuildResult for callers that still use the legacy return type.
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

    /** Megabytes as a human-readable string, e.g. "42.3 MB". */
    public function peakMemoryMb(): string
    {
        return round($this->peakMemoryBytes / 1_048_576, 1) . ' MB';
    }
}
