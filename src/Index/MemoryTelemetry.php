<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Tag1\Scolta\Exception\MemoryThresholdExceededException;

/**
 * Emits PSR-3 memory-usage events at build phase boundaries.
 *
 * Each event includes elapsed wall-clock seconds since the telemetry object
 * was constructed. With a logger wired to WP-CLI/Drush --debug output, this
 * lets operators see exactly which phase is slow without a profiler.
 *
 * Measures actual RSS (Resident Set Size) from the OS when available, falling
 * back to PHP's memory_get_usage(true) when /proc/self/status is unavailable.
 * RSS is the accurate measure for OOM risk — it includes extension allocations,
 * shared library pages, and process overhead that PHP's allocator doesn't track.
 *
 * Also reads cgroup v2/v1 memory limits (containerised/shared hosting) so the
 * effective ceiling is the lower of PHP's memory_limit and the container limit.
 *
 * Warns at 75% of the effective memory limit, aborts at 90%.
 */
final class MemoryTelemetry
{
    /** Bucket that collects time spent before the first emit() call. */
    private const STARTUP_BUCKET = 'startup';

    private readonly int $limitBytes;
    private readonly float $buildStartTime;
    private readonly bool $canReadRss;
    /** @var \Closure(): int */
    private readonly \Closure $getCurrentMemory;
    /** @var \Closure(): int */
    private readonly \Closure $getPeakMemory;

    /**
     * Wall-clock accumulator, bucket name => {seconds, calls, items}.
     *
     * @var array<string, array{seconds: float, calls: int, items: int}>
     */
    private array $phaseTotals = [];

    /**
     * Sub-phase timers, name => {seconds, calls, items}.
     *
     * Spans between emit() calls cannot separate work that interleaves inside
     * one span — on a real corpus 98% of the chunk loop lands between
     * `chunk_committed(N)` and `chunk_start(N+1)`, which is gather, tokenize
     * and GC together. These are accumulated by explicit hrtime() pairs at the
     * call sites instead, and reported separately so they never double-count
     * against the span totals.
     *
     * @var array<string, array{seconds: float, calls: int, items: int}>
     */
    private array $subTimers = [];

    /** Time of the most recent emit(), or construction time before the first. */
    private float $lastEmitTime;

    /** Bucket the currently-open span is attributed to. */
    private string $openBucket = self::STARTUP_BUCKET;

    /**
     * @param \Closure(): int|null $getCurrentMemory Injectable for testing; defaults to RSS or memory_get_usage(true).
     * @param \Closure(): int|null $getPeakMemory    Injectable for testing; defaults to RSS peak or memory_get_peak_usage(true).
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MemoryBudget $budget,
        ?\Closure $getCurrentMemory = null,
        ?\Closure $getPeakMemory = null,
    ) {
        $this->limitBytes     = self::effectiveMemoryLimit();
        $this->buildStartTime = microtime(true);
        $this->lastEmitTime   = $this->buildStartTime;

        // Detect /proc availability once at construction — used in default closures.
        $canReadRss       = is_readable('/proc/self/status');
        $this->canReadRss = $canReadRss;

        // Default closures read actual RSS from /proc when available, falling back
        // to PHP's allocator-reported memory. Injected closures (tests) bypass this.
        $this->getCurrentMemory = $getCurrentMemory ?? static function () use ($canReadRss) {
            if ($canReadRss && ($rss = self::readProcRss()) !== null) {
                return $rss;
            }
            return memory_get_usage(true);
        };
        $this->getPeakMemory = $getPeakMemory ?? static function () use ($canReadRss) {
            if ($canReadRss && ($peak = self::readProcPeakRss()) !== null) {
                return $peak;
            }
            return memory_get_peak_usage(true);
        };
    }

    /**
     * Record a telemetry event for a named build phase.
     *
     * @throws MemoryThresholdExceededException When memory usage exceeds 90% of the effective limit.
     * @since 1.0.0
     * @stability stable
     */
    public function emit(string $phase, array $extra = []): void
    {
        $now = microtime(true);
        // Close the span opened by the previous emit() before anything else,
        // so an abort at the 90% threshold still accounts for the time that
        // led up to it. Spans are attributed to the phase that opened them.
        $this->closeSpan($now, $phase, $extra);

        $current   = ($this->getCurrentMemory)();
        $peak      = ($this->getPeakMemory)();
        $pct       = $this->limitBytes > 0
            ? round($current / $this->limitBytes * 100, 1)
            : 0.0;
        $elapsed   = round($now - $this->buildStartTime, 2);

        $context = array_merge([
            'phase'      => $phase,
            'elapsed_s'  => $elapsed,
            'current_mb' => round($current / 1_048_576, 1),
            'peak_mb'    => round($peak / 1_048_576, 1),
            'budget_mb'  => round($this->budget->totalBudgetBytes() / 1_048_576, 1),
            'limit_mb'   => round($this->limitBytes / 1_048_576, 1),
            'limit_pct'  => $pct,
            'source'     => $this->canReadRss ? 'rss' : 'php',
        ], $extra);

        if ($pct >= 90.0 && $this->limitBytes > 0) {
            $this->logger->error(
                '[scolta] Memory at {limit_pct}% of limit ({current_mb} MB RSS, limit {limit_mb} MB) at phase {phase} (+{elapsed_s}s). Aborting.',
                $context,
            );
            throw new MemoryThresholdExceededException(
                "Memory usage ({$pct}% of {$context['limit_mb']} MB limit) exceeds safe threshold at phase '{$phase}'. "
                . 'Use --memory-budget=conservative or reduce chunk size.',
            );
        }

        if ($pct >= 75.0 && $this->limitBytes > 0) {
            $this->logger->warning(
                '[scolta] Memory at {limit_pct}% of limit ({current_mb} MB RSS) at phase {phase} (+{elapsed_s}s).',
                $context,
            );
        } else {
            $this->logger->info(
                '[scolta] Phase {phase}: {peak_mb} MB peak ({limit_pct}% of limit, source: {source}) +{elapsed_s}s.',
                $context,
            );
        }
    }

    /**
     * Per-phase wall-clock totals for the build so far.
     *
     * `elapsed_s` on an individual event is time since construction, so
     * attributing a span means subtracting adjacent lines — unworkable across
     * the thousands of per-chunk lines a large build emits. This accumulates
     * it instead: the interval between two emit() calls is charged to the
     * phase that opened it, and phases are bucketed by name with any
     * `(N)` suffix stripped, so `chunk_start(0)` … `chunk_start(2186)` roll
     * up into one `chunk_start` row.
     *
     * The open span is included, measured to now, so the summary is complete
     * whether or not a closing phase was emitted.
     *
     * @return array<string, array{seconds: float, calls: int, items: int, pct: float}>
     *         Ordered by seconds descending.
     * @since 1.1.1
     * @stability experimental
     */
    public function phaseSummary(): array
    {
        $totals = $this->phaseTotals;

        // Fold in the span that is still open so the rows sum to the build.
        $openSeconds = microtime(true) - $this->lastEmitTime;
        $bucket      = self::bucketName($this->openBucket);
        $totals[$bucket] ??= ['seconds' => 0.0, 'calls' => 0, 'items' => 0];
        $totals[$bucket]['seconds'] += $openSeconds;

        $wall = array_sum(array_column($totals, 'seconds'));

        foreach ($totals as $name => $row) {
            $totals[$name]['seconds'] = round($row['seconds'], 3);
            $totals[$name]['pct']     = $wall > 0.0 ? round($row['seconds'] / $wall * 100, 1) : 0.0;
        }

        uasort($totals, static fn(array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $totals;
    }

    /**
     * Accumulate one sub-phase measurement.
     *
     * The caller owns the hrtime() pair; this only adds it up. Kept free of
     * any clock read of its own so wrapping a hot per-page call costs one
     * array write.
     *
     * @param string $name    Sub-timer name, e.g. 'tokenize' or 'gc'.
     * @param float  $seconds Elapsed seconds for this occurrence.
     * @param int    $items   Items covered, for a rate in the summary.
     * @since 1.1.1
     * @stability experimental
     */
    public function recordSubTimer(string $name, float $seconds, int $items = 0): void
    {
        $this->subTimers[$name] ??= ['seconds' => 0.0, 'calls' => 0, 'items' => 0];
        $this->subTimers[$name]['seconds'] += $seconds;
        $this->subTimers[$name]['calls']++;
        $this->subTimers[$name]['items'] += $items;
    }

    /**
     * Accumulated sub-phase timers, ordered by seconds descending.
     *
     * These overlap the span totals from phaseSummary() by construction —
     * they measure work happening inside a span, not alongside it — so the
     * two sets must never be added together.
     *
     * @return array<string, array{seconds: float, calls: int, items: int}>
     * @since 1.1.1
     * @stability experimental
     */
    public function subTimers(): array
    {
        $timers = $this->subTimers;
        foreach ($timers as $name => $row) {
            $timers[$name]['seconds'] = round($row['seconds'], 3);
        }
        uasort($timers, static fn(array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $timers;
    }

    /**
     * Log the phase summary as a single line.
     *
     * Callers run this once at the end of a build. The human-readable message
     * carries the ranked breakdown; the `phases` context key carries the same
     * data structured, for log processors.
     *
     * @since 1.1.1
     * @stability experimental
     */
    public function emitPhaseSummary(): void
    {
        $summary = $this->phaseSummary();

        $parts = [];
        foreach ($summary as $name => $row) {
            $part = sprintf(
                '%s %.1fs (%.1f%%, %d call%s',
                $name,
                $row['seconds'],
                $row['pct'],
                $row['calls'],
                $row['calls'] === 1 ? '' : 's',
            );
            if ($row['items'] > 0) {
                $rate  = $row['seconds'] > 0.0 ? $row['items'] / $row['seconds'] : 0.0;
                $part .= sprintf(', %d items, %.1f/s', $row['items'], $rate);
            }
            $parts[] = $part . ')';
        }

        $subTimers = $this->subTimers();
        $subParts  = [];
        foreach ($subTimers as $name => $row) {
            $rate = $row['items'] > 0 && $row['seconds'] > 0.0
                ? sprintf(', %.2f ms/item', $row['seconds'] / $row['items'] * 1000)
                : '';
            $subParts[] = sprintf('%s %.1fs (%d calls%s)', $name, $row['seconds'], $row['calls'], $rate);
        }

        $this->logger->info(
            '[scolta] Phase summary ({total_s}s total): {breakdown}',
            [
                'total_s'   => round(array_sum(array_column($summary, 'seconds')), 2),
                'breakdown' => implode('; ', $parts),
                'phases'    => $summary,
            ],
        );

        if ($subParts !== []) {
            $this->logger->info(
                '[scolta] Sub-timers (inside the spans above, not additive with them): {breakdown}',
                [
                    'breakdown' => implode('; ', $subParts),
                    'timers'    => $subTimers,
                ],
            );
        }
    }

    /**
     * Charge the interval since the last emit() to the phase that opened it,
     * then open a new span for $nextPhase.
     */
    /** @param array<string, mixed> $extra */
    private function closeSpan(float $now, string $nextPhase, array $extra): void
    {
        $bucket = self::bucketName($this->openBucket);
        $this->phaseTotals[$bucket] ??= ['seconds' => 0.0, 'calls' => 0, 'items' => 0];
        $this->phaseTotals[$bucket]['seconds'] += $now - $this->lastEmitTime;

        // Calls and items belong to the phase being emitted, not the one whose
        // span just closed: `chunk_committed(7)` reports the pages that chunk
        // held, and the reader expects that count on the chunk_committed row.
        $emitted = self::bucketName($nextPhase);
        $this->phaseTotals[$emitted] ??= ['seconds' => 0.0, 'calls' => 0, 'items' => 0];
        $this->phaseTotals[$emitted]['calls']++;
        if (isset($extra['items']) && is_int($extra['items'])) {
            $this->phaseTotals[$emitted]['items'] += $extra['items'];
        }

        $this->lastEmitTime = $now;
        $this->openBucket   = $nextPhase;
    }

    /**
     * Strip a trailing `(...)` discriminator so per-iteration phases roll up.
     */
    private static function bucketName(string $phase): string
    {
        $paren = strpos($phase, '(');

        return $paren === false ? $phase : substr($phase, 0, $paren);
    }

    /**
     * Get the current RSS in bytes.
     *
     * Uses the same measurement as emit() — actual RSS on Linux, PHP allocator
     * on macOS/Windows, or injected value when a closure was provided to the
     * constructor (test scenario). Suitable for StatusReport construction.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function getCurrentRssBytes(): int
    {
        return ($this->getCurrentMemory)();
    }

    /**
     * Get the peak RSS in bytes (VmHWM on Linux, PHP peak on macOS/Windows).
     *
     * Suitable for StatusReport construction — matches what emit() would report
     * for peak_mb.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function getPeakRssBytes(): int
    {
        return ($this->getPeakMemory)();
    }

    /**
     * Return the effective memory limit in bytes.
     *
     * This is the lower of PHP's memory_limit and any cgroup memory limit.
     * Returns 0 when no limit is detectable (unlimited or unknown).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function effectiveLimitBytes(): int
    {
        return $this->limitBytes;
    }

    /**
     * Parse VmRSS from /proc/self/status (current RSS).
     */
    private static function readProcRss(): ?int
    {
        $content = @file_get_contents('/proc/self/status');
        if ($content === false) {
            return null;
        }
        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $content, $m)) {
            return (int) $m[1] * 1024;
        }

        return null;
    }

    /**
     * Parse VmHWM (peak RSS high-water mark) from /proc/self/status.
     */
    private static function readProcPeakRss(): ?int
    {
        $content = @file_get_contents('/proc/self/status');
        if ($content === false) {
            return null;
        }
        if (preg_match('/VmHWM:\s+(\d+)\s+kB/', $content, $m)) {
            return (int) $m[1] * 1024;
        }

        return null;
    }

    /**
     * Effective memory limit: the lower of PHP memory_limit and cgroup limit.
     *
     * On containerised/shared hosting, the cgroup limit is often lower than
     * memory_limit. Either one can SIGKILL the process, so we use the minimum.
     * Returns 0 when no finite limit is detectable (disables threshold checks).
     */
    private static function effectiveMemoryLimit(): int
    {
        $phpLimit    = self::parseMemoryLimit();
        $cgroupLimit = self::readCgroupMemoryLimit();

        if ($phpLimit <= 0 && $cgroupLimit <= 0) {
            return 0;
        }
        if ($phpLimit <= 0) {
            return $cgroupLimit;
        }
        if ($cgroupLimit <= 0) {
            return $phpLimit;
        }

        return min($phpLimit, $cgroupLimit);
    }

    /**
     * Read cgroup v2 memory limit, with v1 fallback.
     *
     * Returns 0 if not in a cgroup, file unreadable, or limit is "max"/unlimited.
     */
    private static function readCgroupMemoryLimit(): int
    {
        // cgroup v2
        $v2 = @file_get_contents('/sys/fs/cgroup/memory.max');
        if ($v2 !== false) {
            $v2 = trim($v2);
            if ($v2 !== 'max' && is_numeric($v2)) {
                return (int) $v2;
            }
            return 0;
        }

        // cgroup v1
        $v1 = @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        if ($v1 !== false) {
            $val = (int) trim($v1);
            // cgroup v1 uses a very large sentinel value for "unlimited".
            if ($val > 0 && $val < 1_099_511_627_776) {
                return $val;
            }
        }

        return 0;
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
