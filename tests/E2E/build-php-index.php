<?php

declare(strict_types=1);

/**
 * Build a PHP-generated Pagefind index from the concordance corpus.
 * Used by the E2E Playwright tests to prove format compatibility.
 *
 * Usage: php tests/E2E/build-php-index.php /path/to/output/dir
 */

require __DIR__ . '/../../vendor/autoload.php';

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

$outputDir = $argv[1] ?? sys_get_temp_dir() . '/scolta-e2e-output';
$corpusDir = __DIR__ . '/../fixtures/concordance/corpus';
$stateDir = sys_get_temp_dir() . '/scolta-e2e-state-' . uniqid();

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0755, true);
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$items = [];
foreach (glob($corpusDir . '/*.html') as $file) {
    $filename = pathinfo($file, PATHINFO_FILENAME);
    $html = file_get_contents($file);

    preg_match('/<title>(.*?)<\/title>/s', $html, $titleMatch);
    $title = html_entity_decode($titleMatch[1] ?? $filename);

    preg_match('/<body[^>]*>(.*?)<\/body>/s', $html, $bodyMatch);
    $body = $bodyMatch[1] ?? '';

    preg_match('/data-pagefind-meta="date:([^"]*)"/', $html, $dateMatch);
    $date = $dateMatch[1] ?? '';

    preg_match('/data-pagefind-filter="category:([^"]*)"/', $html, $catMatch);
    $siteName = $catMatch[1] ?? '';

    // Use STRING keys to exercise the production code path.
    $items['post-' . $filename] = new ContentItem(
        $filename,
        $title,
        $body,
        '/' . $filename . '.html',
        $date,
        $siteName
    );
}

$indexer = new PhpIndexer($stateDir, $outputDir);
$indexer->processChunk($items, 0);
$result = $indexer->finalize();

if (!$result->success) {
    fwrite(STDERR, 'Build failed: ' . ($result->error ?? 'unknown') . "\n");
    exit(1);
}

// Copy pagefind.js browser assets into the index directory for serving.
$buildDir = $outputDir . '/pagefind';
$assetsDir = __DIR__ . '/pagefind-assets';
if (is_dir($assetsDir) && is_dir($buildDir)) {
    foreach (glob($assetsDir . '/*') as $asset) {
        copy($asset, $buildDir . '/' . basename($asset));
    }
}

echo "Built PHP index: {$result->pageCount} pages, {$result->fileCount} files\n";
echo "Output: {$buildDir}\n";
