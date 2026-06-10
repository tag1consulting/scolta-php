<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * No-op progress reporter for headless and test contexts.
 */
final class NullProgressReporter implements ProgressReporterInterface
{
    /**
     * @since 1.0.0
     * @stability stable
     */
    public function start(int $totalSteps, string $label): void {}

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function advance(int $steps = 1, ?string $detail = null): void {}

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function finish(?string $summary = null): void {}
}
