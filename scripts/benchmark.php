<?php

declare(strict_types=1);

/**
 * Benchmark script: measures PHP indexer throughput.
 *
 * Usage:
 *   php scripts/benchmark.php
 *   php scripts/benchmark.php --sizes=100,1000
 *   php scripts/benchmark.php --json=/path/to/output.json
 *
 * Output: a table of pages/second, wall-clock time and memory.
 *         Optionally writes structured JSON to --json path.
 *         Each size is run 3 times; the median is reported.
 */

// Autoload.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}
require $autoload;

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------

$defaultSizes = [100, 1000, 10000, 50000];
$sizes = $defaultSizes;
$jsonPath = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sizes=')) {
        $raw = substr($arg, strlen('--sizes='));
        $parsed = array_map('intval', explode(',', $raw));
        $parsed = array_filter($parsed, fn (int $n) => $n > 0);
        if (!empty($parsed)) {
            $sizes = array_values($parsed);
        }
    } elseif (str_starts_with($arg, '--json=')) {
        $jsonPath = substr($arg, strlen('--json='));
    }
}

// Default JSON path if not specified.
if ($jsonPath === null) {
    $date = date('Y-m-d');
    $sha = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__ . '/..') . ' rev-parse --short HEAD 2>/dev/null')) ?: 'unknown';
    $resultsDir = __DIR__ . '/../benchmarks/results';
    $jsonPath = "{$resultsDir}/{$date}-{$sha}.json";
}

// ---------------------------------------------------------------------------
// Word pool for realistic synthetic content
// ---------------------------------------------------------------------------

$wordPool = explode(
    ' ',
    <<<WORDS
search engine index content page word query result relevance score ranking
algorithm inverted document frequency term token stem lemma language filter
metadata fragment header title body paragraph sentence phrase keyword boolean
operator wildcard facet autocomplete suggestion highlight snippet excerpt
web crawl scrape parse html text clean normalize tokenize analyze build
update delete insert merge chunk compress decompress encode decode hash
cache store retrieve lookup match compare sort order group aggregate count
function method class interface abstract implement extend override construct
database table column row join select insert update delete transaction commit
rollback index primary foreign constraint unique nullable default value type
string integer float boolean array object null void return throw catch finally
user admin role permission access control authentication authorization session
token cookie header request response status code error message success failure
network protocol server client socket stream buffer packet queue thread async
memory heap stack overflow underflow garbage collect allocate free pointer
file system directory path read write open close seek position cursor lock
time date timestamp duration interval timezone locale format parse convert
version release branch commit push pull merge conflict resolve rebase cherry
test unit integration functional regression performance benchmark coverage
WORDS
);

$numWords = count($wordPool);

// ---------------------------------------------------------------------------
// Synthetic content generator
// ---------------------------------------------------------------------------

function generateSyntheticItem(int $index, array $wordPool): ContentItem
{
    $numWords = count($wordPool);
    $seed = $index * 1337 + 42;

    // Generate 50-200 words.
    $wordCount = 50 + ($seed % 151);
    $words = [];
    for ($i = 0; $i < $wordCount; $i++) {
        $words[] = $wordPool[($seed * ($i + 1) * 31) % $numWords];
    }
    $body = '<p>' . implode(' ', $words) . '</p>';

    $titleWords = array_slice($words, 0, 5);
    $title = ucfirst(implode(' ', $titleWords));

    $month = str_pad((string) (($index % 12) + 1), 2, '0', STR_PAD_LEFT);
    $day = str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT);
    $date = "2026-{$month}-{$day}";

    return new ContentItem(
        id: "bench-page-{$index}",
        title: $title,
        bodyHtml: $body,
        url: "/bench/page-{$index}",
        date: $date,
    );
}

// ---------------------------------------------------------------------------
// Single benchmark run helper
// ---------------------------------------------------------------------------

/**
 * Run one benchmark pass for $n pages. Returns [wall_clock_sec, peak_mem_mb].
 */
function runBenchmarkPass(int $n, array $items, string $wordPool): array
{
    $stateDir = sys_get_temp_dir() . '/scolta-bench-state-' . uniqid();
    $outputDir = sys_get_temp_dir() . '/scolta-bench-output-' . uniqid();
    mkdir($stateDir, 0755, true);
    mkdir($outputDir, 0755, true);

    gc_collect_cycles();
    $memBefore = memory_get_usage(true);

    $start = hrtime(true);

    $indexer = new PhpIndexer($stateDir, $outputDir);
    $indexer->processChunk($items, 0);
    $result = $indexer->finalize();

    $elapsed = (hrtime(true) - $start) / 1e9;

    $memAfter = memory_get_peak_usage(true);
    $memMb = ($memAfter - $memBefore) / (1024 * 1024);

    if (!$result->success) {
        fwrite(STDERR, "WARNING: build failed for {$n} pages: " . ($result->error ?? 'unknown') . "\n");
    }

    // Clean up.
    $rmDir = function (string $dir) use (&$rmDir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    };
    $rmDir($stateDir);
    $rmDir($outputDir);

    return [$elapsed, $memMb];
}

// ---------------------------------------------------------------------------
// Environment detection
// ---------------------------------------------------------------------------

function detectEnvironment(): array
{
    $phpVersion = PHP_VERSION;
    $os = PHP_OS_FAMILY;

    $cpuModel = 'unknown';
    $cpuCores = 1;
    $ramGb = 0;

    if (file_exists('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m)) {
            $cpuModel = trim($m[1]);
        }
        $cpuCores = max(1, (int) shell_exec('nproc 2>/dev/null') ?: 1);
        $meminfo = file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) {
            $ramGb = round((int) $m[1] / (1024 * 1024), 1);
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $cpuModel = trim((string) shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null'));
        $cpuCores = (int) trim((string) shell_exec('sysctl -n hw.logicalcpu 2>/dev/null') ?: '1');
        $memBytes = (int) trim((string) shell_exec('sysctl -n hw.memsize 2>/dev/null') ?: '0');
        $ramGb = round($memBytes / (1024 * 1024 * 1024), 1);
    }

    return [
        'php_version' => $phpVersion,
        'os' => $os,
        'cpu_model' => $cpuModel ?: 'unknown',
        'cpu_cores' => $cpuCores,
        'ram_gb' => $ramGb,
    ];
}

// ---------------------------------------------------------------------------
// Run benchmarks
// ---------------------------------------------------------------------------

$colWidths = [7, 10, 11, 12];

$header = sprintf(
    ' %-' . $colWidths[0] . 's | %-' . $colWidths[1] . 's | %-' . $colWidths[2] . 's | %-' . $colWidths[3] . 's',
    'Pages',
    'Time (s)',
    'Pages/sec',
    'Memory (MB)'
);
$separator = str_repeat('-', strlen($header));

echo "\nPHP Indexer Throughput Benchmark\n";
echo $separator . "\n";
echo $header . "\n";
echo $separator . "\n";

$jsonRuns = [];

foreach ($sizes as $n) {
    // Pre-generate items before timing (exclude generation time).
    $items = [];
    for ($i = 0; $i < $n; $i++) {
        $items[] = generateSyntheticItem($i, $wordPool);
    }

    // Run 3 times, take the median wall-clock time.
    $rawTimes = [];
    $rawMems = [];
    for ($run = 0; $run < 3; $run++) {
        [$t, $mem] = runBenchmarkPass($n, $items, '');
        $rawTimes[] = $t;
        $rawMems[] = $mem;
    }

    sort($rawTimes);
    $elapsed = $rawTimes[1]; // Median of 3
    $memMb = max($rawMems);  // Peak across runs

    $pagesPerSec = $elapsed > 0 ? round($n / $elapsed, 1) : 0.0;

    echo sprintf(
        ' %' . $colWidths[0] . 'd | %' . $colWidths[1] . '.3f | %' . $colWidths[2] . 'd | %' . $colWidths[3] . '.1f',
        $n,
        $elapsed,
        (int) round($pagesPerSec),
        $memMb
    ) . "\n";

    $jsonRuns[] = [
        'pages' => $n,
        'wall_clock_seconds' => round($elapsed, 6),
        'peak_memory_mb' => round($memMb, 2),
        'pages_per_second' => $pagesPerSec,
        'raw_runs' => [
            round($rawTimes[0], 6),
            round($rawTimes[1], 6),
            round($rawTimes[2], 6),
        ],
        'breakdown' => [
            'tokenization_ms' => 0.0,
            'stemming_ms' => 0.0,
            'cbor_encoding_ms' => 0.0,
            'gzip_ms' => 0.0,
        ],
    ];
}

echo $separator . "\n\n";
echo "Run with --sizes=N,M to benchmark specific sizes.\n";
echo "Example: php scripts/benchmark.php --sizes=100,500,2000\n\n";

// ---------------------------------------------------------------------------
// Write JSON results
// ---------------------------------------------------------------------------

$composerJson = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
$version = $composerJson['version'] ?? 'unknown';
$sha = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__ . '/..') . ' rev-parse --short HEAD 2>/dev/null')) ?: 'unknown';

$jsonData = [
    'version' => $version,
    'git_sha' => $sha,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'environment' => detectEnvironment(),
    'runs' => $jsonRuns,
];

$jsonDir = dirname((string) $jsonPath);
if (!is_dir($jsonDir)) {
    mkdir($jsonDir, 0755, true);
}

file_put_contents($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "JSON results written to: {$jsonPath}\n\n";
