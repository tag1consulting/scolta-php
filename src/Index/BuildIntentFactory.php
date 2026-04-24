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
     *
     * @since     0.3.3
     * @stability experimental
     */
    public static function fromFlags(
        bool $resume,
        bool $restart,
        int $totalCount,
        MemoryBudget $budget,
    ): BuildIntent {
        return match (true) {
            $resume  => BuildIntent::resume($budget),
            $restart => BuildIntent::restart($totalCount, $budget),
            default  => BuildIntent::fresh($totalCount, $budget),
        };
    }
}
