<?php

declare(strict_types=1);

/**
 * Benchmark script: measures PHP indexer throughput.
 *
 * Usage:
 *   php scripts/benchmark.php
 *   php scripts/benchmark.php --sizes=100,1000
 *
 * Output: a table of pages/second, wall-clock time and memory.
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

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sizes=')) {
        $raw = substr($arg, strlen('--sizes='));
        $parsed = array_map('intval', explode(',', $raw));
        $parsed = array_filter($parsed, fn (int $n) => $n > 0);
        if (!empty($parsed)) {
            $sizes = array_values($parsed);
        }
    }
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
        language: 'en',
    );
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

foreach ($sizes as $n) {
    // Pre-generate items before timing (exclude generation time).
    $items = [];
    for ($i = 0; $i < $n; $i++) {
        $items[] = generateSyntheticItem($i, $wordPool);
    }

    // Directories.
    $stateDir = sys_get_temp_dir() . '/scolta-bench-state-' . uniqid();
    $outputDir = sys_get_temp_dir() . '/scolta-bench-output-' . uniqid();
    mkdir($stateDir, 0755, true);
    mkdir($outputDir, 0755, true);

    // Force GC before run.
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);

    // Time the build.
    $start = hrtime(true);

    $indexer = new PhpIndexer($stateDir, $outputDir);
    $indexer->processChunk($items, 0);
    $result = $indexer->finalize();

    $elapsed = (hrtime(true) - $start) / 1e9; // seconds

    $memAfter = memory_get_peak_usage(true);
    $memMb = ($memAfter - $memBefore) / (1024 * 1024);

    $pagesPerSec = $elapsed > 0 ? (int) round($n / $elapsed) : 0;

    echo sprintf(
        ' %' . $colWidths[0] . 'd | %' . $colWidths[1] . '.3f | %' . $colWidths[2] . 'd | %' . $colWidths[3] . '.1f',
        $n,
        $elapsed,
        $pagesPerSec,
        $memMb
    ) . "\n";

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
}

echo $separator . "\n\n";
echo "Run with --sizes=N,M to benchmark specific sizes.\n";
echo "Example: php scripts/benchmark.php --sizes=100,500,2000\n\n";
