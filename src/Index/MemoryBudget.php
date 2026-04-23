<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Configurable memory budget that shapes chunk sizes and flush thresholds.
 *
 * The budget is advisory: it tunes constants for throughput without enforcing
 * a hard cap on peak RSS. The streaming pipeline is already O(1) in corpus
 * size regardless of budget; the budget controls how aggressively memory is
 * traded for fewer round trips and larger buffers.
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
    ) {
    }

    /**
     * Conservative: safe for shared hosts with PHP memory_limit ≤ 128 MB.
     *
     * Peak RSS ≤ 96 MB for any corpus size. This is the runtime default.
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
