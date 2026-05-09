<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Configurable memory budget that shapes chunk sizes and flush thresholds.
 *
 * The budget is advisory: it tunes chunk sizes, flush thresholds, and fan-in
 * limits without enforcing a hard cap on peak RSS. The streaming pipeline is
 * already O(1) in corpus size; the budget controls how aggressively memory is
 * traded for fewer round trips and larger buffers.
 *
 * **Internal budget vs total process RSS.** The values here (totalBudgetBytes,
 * fragmentFlushBytes, etc.) describe Scolta's own allocation during indexing —
 * the memory Scolta adds on top of whatever the PHP process already uses.
 * Total process RSS = PHP runtime baseline + Scolta allocation + I/O overhead.
 * Typical baselines: Laravel CLI ~60 MB, WordPress ~80 MB, Drupal ~130 MB.
 * Add the profile's totalBudgetBytes and ~15 MB I/O overhead to estimate total
 * process RSS. The conservative profile's 96 MB internal budget therefore
 * results in roughly 170 MB total RSS on WordPress or 240 MB on Drupal.
 *
 * The runtime default is always conservative(). Larger profiles are opt-in
 * only — Scolta never auto-selects a larger profile at runtime.
 */
final class MemoryBudget
{
    private function __construct(
        private readonly string $profile,
        private readonly int $chunkSize,
        private readonly int $fragmentFlushBytes,
        private readonly int $wordIndexChunkBytes,
        private readonly int $mergeOpenFileHandles,
        private readonly int $totalBudgetBytes,
        private readonly int $tokenCacheChunkBytes,
    ) {
    }

    /**
     * Conservative: safe for shared hosts with PHP memory_limit ≤ 128 MB.
     *
     * Scolta's internal allocation budget: 96 MB. This is the runtime default.
     * Total process RSS will be higher — add the PHP runtime baseline for your
     * platform (Laravel CLI ~60 MB, WordPress ~80 MB, Drupal ~130 MB) plus ~15 MB
     * I/O overhead. The 4 MB token-cache chunk limit prevents single serialize()
     * allocations from exhausting memory when pages contain thousands of tokens.
     */
    public static function conservative(): self
    {
        return new self(
            profile: 'conservative',
            chunkSize: 50,
            fragmentFlushBytes: 40_000,
            wordIndexChunkBytes: 40_000,
            mergeOpenFileHandles: 50,
            totalBudgetBytes: 96 * 1024 * 1024,
            tokenCacheChunkBytes: 4 * 1024 * 1024,
        );
    }

    /**
     * Balanced: ~256–512 MB available. Larger chunks, bigger buffers.
     */
    public static function balanced(): self
    {
        return new self(
            profile: 'balanced',
            chunkSize: 200,
            fragmentFlushBytes: 160_000,
            wordIndexChunkBytes: 160_000,
            mergeOpenFileHandles: 200,
            totalBudgetBytes: 384 * 1024 * 1024,
            tokenCacheChunkBytes: 16 * 1024 * 1024,
        );
    }

    /**
     * Aggressive: ≥ 1 GB available. Maximises throughput.
     */
    public static function aggressive(): self
    {
        return new self(
            profile: 'aggressive',
            chunkSize: 500,
            fragmentFlushBytes: 512_000,
            wordIndexChunkBytes: 512_000,
            mergeOpenFileHandles: 500,
            totalBudgetBytes: 1024 * 1024 * 1024,
            tokenCacheChunkBytes: 64 * 1024 * 1024,
        );
    }

    /**
     * Build a budget from a raw byte value, routing to the nearest named profile.
     */
    public static function fromBytes(int $bytes): self
    {
        if ($bytes >= 768 * 1024 * 1024) {
            return self::aggressive();
        }
        if ($bytes >= 192 * 1024 * 1024) {
            return self::balanced();
        }

        return self::conservative();
    }

    /**
     * Parse a CLI/config string such as "conservative", "balanced",
     * "aggressive", or a byte value like "256M".
     *
     * Returns conservative() if the string is unrecognised.
     */
    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'conservative' => self::conservative(),
            'balanced'     => self::balanced(),
            'aggressive'   => self::aggressive(),
            default        => self::fromBytes(self::parseByteString($value)),
        };
    }

    /**
     * The runtime default. Always conservative(). Framework adapters call this
     * when no --memory-budget flag is present.
     */
    public static function default(): self
    {
        return self::conservative();
    }

    /**
     * Build a budget from a memory string and optional chunk size override.
     *
     * This is the single call site every framework adapter should use.
     * It encapsulates the three-tier precedence — explicit chunk size >
     * memory profile default — so adapters don't repeat the inline pattern.
     *
     * ```php
     * // Named profile, no chunk override
     * MemoryBudget::fromOptions('balanced');
     *
     * // Arbitrary byte string with explicit chunk size
     * MemoryBudget::fromOptions('256M', 100);
     * ```
     *
     * @param string   $memoryBudget Profile name ("conservative") or byte string ("256M").
     * @param int|null $chunkSize    Pages per chunk, or null to use the profile default.
     * @since 0.3.2
     * @stability experimental
     */
    public static function fromOptions(string $memoryBudget = 'conservative', ?int $chunkSize = null): self
    {
        $budget = self::fromString($memoryBudget);
        if ($chunkSize !== null && $chunkSize >= 1) {
            return $budget->withChunkSize($chunkSize);
        }

        return $budget;
    }

    /**
     * Return a copy of this budget with the chunk size overridden.
     *
     * Use this when the admin or CLI specifies a chunk size independently of
     * the memory profile — e.g., `--chunk-size=100`. The merge open-file-handle
     * cap is adjusted upward to match the new chunk size when necessary, since
     * the pre-merge pass fan-in limit should be at least as large as one chunk.
     *
     * @param positive-int $chunkSize Pages per chunk (must be ≥ 1).
     * @since 0.3.2
     * @stability experimental
     */
    public function withChunkSize(int $chunkSize): self
    {
        return new self(
            profile: $this->profile,
            chunkSize: $chunkSize,
            fragmentFlushBytes: $this->fragmentFlushBytes,
            wordIndexChunkBytes: $this->wordIndexChunkBytes,
            mergeOpenFileHandles: max($chunkSize, $this->mergeOpenFileHandles),
            totalBudgetBytes: $this->totalBudgetBytes,
            tokenCacheChunkBytes: $this->tokenCacheChunkBytes,
        );
    }

    /** Pages per chunk. */
    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    /** StreamingFormatWriter flush threshold (bytes). */
    public function fragmentFlushBytes(): int
    {
        return $this->fragmentFlushBytes;
    }

    /** Word-index chunk size (bytes). */
    public function wordIndexChunkBytes(): int
    {
        return $this->wordIndexChunkBytes;
    }

    /**
     * Soft cap on simultaneously-open file handles during N-way merge.
     *
     * When chunk count exceeds this, IndexMerger::mergeStreaming() performs a
     * recursive pre-merge pass to reduce fan-in.
     */
    public function mergeOpenFileHandles(): int
    {
        return $this->mergeOpenFileHandles;
    }

    /** Total budget in bytes, used for diagnostics and telemetry warnings. */
    public function totalBudgetBytes(): int
    {
        return $this->totalBudgetBytes;
    }

    /**
     * Maximum bytes to buffer in PageWordCache before flushing to a chunk file.
     *
     * Bounds the serialization allocation for a single flush. Prevents OOM when
     * large pages (e.g. long Wikipedia articles with thousands of tokens) would
     * otherwise fill the write buffer with many megabytes before serialize() fires.
     *
     * @since 0.3.11
     * @stability experimental
     */
    public function tokenCacheChunkBytes(): int
    {
        return $this->tokenCacheChunkBytes;
    }

    /** Human-readable profile name: "conservative" | "balanced" | "aggressive". */
    public function profile(): string
    {
        return $this->profile;
    }

    private static function parseByteString(string $value): int
    {
        if ($value === '' || $value === '0') {
            return 0;
        }

        $num  = (int) $value;
        $unit = strtolower(substr(rtrim($value), -1));

        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => is_numeric($value) ? (int) $value : 0,
        };
    }
}
