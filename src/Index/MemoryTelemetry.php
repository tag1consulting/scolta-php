<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;

/**
 * Emits PSR-3 memory-usage events at build phase boundaries.
 *
 * Each event includes elapsed wall-clock seconds since the telemetry object
 * was constructed. With a logger wired to WP-CLI/Drush --debug output, this
 * lets operators see exactly which phase is slow without a profiler.
 *
 * Warns at 75% of current live RSS, aborts at 90%. Thresholds use
 * memory_get_usage() (live) not memory_get_peak_usage() (monotonic) so that
 * large-corpus builds with many small chunks don't abort when past-chunk peaks
 * accumulate above the threshold while current live RSS is well within bounds.
 */
final class MemoryTelemetry
{
    private readonly int $limitBytes;
    private readonly float $buildStartTime;
    /** @var \Closure(): int */
    private readonly \Closure $getCurrentMemory;
    /** @var \Closure(): int */
    private readonly \Closure $getPeakMemory;

    /**
     * @param \Closure(): int|null $getCurrentMemory Injectable for testing; defaults to memory_get_usage(true).
     * @param \Closure(): int|null $getPeakMemory    Injectable for testing; defaults to memory_get_peak_usage(true).
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MemoryBudget $budget,
        ?\Closure $getCurrentMemory = null,
        ?\Closure $getPeakMemory = null,
    ) {
        $this->limitBytes       = self::parseMemoryLimit();
        $this->buildStartTime   = microtime(true);
        $this->getCurrentMemory = $getCurrentMemory ?? static fn () => memory_get_usage(true);
        $this->getPeakMemory    = $getPeakMemory    ?? static fn () => memory_get_peak_usage(true);
    }

    /**
     * Record a telemetry event for a named build phase.
     *
     * @throws \RuntimeException When current live memory exceeds 90% of PHP memory_limit.
     */
    public function emit(string $phase, array $extra = []): void
    {
        $current   = ($this->getCurrentMemory)();
        $peak      = ($this->getPeakMemory)();
        $pct       = $this->limitBytes > 0
            ? round($current / $this->limitBytes * 100, 1)
            : 0.0;
        $elapsed   = round(microtime(true) - $this->buildStartTime, 2);

        $context = array_merge([
            'phase'      => $phase,
            'elapsed_s'  => $elapsed,
            'current_mb' => round($current / 1_048_576, 1),
            'peak_mb'    => round($peak / 1_048_576, 1),
            'budget_mb'  => round($this->budget->totalBudgetBytes() / 1_048_576, 1),
            'limit_pct'  => $pct,
        ], $extra);

        if ($pct >= 90.0 && $this->limitBytes > 0) {
            $this->logger->error(
                '[scolta] Memory at {limit_pct}% of PHP memory_limit at phase {phase} (+{elapsed_s}s). Aborting.',
                $context
            );
            throw new \RuntimeException(
                "Memory usage ({$pct}% of PHP memory_limit) exceeds safe threshold at phase '{$phase}'. "
                . 'Use --memory-budget=conservative or reduce chunk size.'
            );
        }

        if ($pct >= 75.0 && $this->limitBytes > 0) {
            $this->logger->warning(
                '[scolta] Memory at {limit_pct}% of PHP memory_limit at phase {phase} (+{elapsed_s}s).',
                $context
            );
        } else {
            $this->logger->info(
                '[scolta] Phase {phase}: {peak_mb} MB peak ({limit_pct}% of limit) +{elapsed_s}s.',
                $context
            );
        }
    }

    private static function parseMemoryLimit(): int
    {
        $raw = ini_get('memory_limit');
        if ($raw === false || $raw === '' || $raw === '-1') {
            return 0;
        }

        $raw  = trim($raw);
        $num  = (int) $raw;
        $unit = strtolower(substr($raw, -1));

        return match ($unit) {
            'g'     => $num * 1_073_741_824,
            'm'     => $num * 1_048_576,
            'k'     => $num * 1_024,
            default => is_numeric($raw) ? (int) $raw : 0,
        };
    }
}
