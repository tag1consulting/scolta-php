# PHP Indexer Performance Benchmarks

This document records the performance characteristics of the PHP indexer
(`Tag1\Scolta\Index\PhpIndexer`) across various corpus sizes. Numbers were
captured on a development machine; see [Methodology](#methodology) for how to
reproduce them.

## TL;DR

| Corpus size | Build time | Memory (peak) | Index size | Throughput |
|-------------|-----------|---------------|------------|------------|
| 100 pages   | ~0.85s    | ~14 MB        | ~0.2 MB    | ~8 ms/page |
| 1 K pages   | ~8–9s     | ~134 MB       | ~0.7 MB    | ~8 ms/page |
| 10 K pages  | ~85–90s   | ~500–800 MB   | ~3–5 MB    | ~8 ms/page |
| 50 K pages  | ~8–10 min | ~2+ GB        | ~15–25 MB  | ~8 ms/page |

**Key insight:** build time scales roughly linearly at ~8 ms per page regardless
of corpus size. Memory usage scales super-linearly because the in-memory inverted
index accumulates all word→page mappings before writing.

**Output size scales sub-linearly**: pagefind's compressed format reuses
vocabulary across pages, so 10× more pages produces roughly 3–5× more index
data (not 10×).

---

## Detailed Results

Results captured on an Apple M-series MacBook running PHP 8.5 with 8 GB RAM.
CI times will differ; see [Acceptance Targets](#acceptance-targets).

### 100-page corpus

```
[benchmark]   100 pages |  0.85s | 14.0MB peak | 0.2MB output | 8.5ms/page
```

- Content mix: 60% articles (~500 words), 25% short pages (~100 words),
  15% long guides (~1,500 words)
- All pages indexed within 1 second — well within interactive rebuild tolerance
- Memory is dominated by the PHP autoloader and stemmer initialization, not
  index size

### 1,000-page corpus

```
[benchmark]  1000 pages |  8.5s | 134.0MB peak | 0.7MB output | 8.5ms/page
```

- Linear scaling from 100-page baseline confirmed
- Memory grows substantially: each word entry in the inverted index adds
  overhead. Common words (stopwords) are not filtered (pagefind does its own
  post-processing)
- Output size (0.7 MB) fits comfortably in browser cache

### 10,000-page corpus

Estimated from the linear scaling observed at 100 and 1K pages:

| Metric         | Estimated  | Notes                                    |
|----------------|-----------|------------------------------------------|
| Build time     | ~85–90s   | Requires `memory_limit ≥ 512MB`          |
| Peak memory    | ~500–800MB | Dominated by in-memory inverted index    |
| Index size     | ~3–5MB    | Sub-linear due to shared vocabulary      |
| Throughput     | ~8ms/page | Stable per-page cost                     |

The in-memory merge phase is the bottleneck. `IndexMerger::merge()` calls
`array_unique()` on all page lists for every word in the vocabulary — for a
10K corpus this can take several GB at peak if vocabulary is large.

**Memory optimization opportunity:** The current implementation holds the
entire merged index in memory. A future version could stream-merge chunk files
on disk to reduce peak usage.

### 50,000-page corpus

- Requires `memory_limit ≥ 2GB`
- Estimated build time: 7–10 minutes
- Not recommended for automated CI (skipped unless `memory_limit ≥ 1GB`)
- For production sites this size, consider the pagefind binary indexer via
  `PagefindBinary` which uses a Rust implementation with a memory-mapped
  approach

---

## Acceptance Targets

The CI benchmark tests enforce these limits (generous to accommodate slow
runners):

| Corpus   | Time limit | Memory limit | Notes                              |
|----------|-----------|-------------|-------------------------------------|
| 100 pages  | 5s        | 32 MB       | Fast enough for local dev          |
| 1K pages   | 30s       | 256 MB      | Typical small site full rebuild    |
| 10K pages  | 300s (5 min) | 1 GB    | Medium site; needs `-d memory_limit=1G` |
| 50K pages  | 600s (10 min) | 2 GB   | Large site; skipped if memory < 1GB |

These limits are calibrated for **correctness verification**, not performance
regression. To detect regressions, compare the ms/page metric across runs.

---

## Incremental Rebuilds

The indexer supports content-fingerprint-based skip:

```php
$fingerprint = $indexer->shouldBuild($items);
if ($fingerprint !== null) {
    $indexer->processChunk($items, 0);
    $indexer->finalize();
    file_put_contents($outputDir . '/.scolta-state', $fingerprint);
}
```

When content has not changed since the last build:
- `shouldBuild()` returns `null` in microseconds (just a file hash comparison)
- No indexer work is performed
- The output directory is not touched

**Smart rebuild within a run:** Even when a full rebuild is needed, the indexer
caches per-page word lists keyed by content hash. Pages whose content has not
changed reuse the cached word list, skipping tokenization and stemming for
those pages. This makes incremental builds on large corpora significantly
faster in practice.

---

## Output Size Scaling

The pagefind format uses compression that exploits vocabulary sharing:

| Small corpus | Large corpus | Ratio | Expected linear |
|-------------|-------------|-------|-----------------|
| 100 pages / 0.2 MB | 1K pages / 0.7 MB | ~3.5× | 10× |

Index size grows at roughly 3–5× per 10× increase in pages. This is because:

1. **Vocabulary is largely shared** across pages on the same site. Adding 10×
   more pages does not add 10× more unique words.
2. **Fragment files** (per-word postings) are compressed. Longer posting lists
   compress better.
3. **Meta files** (per-page data) grow linearly, but they are a small fraction
   of total index size.

---

## Running Benchmarks

```bash
# Individual size checkpoints (fast)
./vendor/bin/phpunit tests/Benchmark/ --group benchmark --filter "100Pages|1kPages"

# All benchmarks (requires higher memory limit)
php -d memory_limit=2G ./vendor/bin/phpunit tests/Benchmark/ --group benchmark

# With formatted output table
php -d memory_limit=2G ./vendor/bin/phpunit tests/Benchmark/ --group benchmark 2>&1 | grep benchmark
```

The benchmark results are printed to `STDERR` so they appear even when PHPUnit
captures standard output.

---

## Methodology

**Corpus generation:** Synthetic content items with realistic word distributions.
Mix of 60% 500-word articles, 25% 100-word short pages, 15% 1,500-word guides.
Topics: PHP, Laravel, WordPress, Drupal, Search, AI, and 14 others. Content is
deterministic (seeded by item index).

**Measurement:**
- **Build time:** `microtime(true)` difference from first `processChunk()` call
  to `finalize()` return
- **Peak memory:** `memory_get_peak_usage(true)` delta from before first chunk
  to after finalize
- **Output size:** Recursive byte sum of the `pagefind/` output directory

**Environment (development machine):**
- Hardware: Apple M-series, 8 GB RAM
- OS: macOS 25.x
- PHP: 8.5.x with `intl` extension
- Note: CI runners (Ubuntu x86_64) typically show 2–3× slower per-page times

**Reproducibility:** Results vary by 5–10% between runs due to OS caching,
GC timing, and JIT warm-up. The acceptance targets include a 3–5× safety
margin above the measured baselines.
