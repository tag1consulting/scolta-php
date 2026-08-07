<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Configurable memory budget that shapes chunk sizes and flush thresholds.
 *
 * The budget sizes chunk sizes, flush thresholds, fan-in limits and the token
 * cache's lookup manifest. It is not a hard cap on peak RSS — nothing here can
 * bound the framework's own allocation — but every structure it sizes is
 * bounded by it rather than by the corpus, and a budget that would not fit the
 * process is reduced to one that does instead of being allowed to fatal.
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
    ) {}

    /**
     * Conservative: the smallest of the three profiles, and the default.
     *
     * It is not a promise that a 128 MB host will finish a build. 96 MB of
     * Scolta allocation on top of a ~130 MB Drupal baseline does not fit in
     * 128 MB, and claiming it did sent operators into exactly the mid-build
     * memory failures the profile was supposed to avoid. On a limit this
     * small, {@see self::withCeiling()} cuts the budget down to fit and the
     * build runs slower; whether it completes depends on the corpus.
     *
     * Scolta's internal allocation budget: 96 MB. This is the runtime default.
     * Total process RSS will be higher — add the PHP runtime baseline for your
     * platform (Laravel CLI ~60 MB, WordPress ~80 MB, Drupal ~130 MB) plus ~15 MB
     * I/O overhead. The 4 MB token-cache chunk limit prevents single serialize()
     * allocations from exhausting memory when pages contain thousands of tokens.
     *
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
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
     * Smallest budget worth running: below this the buffers stop being buffers.
     */
    private const MIN_TOTAL_BYTES = 16 * 1024 * 1024;

    /**
     * Share of the process memory limit a clamped budget is cut back to.
     *
     * The rest is the framework baseline (Drupal ~130 MB, WordPress ~80 MB)
     * plus I/O overhead, which is why a clamped budget takes half the limit
     * rather than all of it.
     */
    private const CEILING_RATIO = 0.5;

    /**
     * Build a budget from a raw byte value.
     *
     * The named profile nearest the request supplies the *shape* — the ratios
     * between chunk size, flush thresholds and fan-in — and the request itself
     * supplies the size. This used to round the request to whichever profile
     * it landed nearest, so `--memory-budget=48M` silently ran with the
     * conservative profile's 96 MB and the operator's number went nowhere.
     *
     * @since 1.0.0
     * @stability stable
     */
    public static function fromBytes(int $bytes): self
    {
        $base = match (true) {
            $bytes >= 768 * 1024 * 1024 => self::aggressive(),
            $bytes >= 192 * 1024 * 1024 => self::balanced(),
            default                     => self::conservative(),
        };

        return $bytes > 0 ? $base->scaledTo($bytes) : $base;
    }

    /**
     * Return a copy sized to $totalBytes, keeping this profile's proportions.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function scaledTo(int $totalBytes): self
    {
        $totalBytes = max(self::MIN_TOTAL_BYTES, $totalBytes);
        if ($totalBytes === $this->totalBudgetBytes) {
            return $this;
        }

        $ratio = $totalBytes / $this->totalBudgetBytes;

        return new self(
            profile: $this->profile,
            chunkSize: max(10, (int) round($this->chunkSize * $ratio)),
            fragmentFlushBytes: max(8_000, (int) round($this->fragmentFlushBytes * $ratio)),
            wordIndexChunkBytes: max(8_000, (int) round($this->wordIndexChunkBytes * $ratio)),
            mergeOpenFileHandles: max(10, (int) round($this->mergeOpenFileHandles * $ratio)),
            totalBudgetBytes: $totalBytes,
            tokenCacheChunkBytes: max(1024 * 1024, (int) round($this->tokenCacheChunkBytes * $ratio)),
        );
    }

    /**
     * Return a copy that fits inside a process memory limit.
     *
     * A budget at or above the limit is not ambition, it is a crash: asking
     * for 4 GB in a 512 MB process selected the aggressive profile's 500-page
     * chunks and 64 MB token-cache flush, and one of those allocations fatals
     * in a single step, before the RSS watchdog gets its between-chunk turn to
     * abort cleanly. Asking for more than the process has now degrades to half
     * of what it has.
     *
     * A budget that merely sits below the limit is left alone: the profiles
     * describe Scolta's own allocation, the framework baseline is on top of
     * it, and second-guessing a deliberate choice here would quietly reshape
     * every default.
     *
     * @param int $processLimitBytes The effective limit, or 0 when unlimited.
     * @since 1.2.0
     * @stability experimental
     */
    public function withCeiling(int $processLimitBytes): self
    {
        if ($processLimitBytes <= 0 || $this->totalBudgetBytes < $processLimitBytes) {
            return $this;
        }

        return $this->scaledTo((int) ($processLimitBytes * self::CEILING_RATIO));
    }

    /**
     * Parse a CLI/config string such as "conservative", "balanced",
     * "aggressive", or a byte value like "256M".
     *
     * Returns conservative() if the string is unrecognised.
     *
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
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
     * The result is always capped to what the process can actually allocate,
     * so a budget copied from a bigger host degrades instead of fataling.
     *
     * @param string   $memoryBudget     Profile name ("conservative") or byte string ("256M").
     * @param int|null $chunkSize        Pages per chunk, or null to use the profile default.
     * @param int|null $processLimitBytes Effective process limit; detected from
     *                                    memory_limit when null, 0 for unlimited.
     * @since 0.3.2
     * @stability experimental
     */
    public static function fromOptions(
        string $memoryBudget = 'conservative',
        ?int $chunkSize = null,
        ?int $processLimitBytes = null,
    ): self {
        $budget = self::fromString($memoryBudget)
            ->withCeiling($processLimitBytes ?? self::detectProcessLimitBytes());

        if ($chunkSize !== null && $chunkSize >= 1) {
            return $budget->withChunkSize($chunkSize);
        }

        return $budget;
    }

    /**
     * The process memory limit in bytes, or 0 when there is none.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public static function detectProcessLimitBytes(): int
    {
        $raw = strtolower(trim((string) ini_get('memory_limit')));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        return self::parseByteString($raw);
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

    /**
     * Pages per chunk.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * StreamingFormatWriter flush threshold (bytes).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function fragmentFlushBytes(): int
    {
        return $this->fragmentFlushBytes;
    }

    /**
     * Word-index chunk size (bytes).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function wordIndexChunkBytes(): int
    {
        return $this->wordIndexChunkBytes;
    }

    /**
     * Soft cap on simultaneously-open file handles during N-way merge.
     *
     * When chunk count exceeds this, IndexMerger::mergeStreaming() performs a
     * recursive pre-merge pass to reduce fan-in.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function mergeOpenFileHandles(): int
    {
        return $this->mergeOpenFileHandles;
    }

    /**
     * Total budget in bytes, used for diagnostics and telemetry warnings.
     *
     * @since 1.0.0
     * @stability stable
     */
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

    /**
     * Maximum entries the token-cache lookup manifest may hold in memory.
     *
     * The manifest is the one part of the cache that is O(corpus) rather than
     * O(chunk): one hash per page, held for the whole build. Capping it is
     * what makes peak memory a function of the budget instead of the corpus.
     * Past the cap the cache stops admitting new pages, so a very large corpus
     * re-tokenizes its tail on every build — slower, never wrong.
     *
     * A quarter of the budget at roughly 96 bytes per entry (a 32-char key
     * plus PHP's hash-table slot), so the conservative profile still tracks
     * ~260k pages before it starts declining new ones.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function tokenCacheManifestEntries(): int
    {
        return max(1000, (int) ($this->totalBudgetBytes * 0.25 / 96));
    }

    /**
     * Human-readable profile name: "conservative" | "balanced" | "aggressive".
     *
     * @since 1.0.0
     * @stability stable
     */
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
