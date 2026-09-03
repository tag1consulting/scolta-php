<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Creates a BuildIntent from the resume/restart/fresh flag triple.
 *
 * Centralises the match(true) pattern that all three adapter CLIs repeat.
 *
 * @since      0.3.3
 * @stability  experimental
 */
final class BuildIntentFactory
{
    /**
     * @param bool         $resume     True when --resume was passed.
     * @param bool         $restart    True when --restart was passed.
     * @param int          $totalCount Total pages available (ignored for resume).
     * @param MemoryBudget $budget     Memory profile for this build.
     * @param bool         $partial    True when the caller filtered the corpus
     *                                 (a bundle, an id list) rather than
     *                                 walking all of it. Not applied to resume,
     *                                 which inherits the scope recorded in the
     *                                 manifest by the segment that started the
     *                                 build. See BuildIntent::withPartialScope().
     *
     * @since     0.3.3
     * @stability experimental
     */
    public static function fromFlags(
        bool $resume,
        bool $restart,
        int $totalCount,
        MemoryBudget $budget,
        bool $partial = false,
    ): BuildIntent {
        $intent = match (true) {
            $resume  => BuildIntent::resume($budget),
            $restart => BuildIntent::restart($totalCount, $budget),
            default  => BuildIntent::fresh($totalCount, $budget),
        };

        return ($partial && !$resume) ? $intent->withPartialScope() : $intent;
    }
}
