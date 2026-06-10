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
    private readonly int $limitBytes;
    private readonly float $buildStartTime;
    private readonly bool $canReadRss;
    /** @var \Closure(): int */
    private readonly \Closure $getCurrentMemory;
    /** @var \Closure(): int */
    private readonly \Closure $getPeakMemory;

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
            'limit_mb'   => round($this->limitBytes / 1_048_576, 1),
            'limit_pct'  => $pct,
            'source'     => $this->canReadRss ? 'rss' : 'php',
        ], $extra);

        if ($pct >= 90.0 && $this->limitBytes > 0) {
            $this->logger->error(
                '[scolta] Memory at {limit_pct}% of limit ({current_mb} MB RSS, limit {limit_mb} MB) at phase {phase} (+{elapsed_s}s). Aborting.',
                $context
            );
            throw new MemoryThresholdExceededException(
                "Memory usage ({$pct}% of {$context['limit_mb']} MB limit) exceeds safe threshold at phase '{$phase}'. "
                . 'Use --memory-budget=conservative or reduce chunk size.'
            );
        }

        if ($pct >= 75.0 && $this->limitBytes > 0) {
            $this->logger->warning(
                '[scolta] Memory at {limit_pct}% of limit ({current_mb} MB RSS) at phase {phase} (+{elapsed_s}s).',
                $context
            );
        } else {
            $this->logger->info(
                '[scolta] Phase {phase}: {peak_mb} MB peak ({limit_pct}% of limit, source: {source}) +{elapsed_s}s.',
                $context
            );
        }
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
