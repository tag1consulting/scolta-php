<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Drives a memory-yielded build to its end, one fresh process per segment.
 *
 * A build that yields with {@see StatusReport::MEMORY_ABORT} has committed its
 * work and needs another process to carry on: a second segment inside the same
 * fragmented heap is judged a stall. The loop that spawns those segments used
 * to live in each host's CLI command, so a fix to it landed in one and lagged
 * in the others, and a queue worker could not chain at all. This owns the
 * loop; the host supplies only the callable that runs one child segment.
 *
 * Per segment it clears the recorded outcome, runs the callable with
 * {@see self::SEGMENT_ENV} set, and on a non-zero exit asks the
 * {@see ResumeChainPolicy} whether the outcome the child recorded and the
 * pages it committed justify another. It does no I/O beyond BuildState reads
 * and the callable; verifying the published index and reacting to the result
 * stay with the host.
 *
 * @since 1.5.0
 * @stability experimental
 */
final class ResumeChainRunner
{
    /**
     * Set in the environment of every segment this runs.
     *
     * A `--resume` flag alone cannot tell a segment from an operator re-running
     * the command by hand after an interruption. Only the segment must report
     * its yield and return instead of chaining on itself: nesting a chain in
     * every segment would keep one bootstrapped host alive per segment.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public const SEGMENT_ENV = 'SCOLTA_RESUME_SEGMENT';

    /** @var \Closure(array<string, string>): int */
    private readonly \Closure $runSegment;

    /**
     * @param BuildState                             $state      The state directory the build runs against.
     * @param ResumeChainPolicy                      $policy     Decides after each failed segment whether to run another.
     * @param callable(array<string, string>): int   $runSegment Runs one child segment with the given environment
     *                                                           variables added and returns its exit code.
     * @since 1.5.0
     * @stability experimental
     */
    public function __construct(
        private readonly BuildState $state,
        private readonly ResumeChainPolicy $policy,
        callable $runSegment,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->runSegment = $runSegment(...);
    }

    /**
     * Whether this process is a segment spawned by a runner.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public static function isSegment(): bool
    {
        return getenv(self::SEGMENT_ENV) !== false;
    }

    /**
     * Run segments until the build completes or the policy stops the chain.
     *
     * @param StatusReport $yielded The memory-aborted report of the segment that ran in this process.
     *
     * @return StatusReport Success once a segment exits 0, with the pages the build committed in total;
     *                      otherwise a failed report whose error is the policy's reason to stop.
     *
     * @throws \LogicException When the report is not a memory yield.
     * @since 1.5.0
     * @stability experimental
     */
    public function run(StatusReport $yielded): StatusReport
    {
        if (!$yielded->isMemoryAbort()) {
            throw new \LogicException('run() continues a build that yielded on memory; this report did not.');
        }

        $pagesBefore = $yielded->pagesProcessed;
        if ($yielded->chunksWritten === 0) {
            $reason = $this->policy->failureReason(['error' => StatusReport::MEMORY_ABORT], $pagesBefore, $pagesBefore, 0);

            return $this->finish($yielded, false, $reason, $pagesBefore);
        }

        for ($segment = 1; ; $segment++) {
            $this->logger->notice(
                'Memory limit reached at {pages} pages. Continuing in a fresh process (segment {n})...',
                ['pages' => $pagesBefore, 'n' => $segment],
            );

            // Cleared first, so a missing file after the child exits reads as
            // "it died without reporting", not as the previous segment's verdict.
            $this->state->clearOutcome();
            $exitCode = ($this->runSegment)([self::SEGMENT_ENV => '1']);

            if ($exitCode === 0) {
                $this->logger->notice('Index built across {n} resume segment(s).', ['n' => $segment]);

                return $this->finish($yielded, true, null, $this->pagesCommitted());
            }

            // Every failure exits non-zero, so the exit code alone cannot say
            // whether the segment yielded for memory or found the build broken.
            // The segment recorded which; the policy turns that into the decision.
            $pagesNow = $this->pagesCommitted();
            $reason   = $this->policy->failureReason($this->state->readOutcome(), $pagesNow, $pagesBefore, $segment);
            if ($reason !== null) {
                return $this->finish($yielded, false, $reason, $pagesNow);
            }
            $pagesBefore = $pagesNow;
        }
    }

    private function pagesCommitted(): int
    {
        return max($this->state->readOutcome()['pages_processed'] ?? 0, $this->state->getPagesProcessed());
    }

    private function finish(StatusReport $yielded, bool $success, ?string $error, int $pages): StatusReport
    {
        return new StatusReport(
            version: $yielded->version,
            pagefindVersion: $yielded->pagefindVersion,
            resolvedIndexer: $yielded->resolvedIndexer,
            pagesProcessed: $pages,
            chunksWritten: $this->state->getChunksWritten(),
            peakMemoryBytes: $yielded->peakMemoryBytes,
            memoryBudgetBytes: $yielded->memoryBudgetBytes,
            durationSeconds: $yielded->durationSeconds,
            outputDir: $yielded->outputDir,
            warnings: $yielded->warnings,
            success: $success,
            error: $error,
        );
    }
}
