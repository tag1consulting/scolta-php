<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Decides whether an interrupted build should be resumed or given up on.
 *
 * A build too large for one process yields on memory pressure
 * ({@see StatusReport::MEMORY_ABORT}) and is continued by another segment.
 * Two kinds of driver run those segments: a CLI command that spawns child
 * processes and sees only their exit status, and a queue worker that runs one
 * segment per cron invocation with nothing in memory between runs. Both have
 * the same three questions — is there a build to resume, did this segment
 * make progress, and has the chain gone on too long — and this answers them
 * from the state directory alone.
 *
 * It reads no files itself in {@see self::failureReason()}, which is the form
 * the process-spawning drivers use; {@see self::resumable()} and
 * {@see self::stopReason()} are the in-process form for a worker that holds
 * the BuildState and the StatusReport directly.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ResumeChainPolicy
{
    /**
     * Segments a build may run before it is declared runaway.
     *
     * Progress alone is not a licence to run segments forever: a few pages per
     * segment would satisfy the stall check indefinitely.
     */
    public const DEFAULT_MAX_SEGMENTS = 50;

    /**
     * @param string|null $memoryLimit PHP memory_limit to quote in remediation, or null when unknown.
     * @param int         $maxSegments Segments allowed before the chain is stopped as runaway.
     * @since 2.0.0
     * @stability experimental
     */
    public function __construct(
        private readonly ?string $memoryLimit = null,
        private readonly int $maxSegments = self::DEFAULT_MAX_SEGMENTS,
    ) {}

    /**
     * Whether the state directory holds a build the next run should resume.
     *
     * True when an interrupted build is on disk and its last recorded outcome
     * is either a memory yield or nothing at all (the process was killed
     * before it could record one; its committed chunks are still good). A
     * recorded failure of any other kind is not resumable: resuming would
     * re-walk the corpus to reach the same error, so the caller should start
     * fresh, which wipes the broken state.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function resumable(BuildState $state): bool
    {
        if ($state->shouldResume() === null) {
            return false;
        }

        $outcome = $state->readOutcome();

        return $outcome === null || $outcome['error'] === StatusReport::MEMORY_ABORT;
    }

    /**
     * Why an in-process segment's failed report ends the build, or null to run another.
     *
     * For a memory yield this applies {@see self::failureReason()} to the
     * segment counter and page marks {@see BuildState::resumeBuild()} keeps in
     * the manifest. When the answer is to stop, the reason is also recorded as
     * the build's outcome, so {@see self::resumable()} says no on the next run
     * and the caller starts fresh rather than resuming into the same wall.
     *
     * @param StatusReport $report The failed report build() returned.
     * @param BuildState   $state  The state directory that build ran against.
     *
     * @throws \LogicException When handed a successful report.
     * @since 2.0.0
     * @stability experimental
     */
    public function stopReason(StatusReport $report, BuildState $state): ?string
    {
        if ($report->success) {
            throw new \LogicException('stopReason() decides what to do after a failed segment; this one succeeded.');
        }

        if (!$report->isMemoryAbort()) {
            return $report->error ?? 'Build failed';
        }

        $reason = $this->failureReason(
            ['error' => StatusReport::MEMORY_ABORT],
            $report->pagesProcessed,
            $state->pagesAtSegmentStart(),
            $state->segment(),
        );
        if ($reason !== null) {
            $state->recordOutcome(false, $reason, $report->pagesProcessed);
        }

        return $reason;
    }

    /**
     * Why the chain must stop after a segment that exited non-zero, or null to run another.
     *
     * @param array<string, mixed>|null $outcome        What the segment recorded on its way out
     *                                                  ({@see BuildState::readOutcome()}), or null when it recorded
     *                                                  nothing — an OOM kill, a fatal, a signal.
     * @param int                       $pagesCommitted Pages the shared build manifest shows committed now.
     * @param int                       $pagesBefore    Pages it showed before this segment ran.
     * @param int                       $segment        0 for the run that started the build, N for its Nth resume.
     *
     * @return string|null The failure to report, or null when the segment yielded for
     *                     memory and made progress, so another segment is worth running.
     * @since 2.0.0
     * @stability experimental
     */
    public function failureReason(?array $outcome, int $pagesCommitted, int $pagesBefore, int $segment): ?string
    {
        // A segment that recorded anything other than a memory yield has decided
        // this build is broken. Resuming re-walks the whole corpus to reach the
        // same error, so the chain stops here and reports what actually failed.
        if ($outcome !== null && ($outcome['error'] ?? null) !== StatusReport::MEMORY_ABORT) {
            $error = $outcome['error'] ?? null;

            return sprintf(
                'The build failed in segment %d and the index has not been republished: %s',
                $segment,
                // A segment that recorded success and still exited non-zero
                // failed after its build returned — publishing, verifying,
                // shutting down.
                is_string($error) && $error !== ''
                    ? $error
                    : 'the segment reported a successful build and then exited non-zero; see its output.',
            );
        }

        // Either the segment yielded for memory or it died without recording
        // anything. Both leave progress as the only evidence, and chunksWritten
        // cannot supply it: it counts the chunk files on disk, cumulative for
        // the whole build. Only progress since this segment started tells
        // carrying forward from repeating.
        if ($pagesCommitted <= $pagesBefore) {
            return sprintf(
                'The build stalled at %d pages committed: %s hit the memory limit without committing a page, '
                . 'so another resume would repeat it. The index has not been republished. Raise PHP memory_limit '
                . '(currently %s) or lower the per-chunk footprint (memory budget, chunk size), '
                . 'then re-run with --restart.',
                $pagesCommitted,
                $segment === 0 ? 'this build' : sprintf('segment %d', $segment),
                $this->memoryLimit ?? 'unknown',
            );
        }

        if ($segment >= $this->maxSegments) {
            return sprintf(
                'The build did not complete within %d resume segments (%d pages committed). The index has not '
                . 'been republished. Raise PHP memory_limit (currently %s) or the memory budget so fewer segments '
                . 'are needed, then re-run with --restart.',
                $this->maxSegments,
                $pagesCommitted,
                $this->memoryLimit ?? 'unknown',
            );
        }

        return null;
    }
}
