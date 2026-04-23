<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * No-op progress reporter for headless and test contexts.
 */
final class NullProgressReporter implements ProgressReporterInterface
{
    public function start(int $totalSteps, string $label): void
    {
    }

    public function advance(int $steps = 1, ?string $detail = null): void
    {
    }

    public function finish(?string $summary = null): void
    {
    }
}
