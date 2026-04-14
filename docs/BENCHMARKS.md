# PHP Indexer Performance Benchmarks

Run `php scripts/benchmark.php` to generate results on your machine.

## Results

> The table below is a placeholder. Run the benchmark script to get results for your environment.

```
PHP Indexer Throughput Benchmark
---------------------------------------------------------
 Pages   | Time (s)   | Pages/sec   | Memory (MB)
---------------------------------------------------------
     100 |      —     |      —      |      —
    1000 |      —     |      —      |      —
   10000 |      —     |      —      |      —
   50000 |      —     |      —      |      —
---------------------------------------------------------
```

Run the benchmark:

```bash
php scripts/benchmark.php
# Or for specific sizes:
php scripts/benchmark.php --sizes=100,1000,10000
```

## Methodology

The benchmark measures the end-to-end wall-clock time for:

1. **`PhpIndexer::processChunk()`** — tokenization, stemming, inverted index construction.
2. **`PhpIndexer::finalize()`** — CBOR serialization, gzip compression, fragment/index/meta/filter file writing.

### What is NOT measured

- Content-item generation (synthetic items are pre-created before timing starts).
- Disk I/O latency beyond what `finalize()` actually incurs.

### Measurement details

- Wall-clock time via `hrtime(true)` (nanosecond precision, monotonic clock).
- Peak memory via `memory_get_peak_usage(true)` minus baseline before the run.
- Each size is run once; for stable numbers, run 3× and take the median.

### Synthetic corpus

Each page is generated with 50–200 words drawn from a ~150-word pool of realistic
search/technical vocabulary. No disk reads are performed during generation.

## Optimization Notes

Key hot paths identified during profiling:

1. **Stemmer** — `Stemmer::stem()` is called for every token on every page. The
   stemmer caches results internally; warm-cache throughput is significantly higher
   than cold-cache.

2. **CBOR serialization** (`InvertedIndexWriter`) — integer delta-encoding of page
   numbers is CPU-bound; avoid redundant array copies.

3. **gzip compression** — `gzencode()` at default level (6) accounts for a large
   fraction of `finalize()` time at high page counts. Level 1 offers 2–3× speedup
   with minimal size increase.

4. **Fragment file writes** — one `file_put_contents()` per page; batching or
   async I/O would improve throughput on slow disks.
