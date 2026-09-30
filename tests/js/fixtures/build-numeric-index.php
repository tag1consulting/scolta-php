<?php

declare(strict_types=1);

/**
 * Build the digit-leading-term corpus from NumericTermOrderTest with the PHP
 * indexer, for numeric-term-search.test.js to load with Pagefind's runtime.
 *
 * Usage: php build-numeric-index.php <output dir>
 * Prints a JSON map of term => URLs of the pages that contain it.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../Index/NumericTermOrderTest.php';

use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Tests\Index\NumericTermOrderTest;

$outputDir = $argv[1] ?? throw new \InvalidArgumentException('Usage: build-numeric-index.php <output dir>');
$stateDir  = $outputDir . '/.state';
foreach ([$outputDir, $stateDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new \RuntimeException("Cannot create {$dir}");
    }
}

['items' => $items, 'pagesFor' => $pagesFor] = NumericTermOrderTest::corpus();

$result = (new IndexBuildOrchestrator($stateDir, $outputDir))->build(
    BuildIntent::fresh(count($items), MemoryBudget::conservative()->withChunkSize(2)),
    $items,
);
if (!$result->success) {
    fwrite(STDERR, 'Build failed: ' . ($result->error ?? '') . "\n");
    exit(1);
}

$urls = [];
foreach ($pagesFor as $term => $ids) {
    $urls[$term] = array_map(static fn(string $id): string => '/lesson-' . substr($id, strlen('page-')), $ids);
}
echo json_encode($urls, JSON_UNESCAPED_SLASHES), "\n";
