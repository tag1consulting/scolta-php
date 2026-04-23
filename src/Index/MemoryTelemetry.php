<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;

/**
 * Emits PSR-3 memory-usage events at build phase boundaries.
 *
 * Warns at 75% of PHP memory_limit, aborts at 90%.
 */
final class MemoryTelemetry
{
    private readonly int $limitBytes;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MemoryBudget $budget,
    ) {
        $this->limitBytes = self::parseMemoryLimit();
    }

    /**
     * Record a telemetry event for a named build phase.
     *
     * @throws \RuntimeException When memory usage exceeds 90% of PHP memory_limit.
     */
    public function emit(string $phase, array $extra = []): void
    {
        $current = memory_get_usage(true);
        $peak    = memory_get_peak_usage(true);
        $pct     = $this->limitBytes > 0
            ? round($peak / $this->limitBytes * 100, 1)
            : 0.0;

        $context = array_merge([
            'phase'      => $phase,
            'current_mb' => round($current / 1_048_576, 1),
            'peak_mb'    => round($peak / 1_048_576, 1),
            'budget_mb'  => round($this->budget->totalBudgetBytes() / 1_048_576, 1),
            'limit_pct'  => $pct,
        ], $extra);

        if ($pct >= 90.0 && $this->limitBytes > 0) {
            $this->logger->error(
                '[scolta] Memory at {limit_pct}% of PHP memory_limit at phase {phase}. Aborting.',
                $context
            );
            throw new \RuntimeException(
                "Memory usage ({$pct}% of PHP memory_limit) exceeds safe threshold at phase '{$phase}'. "
                . 'Use --memory-budget=conservative or reduce chunk size.'
            );
        }

        if ($pct >= 75.0 && $this->limitBytes > 0) {
            $this->logger->warning(
                '[scolta] Memory at {limit_pct}% of PHP memory_limit at phase {phase}.',
                $context
            );
        } else {
            $this->logger->info(
                '[scolta] Phase {phase}: {peak_mb} MB peak ({limit_pct}% of limit).',
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
