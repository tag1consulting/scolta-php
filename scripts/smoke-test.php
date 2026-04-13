<?php

declare(strict_types=1);

/**
 * Release smoke test: exercises the full PHP indexer pipeline.
 *
 * What this verifies:
 *   1. PHP indexer can process ContentItems and produce pagefind-format output
 *   2. Output directory contains expected files (entry JSON, fragments, index)
 *   3. Page count matches the number of items submitted
 *   4. Incremental rebuild: second run with same items produces no new output
 *   5. AiEndpointHandler rejects invalid inputs without a real API key
 *
 * Exit code 0 = all checks passed.
 * Exit code 1 = a check failed (details on STDERR).
 */

require __DIR__ . '/../vendor/autoload.php';

use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Prompt\NullEnricher;

$fail = false;

function pass(string $msg): void
{
    echo "PASS: {$msg}\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail = true;
    fwrite(STDERR, "FAIL: {$msg}\n");
}

function check(bool $condition, string $passMsg, string $failMsg): void
{
    if ($condition) {
        pass($passMsg);
    } else {
        fail($failMsg);
    }
}

// ---------------------------------------------------------------------------
// Setup temp directories
// ---------------------------------------------------------------------------

$stateDir  = '/tmp/scolta-smoke-state-' . uniqid();
$outputDir = '/tmp/scolta-smoke-output';

foreach ([$stateDir, $outputDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ---------------------------------------------------------------------------
// 1. Build an index from 50 synthetic ContentItems
// ---------------------------------------------------------------------------

echo "\n--- 1. PHP Indexer pipeline ---\n";

$items = [];
$topics = ['search algorithms', 'web crawling', 'inverted index', 'relevance scoring', 'stemming'];
for ($i = 1; $i <= 50; $i++) {
    $topic = $topics[$i % count($topics)];
    $items[] = new ContentItem(
        id: "doc-{$i}",
        title: "Article {$i}: " . ucfirst($topic),
        bodyHtml: "<p>This article covers {$topic} in depth. "
            . "It is article number {$i} in the series. "
            . "Keywords: search, index, algorithm, retrieval, relevance, pagefind, {$topic}.</p>"
            . "<p>Additional content about {$topic} helps with stemming and concordance tests. "
            . "The article was written for the Scolta smoke test suite.</p>",
        url: "/articles/{$i}",
        date: date('Y-m-d', strtotime("-{$i} days")),
        siteName: 'Scolta Smoke Test',
    );
}

$indexer = new PhpIndexer($stateDir, $outputDir);
$indexer->processChunk($items, 0);
$result = $indexer->finalize();

check($result->success, "Indexer finalized successfully", "Indexer finalize() returned success=false: " . ($result->error ?? 'unknown'));
check($result->pageCount === 50, "Page count equals 50 ({$result->pageCount})", "Expected 50 pages, got {$result->pageCount}");
check($result->fileCount > 0, "File count > 0 ({$result->fileCount})", "No files written");

// ---------------------------------------------------------------------------
// 2. Verify output file structure
// ---------------------------------------------------------------------------

echo "\n--- 2. Output file structure ---\n";

$pagefindDir = $outputDir . '/pagefind';

check(
    is_dir($pagefindDir),
    "pagefind/ directory exists",
    "pagefind/ directory missing from {$outputDir}"
);

check(
    file_exists($pagefindDir . '/pagefind-entry.json'),
    "pagefind-entry.json present",
    "pagefind-entry.json missing"
);

$entryContent = @file_get_contents($pagefindDir . '/pagefind-entry.json');
$entry = $entryContent ? json_decode($entryContent, true) : null;
check(
    is_array($entry) && isset($entry['version']),
    "pagefind-entry.json is valid JSON with version field (v{$entry['version']})",
    "pagefind-entry.json is invalid or missing 'version' field"
);

// Fragments live in fragment/ subdirectory.
$fragmentCount = count(glob($pagefindDir . '/fragment/*.pf_fragment') ?: []);
check(
    $fragmentCount >= 5,
    "At least 5 fragment files present ({$fragmentCount})",
    "Expected >= 5 fragment files, found {$fragmentCount}"
);

// Index files live in index/ subdirectory.
$indexCount = count(glob($pagefindDir . '/index/*.pf_index') ?: []);
check(
    $indexCount >= 1,
    "At least 1 index file present ({$indexCount})",
    "No .pf_index files found"
);

$metaCount = count(glob($pagefindDir . '/*.pf_meta') ?: []);
check(
    $metaCount >= 1,
    "At least 1 meta file present ({$metaCount})",
    "No .pf_meta files found"
);

// ---------------------------------------------------------------------------
// 3. Verify fragment content is readable gzip
// ---------------------------------------------------------------------------

echo "\n--- 3. Fragment file integrity ---\n";

$fragments = glob($pagefindDir . '/fragment/*.pf_fragment') ?: [];
$fragmentOk = 0;
foreach (array_slice($fragments, 0, 5) as $fragmentFile) {
    $raw = file_get_contents($fragmentFile);
    // Fragments are gzip-encoded. PHP's gzinflate needs the raw deflate stream.
    // We can verify the gzip magic bytes instead.
    if (str_starts_with($raw, "\x1f\x8b")) {
        $fragmentOk++;
    }
}
check(
    $fragmentOk > 0,
    "Fragment files have valid gzip magic bytes ({$fragmentOk}/5 checked)",
    "Fragment files do not appear to be gzip-encoded"
);

// ---------------------------------------------------------------------------
// 4. Incremental rebuild: second run with same items
// ---------------------------------------------------------------------------

echo "\n--- 4. Incremental rebuild ---\n";

$stateDir2 = '/tmp/scolta-smoke-state2-' . uniqid();
$outputDir2 = '/tmp/scolta-smoke-output2-' . uniqid();
mkdir($stateDir2, 0755, true);
mkdir($outputDir2, 0755, true);

// Compute fingerprint, build, and persist it (caller responsibility).
$indexer1 = new PhpIndexer($stateDir2, $outputDir2);
$fingerprint1 = $indexer1->shouldBuild($items);

check(
    $fingerprint1 !== null,
    "shouldBuild() returns a fingerprint for fresh items",
    "shouldBuild() unexpectedly returned null before first build"
);

$indexer1->processChunk($items, 0);
$result1 = $indexer1->finalize();
file_put_contents($outputDir2 . '/.scolta-state', (string) $fingerprint1);

check(
    $result1->success,
    "First build succeeded ({$result1->pageCount} pages)",
    "First build failed: " . ($result1->error ?? 'unknown')
);

// Second run: shouldBuild() with identical items should return null (no change).
$indexer2 = new PhpIndexer($stateDir2, $outputDir2);
$fingerprint2 = $indexer2->shouldBuild($items);

check(
    $fingerprint2 === null,
    "shouldBuild() returns null for identical content (incremental skip)",
    "Expected null fingerprint on unchanged content, got: " . var_export($fingerprint2, true)
);

// ---------------------------------------------------------------------------
// 5. AiEndpointHandler: validation without a real API key
// ---------------------------------------------------------------------------

echo "\n--- 5. AI endpoint validation ---\n";

$cache = new class implements CacheDriverInterface {
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store[$key] = $value;
    }
};

// Use a minimal mock AI service (duck-typed — just needs the methods AiEndpointHandler calls).
$mockAi = new class {
    public function getExpandPrompt(): string { return 'Expand: {QUERY}'; }
    public function getSummarizePrompt(): string { return 'Summarize: {QUERY}'; }
    public function getFollowUpPrompt(): string { return 'Follow-up: {QUERY}'; }
    public function message(string $prompt): \Tag1\Scolta\Ai\AiResponse
    {
        return new \Tag1\Scolta\Ai\AiResponse(true, '["result1", "result2"]');
    }
    public function conversation(array $messages): \Tag1\Scolta\Ai\AiResponse
    {
        return new \Tag1\Scolta\Ai\AiResponse(true, 'Follow-up answer.');
    }
};

$handler = new AiEndpointHandler(
    aiService: $mockAi,
    cache: $cache,
    generation: 1,
    cacheTtl: 0,
    maxFollowUps: 3,
    promptEnricher: new NullEnricher(),
);

// Empty query should be rejected.
$r = $handler->handleExpandQuery('');
check($r['ok'] === false && $r['status'] === 400, "Empty query rejected with 400", "Empty query not rejected: " . json_encode($r));

// Over-length query should be rejected.
$r = $handler->handleExpandQuery(str_repeat('a', 501));
check($r['ok'] === false && $r['status'] === 400, "Over-length query rejected with 400", "Over-length query not rejected");

// Empty summarize query should be rejected.
$r = $handler->handleSummarize('', 'some context');
check($r['ok'] === false && $r['status'] === 400, "Empty summarize query rejected", "Empty summarize not rejected");

// Empty follow-up messages should be rejected.
$r = $handler->handleFollowUp([]);
check($r['ok'] === false && $r['status'] === 400, "Empty follow-up messages rejected", "Empty follow-up not rejected");

// ---------------------------------------------------------------------------
// 6. Clean up
// ---------------------------------------------------------------------------

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    // glob() skips hidden files — use scandir() to include .scolta-state etc.
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? removeDir($path) : unlink($path);
    }
    rmdir($dir);
}

removeDir($stateDir);
removeDir($stateDir2);
removeDir($outputDir2);
// Leave $outputDir for the CI step that verifies the output structure.

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n";
if ($fail) {
    fwrite(STDERR, "FAIL: Smoke test failed — see errors above.\n");
    exit(1);
}

echo "PASS: All smoke test checks passed.\n";
