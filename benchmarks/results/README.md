# Benchmark Results

This directory stores historical benchmark JSON files produced by `scripts/benchmark.php`.

## Filename convention

```
YYYY-MM-DD-<sha>.json
```

Example: `2026-04-14-a1b2c3d.json`

Files are kept in git to enable historical performance tracking.

## Regenerating BENCHMARKS-LATEST.md

After running the benchmark, regenerate the markdown summary:

```bash
php -d error_reporting=E_ALL~E_DEPRECATED scripts/benchmark.php --sizes=100,1000,10000,50000
php scripts/generate-benchmarks-md.php
```

The script reads the most recent file in this directory (sorted alphabetically by filename) and writes `docs/BENCHMARKS-LATEST.md`.

## JSON schema

Each file contains:

```json
{
  "version": "0.2.1-dev",
  "git_sha": "a1b2c3d",
  "timestamp": "2026-04-14T12:00:00Z",
  "environment": {
    "php_version": "8.3.x",
    "os": "Darwin",
    "cpu_model": "Apple M2",
    "cpu_cores": 8,
    "ram_gb": 16
  },
  "runs": [
    {
      "pages": 1000,
      "wall_clock_seconds": 0.042,
      "peak_memory_mb": 12.3,
      "pages_per_second": 23810.0,
      "raw_runs": [0.041, 0.042, 0.043],
      "breakdown": {
        "tokenization_ms": 0.0,
        "stemming_ms": 0.0,
        "cbor_encoding_ms": 0.0,
        "gzip_ms": 0.0
      }
    }
  ]
}
```

`wall_clock_seconds` is the **median** of the three raw runs.
`breakdown` fields are reserved for future phase-level timing; currently always 0.0.
