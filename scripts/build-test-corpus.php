<?php

/**
 * Build a test corpus with the PHP indexer for compatibility testing.
 *
 * Usage: php scripts/build-test-corpus.php
 * Output: test-output/pagefind/
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

$items = [
    new ContentItem('1', 'Welcome to Scolta', '<p>Scolta is a search solution for CMS platforms.</p>', '/welcome', '2026-04-10'),
    new ContentItem('2', 'Installation Guide', '<p>Install Scolta via Composer. Run composer require tag1/scolta-php.</p>', '/install', '2026-04-10'),
    new ContentItem('3', 'Café Culture in Paris', '<p>The café culture of Paris is world-renowned. Naïve tourists often underestimate the résumé of French dining.</p>', '/cafe', '2026-04-10'),
    new ContentItem('4', '母语教学方法', '<p>中文教学方法的研究对于提高教育质量至关重要。</p>', '/chinese', '2026-04-10'),
    new ContentItem('5', 'Running and Walking', '<p>Running improves cardiovascular health. Walking is also beneficial for runners who need recovery days.</p>', '/running', '2026-04-10'),
];

$stateDir = sys_get_temp_dir() . '/scolta-compat-test-state';
$outputDir = __DIR__ . '/../test-output';

@mkdir($stateDir, 0755, true);
@mkdir($outputDir, 0755, true);

$indexer = new PhpIndexer($stateDir, $outputDir);
$indexer->processChunk($items, 0);
$result = $indexer->finalize();

echo $result->success ? "Index built: {$result->pageCount} pages, {$result->fileCount} files\n" : "Build failed: {$result->error}\n";
