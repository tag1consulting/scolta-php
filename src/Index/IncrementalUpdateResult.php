<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Outcome of one IncrementalIndexUpdater::commit().
 *
 * @since 1.1.1
 * @stability experimental
 */
final class IncrementalUpdateResult
{
    public function __construct(
        public readonly int $pagesUpdated,
        public readonly int $pagesDeleted,
        public readonly int $fragmentsWritten,
        public readonly int $chunksRewritten,
        public readonly float $durationSeconds,
        /** Released-and-unreused ordinals as a fraction of the page table. */
        public readonly float $tombstoneRatio,
    ) {}
}
