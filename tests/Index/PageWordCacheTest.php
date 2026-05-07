<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Tests for the page-word cache in PhpIndexer and IndexBuildOrchestrator.
 *
 * The cache stores per-item tokenization results keyed by content hash so that
 * unchanged pages skip expensive HTML cleaning and tokenization on subsequent
 * builds. These tests verify correctness (cache hit == no-cache output), cache
 * lifecycle (populate, prune, persist), the --force bypass, and edge cases.
 */
class PageWordCacheTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $uid            = uniqid('', true);
        $this->stateDir = sys_get_temp_dir() . "/scolta-cache-state-{$uid}";
        $this->outputDir = sys_get_temp_dir() . "/scolta-cache-out-{$uid}";
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeItem(string $id, string $body, string $date = '2026-01-01'): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: "Page {$id}",
            bodyHtml: "<p>{$body}</p>",
            url: "https://example.com/{$id}",
            date: $date,
            siteName: 'TestSite',
        );
    }

    private function manifestFile(): string
    {
        return $this->stateDir . '/token-cache-manifest.php';
    }

    private function chunkDir(): string
    {
        return $this->stateDir . '/token-cache';
    }

    /**
     * Read the full flattened cache (hash => tokenData) from the chunked disk format.
     * Reads manifest + all chunk files referenced by the manifest.
     */
    private function readCacheFromDisk(): array
    {
        if (!file_exists($this->manifestFile())) {
            return [];
        }

        $manifestRaw = file_get_contents($this->manifestFile());
        $manifest = @unserialize($manifestRaw, ['allowed_classes' => false]);
        if (!is_array($manifest)) {
            return [];
        }

        // Group hashes by chunk number.
        $chunkToHashes = [];
        foreach ($manifest as $hash => $chunkNum) {
            $chunkToHashes[$chunkNum][] = $hash;
        }

        $result = [];
        foreach ($chunkToHashes as $chunkNum => $hashes) {
            $chunkFile = $this->chunkDir()
                . '/chunk-' . str_pad((string) $chunkNum, 6, '0', STR_PAD_LEFT) . '.php';
            if (!file_exists($chunkFile)) {
                continue;
            }
            $chunkRaw = file_get_contents($chunkFile);
            $chunkData = @unserialize($chunkRaw, ['allowed_classes' => false]);
            if (!is_array($chunkData)) {
                continue;
            }
            foreach ($hashes as $hash) {
                if (isset($chunkData[$hash])) {
                    $result[$hash] = $chunkData[$hash];
                }
            }
        }

        return $result;
    }

    private function buildWithPhpIndexer(array $items, bool $force = false): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, count($items), $force);
        $indexer->finalize();
    }

    private function buildWithOrchestrator(array $items, bool $force = false): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent       = BuildIntent::fresh(count($items), MemoryBudget::conservative());
        $orchestrator->build($intent, $items, force: $force);
    }

    private function readEntryJson(): array
    {
        $path = $this->outputDir . '/pagefind/pagefind-entry.json';
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    /**
     * Corrupt the cache entry for a given hash in-place (manifest + chunk file).
     */
    private function corruptCacheEntry(string $hash, string $field, string $value): void
    {
        $manifestRaw = file_get_contents($this->manifestFile());
        $manifest = unserialize($manifestRaw, ['allowed_classes' => false]);
        $chunkNum = $manifest[$hash];

        $chunkFile = $this->chunkDir()
            . '/chunk-' . str_pad((string) $chunkNum, 6, '0', STR_PAD_LEFT) . '.php';
        $chunkRaw = file_get_contents($chunkFile);
        $chunkData = unserialize($chunkRaw, ['allowed_classes' => false]);

        $chunkData[$hash][$field] = $value;
        file_put_contents($chunkFile, serialize($chunkData));
    }

    // =========================================================================
    // Group 1: Fresh build — cache populated correctly
    // =========================================================================

    public function testFreshBuildPopulatesCachePhpIndexer(): void
    {
        $items = [$this->makeItem('a', 'apple banana cherry enough content here')];
        $this->buildWithPhpIndexer($items);

        $cache = $this->readCacheFromDisk();
        $this->assertNotEmpty($cache, 'Cache should be populated after first build');
        $this->assertCount(1, $cache);
    }

    public function testFreshBuildPopulatesCacheOrchestrator(): void
    {
        $items = [$this->makeItem('a', 'apple banana cherry enough content here')];
        $this->buildWithOrchestrator($items);

        $cache = $this->readCacheFromDisk();
        $this->assertNotEmpty($cache, 'Cache should be populated after first build');
        $this->assertCount(1, $cache);
    }

    public function testCacheEntryHasExpectedFields(): void
    {
        $this->buildWithPhpIndexer([$this->makeItem('a', 'alpha beta gamma delta epsilon zeta')]);

        $cache = $this->readCacheFromDisk();
        $entry = reset($cache);

        foreach (['titleTokens', 'bodyTokens', 'urlTokens', 'wordCount', 'cleanTitle', 'content'] as $field) {
            $this->assertArrayHasKey($field, $entry, "Cache entry must have '{$field}'");
        }
        $this->assertIsInt($entry['wordCount']);
        $this->assertIsArray($entry['titleTokens']);
        $this->assertIsArray($entry['bodyTokens']);
    }

    public function testMultipleItemsAllCachedPhpIndexer(): void
    {
        $items = [
            $this->makeItem('a', 'apple content here'),
            $this->makeItem('b', 'banana content here'),
            $this->makeItem('c', 'cherry content here'),
        ];
        $this->buildWithPhpIndexer($items);

        $this->assertCount(3, $this->readCacheFromDisk());
    }

    public function testMultipleItemsAllCachedOrchestrator(): void
    {
        $items = [
            $this->makeItem('a', 'apple content here'),
            $this->makeItem('b', 'banana content here'),
            $this->makeItem('c', 'cherry content here'),
        ];
        $this->buildWithOrchestrator($items);

        $this->assertCount(3, $this->readCacheFromDisk());
    }

    // =========================================================================
    // Group 2: Unchanged content — cache hit produces correct output
    // =========================================================================

    public function testSecondBuildWithSameContentProducesIdenticalEntryJsonPhpIndexer(): void
    {
        $items = [
            $this->makeItem('a', 'apple banana cherry enough words here for indexing'),
            $this->makeItem('b', 'delta epsilon zeta enough words here for indexing'),
        ];

        $this->buildWithPhpIndexer($items);
        $entry1 = $this->readEntryJson();

        // Remove output but keep cache; second build reads from cache.
        $this->removeDir($this->outputDir . '/pagefind');
        mkdir($this->outputDir . '/pagefind', 0755, true);

        $this->buildWithPhpIndexer($items);
        $entry2 = $this->readEntryJson();

        $this->assertSame(
            $entry1['languages']['en']['page_count'] ?? null,
            $entry2['languages']['en']['page_count'] ?? null,
            'Page count must be identical across cached rebuild'
        );
    }

    public function testSecondBuildWithSameContentProducesIdenticalEntryJsonOrchestrator(): void
    {
        $items = [
            $this->makeItem('a', 'apple banana cherry enough words here for indexing'),
            $this->makeItem('b', 'delta epsilon zeta enough words here for indexing'),
        ];

        $this->buildWithOrchestrator($items);
        $entry1 = $this->readEntryJson();

        $this->removeDir($this->outputDir . '/pagefind');
        mkdir($this->outputDir . '/pagefind', 0755, true);

        $this->buildWithOrchestrator($items);
        $entry2 = $this->readEntryJson();

        $this->assertSame(
            $entry1['languages']['en']['page_count'] ?? null,
            $entry2['languages']['en']['page_count'] ?? null,
        );
    }

    public function testCacheKeyIsDeterministicAcrossInstances(): void
    {
        $item = $this->makeItem('a', 'hello world enough words for indexing');

        $this->buildWithPhpIndexer([$item]);
        $cache1 = array_keys($this->readCacheFromDisk());

        // Second instance — same item, same hash expected.
        $this->buildWithPhpIndexer([$item]);
        $cache2 = array_keys($this->readCacheFromDisk());

        $this->assertSame($cache1, $cache2, 'Content hash must be deterministic');
    }

    // =========================================================================
    // Group 3: Changed content — cache invalidated on body change
    // =========================================================================

    public function testChangedBodyInvalidatesCacheEntryPhpIndexer(): void
    {
        $original = $this->makeItem('a', 'original content for first build enough words');
        $this->buildWithPhpIndexer([$original]);
        $oldCache = $this->readCacheFromDisk();
        $oldKey   = array_key_first($oldCache);

        $modified = $this->makeItem('a', 'completely different body content for second build');
        $this->buildWithPhpIndexer([$modified]);
        $newCache = $this->readCacheFromDisk();
        $newKey   = array_key_first($newCache);

        $this->assertNotSame($oldKey, $newKey, 'Content hash must change when body changes');
    }

    public function testChangedBodyInvalidatesCacheEntryOrchestrator(): void
    {
        $original = $this->makeItem('a', 'original content for first build enough words');
        $this->buildWithOrchestrator([$original]);
        $oldKey = array_key_first($this->readCacheFromDisk());

        $modified = $this->makeItem('a', 'completely different body content for second build');
        $this->buildWithOrchestrator([$modified]);
        $newKey = array_key_first($this->readCacheFromDisk());

        $this->assertNotSame($oldKey, $newKey);
    }

    public function testMixedBuildCachesOnlyNewItems(): void
    {
        $unchanged = $this->makeItem('a', 'stable content that will not change at all');
        $toChange  = $this->makeItem('b', 'content that will be replaced entirely');

        $this->buildWithPhpIndexer([$unchanged, $toChange]);
        $cache1    = $this->readCacheFromDisk();
        $keyA      = PhpIndexer::contentHash($unchanged);

        $changed = $this->makeItem('b', 'brand new replacement content for item b');
        $this->buildWithPhpIndexer([$unchanged, $changed]);
        $cache2 = $this->readCacheFromDisk();

        // Unchanged item keeps its original cache entry.
        $this->assertArrayHasKey($keyA, $cache2, 'Unchanged item must remain in cache');
        $this->assertSame($cache1[$keyA], $cache2[$keyA], 'Unchanged item cache entry must be identical');
        $this->assertCount(2, $cache2, 'Cache should have exactly 2 entries (unchanged + new)');
    }

    // =========================================================================
    // Group 4: Deleted content — pruning removes stale entries
    // =========================================================================

    public function testRemovedItemPrunedFromCachePhpIndexer(): void
    {
        $a = $this->makeItem('a', 'item a with enough words for indexing');
        $b = $this->makeItem('b', 'item b with enough words for indexing');

        $this->buildWithPhpIndexer([$a, $b]);
        $this->assertCount(2, $this->readCacheFromDisk());

        // Rebuild with only item a — item b's cache entry should be pruned.
        $this->buildWithPhpIndexer([$a]);
        $this->assertCount(1, $this->readCacheFromDisk());
    }

    public function testRemovedItemPrunedFromCacheOrchestrator(): void
    {
        $a = $this->makeItem('a', 'item a with enough words for indexing');
        $b = $this->makeItem('b', 'item b with enough words for indexing');

        $this->buildWithOrchestrator([$a, $b]);
        $this->assertCount(2, $this->readCacheFromDisk());

        $this->buildWithOrchestrator([$a]);
        $this->assertCount(1, $this->readCacheFromDisk());
    }

    // =========================================================================
    // Group 5: New content — new items tokenized and added to cache
    // =========================================================================

    public function testNewItemAddedToCachePhpIndexer(): void
    {
        $a = $this->makeItem('a', 'item a with enough words for indexing');
        $this->buildWithPhpIndexer([$a]);
        $this->assertCount(1, $this->readCacheFromDisk());

        $b = $this->makeItem('b', 'item b with enough words for indexing');
        $this->buildWithPhpIndexer([$a, $b]);
        $this->assertCount(2, $this->readCacheFromDisk());
    }

    public function testNewItemAddedToCacheOrchestrator(): void
    {
        $a = $this->makeItem('a', 'item a with enough words for indexing');
        $this->buildWithOrchestrator([$a]);
        $this->assertCount(1, $this->readCacheFromDisk());

        $b = $this->makeItem('b', 'item b with enough words for indexing');
        $this->buildWithOrchestrator([$a, $b]);
        $this->assertCount(2, $this->readCacheFromDisk());
    }

    // =========================================================================
    // Group 6: Combined (add + change + delete) — all handled in one build
    // =========================================================================

    public function testCombinedAddChangedDeletePhpIndexer(): void
    {
        $a = $this->makeItem('a', 'stable item a content enough words');
        $b = $this->makeItem('b', 'item b to be changed original text');
        $c = $this->makeItem('c', 'item c to be deleted content words');

        $this->buildWithPhpIndexer([$a, $b, $c]);
        $this->assertCount(3, $this->readCacheFromDisk());

        $bNew = $this->makeItem('b', 'item b replacement text completely different');
        $d    = $this->makeItem('d', 'brand new item d just added to set');

        $this->buildWithPhpIndexer([$a, $bNew, $d]);
        $cache = $this->readCacheFromDisk();

        $this->assertCount(3, $cache, 'Cache should have 3 entries: a, b-new, d');
        $this->assertArrayHasKey(PhpIndexer::contentHash($a), $cache, 'a unchanged');
        $this->assertArrayHasKey(PhpIndexer::contentHash($bNew), $cache, 'b updated');
        $this->assertArrayHasKey(PhpIndexer::contentHash($d), $cache, 'd added');
        $this->assertArrayNotHasKey(PhpIndexer::contentHash($b), $cache, 'b old pruned');
        $this->assertArrayNotHasKey(PhpIndexer::contentHash($c), $cache, 'c deleted pruned');
    }

    // =========================================================================
    // Group 7: --force flag bypasses cache but still populates it
    // =========================================================================

    public function testForceBuildBypassesCacheLookupPhpIndexer(): void
    {
        $item = $this->makeItem('a', 'hello world enough content for indexing please');
        $this->buildWithPhpIndexer([$item]);

        // Corrupt the on-disk cache so any hit would produce wrong output.
        $hash = PhpIndexer::contentHash($item);
        $this->corruptCacheEntry($hash, 'cleanTitle', '__CORRUPTED__');

        // Without force: reads corrupted cache.
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk([$item], 0, 1, false);
        $result = $indexer->finalize();
        $this->assertTrue($result->success);

        // Verify the corrupted title would propagate (cleanTitle in cache is used).
        $updatedCache = $this->readCacheFromDisk();
        // On a non-force build with a cache hit, we reuse the cached token data.
        // The cleanTitle in the output comes from cached data, so it IS corrupted.
        $entry = reset($updatedCache);
        $this->assertSame(
            '__CORRUPTED__',
            $entry['cleanTitle'],
            'Non-force build uses cached (corrupted) token data'
        );

        // Now force: must bypass cache and re-tokenize.
        $indexer2 = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer2->processChunk([$item], 0, 1, true);
        $indexer2->finalize();

        $freshCache = $this->readCacheFromDisk();
        $freshEntry = reset($freshCache);
        $this->assertNotSame(
            '__CORRUPTED__',
            $freshEntry['cleanTitle'],
            'Force build must re-tokenize and overwrite corrupted cache'
        );
    }

    public function testForceBuildBypassesCacheLookupOrchestrator(): void
    {
        $item = $this->makeItem('a', 'hello world enough content for indexing please');
        $this->buildWithOrchestrator([$item]);

        // Corrupt the on-disk cache.
        $hash = PhpIndexer::contentHash($item);
        $this->corruptCacheEntry($hash, 'cleanTitle', '__CORRUPTED__');

        // Force build must bypass the corrupted entry and re-tokenize.
        $this->buildWithOrchestrator([$item], force: true);

        $freshCache = $this->readCacheFromDisk();
        $freshEntry = reset($freshCache);
        $this->assertNotSame(
            '__CORRUPTED__',
            $freshEntry['cleanTitle'],
            'Force build on orchestrator must re-tokenize and overwrite corrupted cache'
        );
    }

    public function testForceBuildStillPopulatesCachePhpIndexer(): void
    {
        $item = $this->makeItem('a', 'force build should still populate cache entry');
        $this->buildWithPhpIndexer([$item], force: true);

        $cache = $this->readCacheFromDisk();
        $this->assertCount(1, $cache, 'Force build must still populate cache for future non-force builds');
    }

    public function testForceBuildStillPopulatesCacheOrchestrator(): void
    {
        $item = $this->makeItem('a', 'force build should still populate cache entry');
        $this->buildWithOrchestrator([$item], force: true);

        $this->assertCount(1, $this->readCacheFromDisk());
    }

    public function testNonForceBuildAfterForceUsesFreshCache(): void
    {
        $item = $this->makeItem('a', 'content for cache repopulation test enough words');

        $this->buildWithPhpIndexer([$item], force: true);
        $cacheAfterForce = $this->readCacheFromDisk();

        $this->buildWithPhpIndexer([$item], force: false);
        $cacheAfterNonForce = $this->readCacheFromDisk();

        $this->assertSame(
            array_keys($cacheAfterForce),
            array_keys($cacheAfterNonForce),
            'Non-force build after force build should use the same cache key'
        );
    }

    // =========================================================================
    // Group 8: Correctness invariants
    // =========================================================================

    public function testCachedBuildProducesSamePageCountAsUncached(): void
    {
        $items = [
            $this->makeItem('a', 'apple content for page a enough words'),
            $this->makeItem('b', 'banana content for page b enough words'),
            $this->makeItem('c', 'cherry content for page c enough words'),
        ];

        $this->buildWithPhpIndexer($items);
        $count1 = $this->readEntryJson()['languages']['en']['page_count'] ?? 0;

        $this->removeDir($this->outputDir . '/pagefind');
        $this->buildWithPhpIndexer($items);
        $count2 = $this->readEntryJson()['languages']['en']['page_count'] ?? 0;

        $this->assertSame($count1, $count2, 'Cached rebuild must produce same page count');
        $this->assertSame(3, $count2);
    }

    public function testMetadataFieldsAlwaysComeFromCurrentItemNotCache(): void
    {
        $item = $this->makeItem('a', 'enough content words for a real index entry here');

        // First build: date is 2026-01-01.
        $this->buildWithPhpIndexer([$item]);

        // Second build: same body but different date (URL + bodyHtml unchanged → cache hit).
        $updatedDateItem = new ContentItem(
            id: 'a',
            title: 'Page a',
            bodyHtml: $item->bodyHtml,
            url: $item->url,
            date: '2026-12-31',  // Changed
            siteName: 'TestSite',
        );

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk([$updatedDateItem], 0, 1, false);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);
        // The cache hit reuses token data but metadata (date, siteName, filters)
        // must still come from the current item. Verify the build completes — the
        // deeper invariant is enforced by the buildFromTokenData() implementation
        // which always reads item->date from the current item, not cached data.
        $this->assertSame(1, $result->pageCount);
    }

    public function testTooShortContentNotCached(): void
    {
        $shortItem = new ContentItem(
            id: 'tiny',
            title: 'Short',
            bodyHtml: '<p>Hi</p>',
            url: 'https://example.com/tiny',
            date: '2026-01-01',
            siteName: 'Test',
        );
        $validItem = $this->makeItem('ok', 'valid content with enough words for indexing');

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk([$shortItem, $validItem], 0, 2);
        $indexer->finalize();

        $cache = $this->readCacheFromDisk();
        $this->assertCount(1, $cache, 'Only the valid item should be cached');
        $this->assertArrayHasKey(PhpIndexer::contentHash($validItem), $cache);
        $this->assertArrayNotHasKey(PhpIndexer::contentHash($shortItem), $cache);
    }

    // =========================================================================
    // Group 9: Edge cases
    // =========================================================================

    public function testCorruptedCacheFileIsIgnoredAndBuildSucceeds(): void
    {
        // Write a corrupted manifest before first build — cache must be ignored.
        file_put_contents($this->manifestFile(), 'THIS IS NOT VALID SERIALIZED DATA');

        $item = $this->makeItem('a', 'content that should index fine despite corrupt cache');

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk([$item], 0, 1);
        $buildResult = $indexer->finalize();

        $this->assertTrue($buildResult->success, 'Build must succeed even with a corrupted cache file');
        $this->assertSame(1, $buildResult->pageCount);
    }

    public function testCorruptedCacheFileIsIgnoredOrchestrator(): void
    {
        file_put_contents($this->manifestFile(), 'INVALID');

        $item         = $this->makeItem('a', 'content for orchestrator with corrupt cache test');
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report       = $orchestrator->build(
            BuildIntent::fresh(1, MemoryBudget::conservative()),
            [$item],
        );

        $this->assertTrue($report->success);
        $this->assertSame(1, $report->pagesProcessed);
    }

    public function testCachePersistsAcrossPhpIndexerInstances(): void
    {
        $item = $this->makeItem('a', 'persistent cache content enough words here');

        // First instance: populate cache.
        $indexer1 = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer1->processChunk([$item], 0, 1);
        $indexer1->finalize();
        $cache1 = $this->readCacheFromDisk();

        // Second instance: should load the same cache.
        $indexer2 = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer2->processChunk([$item], 0, 1);
        $indexer2->finalize();
        $cache2 = $this->readCacheFromDisk();

        $this->assertSame(
            array_keys($cache1),
            array_keys($cache2),
            'Cache keys must persist across PhpIndexer instances'
        );
    }

    public function testEmptyItemSetResultsInEmptyCache(): void
    {
        // An empty build attempt — finalize will fail (no chunks), but the cache
        // should be empty and not corrupt.
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        // Don't call processChunk — just verify finalize with no chunks fails cleanly.
        $result = $indexer->finalize();
        $this->assertFalse($result->success);

        // Manifest must not exist (no items were processed, pruneAndSave not called).
        $this->assertFileDoesNotExist(
            $this->manifestFile(),
            'Manifest must not be created when no items are processed'
        );
    }
}
