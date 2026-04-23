<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Framework-provided progress callback.
 *
 * Framework adapters implement this interface using their native progress-bar
 * APIs (Artisan output, Drush IO, WP-CLI progress bar). IndexBuildOrchestrator
 * calls it at each chunk boundary so the user sees live feedback.
 *
 * Use NullProgressReporter for headless / test contexts.
 */
interface ProgressReporterInterface
{
    /** Called once before the first step. */
    public function start(int $totalSteps, string $label): void;

    /** Called after each completed step. */
    public function advance(int $steps = 1, ?string $detail = null): void;

    /** Called once when all steps are done. */
    public function finish(?string $summary = null): void;
}
