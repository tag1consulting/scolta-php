<?php

declare(strict_types=1);

/**
 * Build a small Pagefind index whose pages carry attachment text.
 *
 * Separate from build-php-index.php on purpose: adding attachment text to the
 * shared concordance corpus would move the result counts the other E2E specs
 * assert on. This corpus exists to prove that stock pagefind.js decodes the
 * attachment weight bucket and can excerpt from it.
 *
 * Usage: php tests/E2E/build-attachment-index.php /path/to/output/dir
 */

require __DIR__ . '/../../vendor/autoload.php';

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

$outputDir = $argv[1] ?? sys_get_temp_dir() . '/scolta-attachment-e2e-output';
$stateDir  = sys_get_temp_dir() . '/scolta-attachment-e2e-state-' . getmypid() . '-' . uniqid();

foreach ([$stateDir, $outputDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// "zygomorphic" appears only in attachment text and nowhere in any body, so a
// hit on it can only have come through the attachment bucket. The surrounding
// sentence is distinctive enough that an excerpt drawn from it is unambiguous.
$items = [
    'lesson-plants' => new ContentItem(
        'lesson-plants',
        'Flowering Plants',
        '<p>Petals attract pollinators to the flower.</p>',
        '/lesson-plants.html',
        '2026-08-11',
        attachmentText: 'Worksheet answer key. A zygomorphic corolla has one plane of symmetry.',
    ),
    'lesson-rocks' => new ContentItem(
        'lesson-rocks',
        'Sedimentary Rocks',
        '<p>Sediment compacts into layers of stone over time.</p>',
        '/lesson-rocks.html',
        '2026-08-11',
    ),
];

$indexer = new PhpIndexer($stateDir, $outputDir);
$indexer->processChunk($items, 0);
$result = $indexer->finalize();

if (!$result->success) {
    fwrite(STDERR, 'Build failed: ' . ($result->error ?? 'unknown') . "\n");
    exit(1);
}

$buildDir  = $outputDir . '/pagefind';
$assetsDir = __DIR__ . '/pagefind-assets';
if (is_dir($assetsDir) && is_dir($buildDir)) {
    foreach (glob($assetsDir . '/*') as $asset) {
        copy($asset, $buildDir . '/' . basename($asset));
    }
}

echo "Built attachment index: {$result->pageCount} pages, {$result->fileCount} files\n";
