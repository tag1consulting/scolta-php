<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\PageWordCache;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * Unit tests for PageWordCache (chunked disk-backed token cache).
 *
 * Tests the PageWordCache class directly without going through PhpIndexer
 * or IndexBuildOrchestrator. Covers: hit/miss, write buffer flush, pruning,
 * orphaned chunk deletion, corruption isolation, atomic writes, put-supersedes-
 * old-chunk, legacy migration, and corrupted legacy migration.
 */
class PageWordCacheUnitTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $uid            = uniqid('', true);
        $this->stateDir = sys_get_temp_dir() . "/scolta-cache-unit-{$uid}";
        mkdir($this->stateDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCache(int $chunkSize = 10, int $maxWriteBufferBytes = 4 * 1024 * 1024): PageWordCache
    {
        return new PageWordCache($this->stateDir, new FilesystemDriver(), $chunkSize, null, $maxWriteBufferBytes);
    }

    private function tokenData(string $label): array
    {
        return [
            'titleTokens' => [$label],
            'bodyTokens'  => [$label . '_body'],
            'urlTokens'   => [],
            'wordCount'   => 3,
            'cleanTitle'  => $label,
            'content'     => $label . ' content',
        ];
    }

    private function manifestFile(): string
    {
        return $this->stateDir . '/token-cache-manifest.php';
    }

    private function chunkDir(): string
    {
        return $this->stateDir . '/token-cache';
    }

    private function chunkFile(int $num): string
    {
        return $this->chunkDir() . '/chunk-' . str_pad((string) $num, 6, '0', STR_PAD_LEFT) . '.php';
    }

    private function readManifest(): array
    {
        if (!file_exists($this->manifestFile())) {
            return [];
        }
        $data = @unserialize(file_get_contents($this->manifestFile()), ['allowed_classes' => false]);
        return is_array($data) ? $data : [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    // =========================================================================
    // get() miss
    // =========================================================================

    public function testGetMissReturnsNull(): void
    {
        $cache = $this->makeCache();
        $this->assertNull($cache->get('nonexistent-hash'));
    }

    public function testGetMissMarksHashAsUsed(): void
    {
        $cache = $this->makeCache();
        $cache->get('hash-miss');
        // Populate something else to give pruning something to prune.
        $cache->put('hash-hit', $this->tokenData('hit'));
        $cache->pruneAndSave();

        // hash-miss was used (but never in manifest), hash-hit was put and used.
        $manifest = $this->readManifest();
        $this->assertArrayHasKey('hash-hit', $manifest, 'put entry must survive pruning');
    }

    // =========================================================================
    // put() + get() hit
    // =========================================================================

    public function testPutThenGetReturnsTokenData(): void
    {
        $cache = $this->makeCache();
        $data  = $this->tokenData('alpha');
        $cache->put('hash-a', $data);

        $result = $cache->get('hash-a');
        $this->assertSame($data, $result, 'get() after put() must return same token data');
    }

    public function testPutPersistsAfterPruneAndSave(): void
    {
        $cache = $this->makeCache();
        $data  = $this->tokenData('beta');
        $cache->put('hash-b', $data);
        $cache->get('hash-b'); // mark used
        $cache->pruneAndSave();

        // Reload from disk.
        $cache2 = $this->makeCache();
        $result = $cache2->get('hash-b');
        $this->assertSame($data, $result, 'Data must survive pruneAndSave() and reload');
    }

    // =========================================================================
    // Write buffer flush at chunk size
    // =========================================================================

    public function testWriteBufferFlushesWhenChunkSizeReached(): void
    {
        $cache = $this->makeCache(chunkSize: 3);

        for ($i = 0; $i < 3; $i++) {
            $cache->put("hash-{$i}", $this->tokenData("item-{$i}"));
        }

        // At chunk size 3, the 3rd put() triggers a flush.
        // The chunk file should exist now.
        $this->assertFileExists($this->chunkFile(0), 'Chunk file must be written when buffer reaches chunk size');
    }

    public function testMultipleFlushesCreateDistinctChunks(): void
    {
        $cache = $this->makeCache(chunkSize: 2);

        for ($i = 0; $i < 4; $i++) {
            $cache->put("hash-{$i}", $this->tokenData("item-{$i}"));
        }

        // Two full flushes at chunk size 2 → chunks 0 and 1.
        $this->assertFileExists($this->chunkFile(0));
        $this->assertFileExists($this->chunkFile(1));
    }

    public function testByteThresholdTriggersFlusheBeforeCountThreshold(): void
    {
        // Each tokenData() entry estimates at ~173 bytes (2 tokens × 80 + 13 content chars).
        // With maxWriteBufferBytes=300 and chunkSize=100, the 2nd put() should trip the
        // byte threshold (2 × 173 = 346 ≥ 300) long before the count threshold (100).
        $cache = $this->makeCache(chunkSize: 100, maxWriteBufferBytes: 300);

        $cache->put('hash-0', $this->tokenData('item-0'));
        $this->assertFileDoesNotExist($this->chunkFile(0), 'First put must not flush (1 entry < 300 bytes)');

        $cache->put('hash-1', $this->tokenData('item-1'));
        $this->assertFileExists(
            $this->chunkFile(0),
            'Second put must flush via byte threshold before count threshold (100) is reached',
        );
    }

    public function testByteThresholdDataIsRetrievableAfterFlush(): void
    {
        $cache = $this->makeCache(chunkSize: 100, maxWriteBufferBytes: 300);

        $data0 = $this->tokenData('item-0');
        $data1 = $this->tokenData('item-1');
        $cache->put('hash-0', $data0);
        $cache->put('hash-1', $data1); // triggers byte flush
        $cache->pruneAndSave();

        $cache2 = $this->makeCache(chunkSize: 100, maxWriteBufferBytes: 300);
        $this->assertSame($data0, $cache2->get('hash-0'));
        $this->assertSame($data1, $cache2->get('hash-1'));
    }

    public function testDataRetrievableAfterFlushFromChunkFile(): void
    {
        $cache = $this->makeCache(chunkSize: 2);

        $data0 = $this->tokenData('item-0');
        $data1 = $this->tokenData('item-1');
        $cache->put('hash-0', $data0);
        $cache->put('hash-1', $data1); // triggers flush

        $cache->pruneAndSave();

        // Reload and verify data is retrievable from the chunk file.
        $cache2 = $this->makeCache(chunkSize: 2);
        $this->assertSame($data0, $cache2->get('hash-0'));
        $this->assertSame($data1, $cache2->get('hash-1'));
    }

    // =========================================================================
    // pruneAndSave() removes unused entries
    // =========================================================================

    public function testPruneRemovesUnusedEntries(): void
    {
        $cache = $this->makeCache();
        $cache->put('hash-keep', $this->tokenData('keep'));
        $cache->put('hash-drop', $this->tokenData('drop'));
        $cache->pruneAndSave();

        // Second build: only 'hash-keep' is accessed.
        $cache2 = $this->makeCache();
        $cache2->get('hash-keep'); // mark as used
        $cache2->pruneAndSave();

        $manifest = $this->readManifest();
        $this->assertArrayHasKey('hash-keep', $manifest, 'Used entry must survive pruning');
        $this->assertArrayNotHasKey('hash-drop', $manifest, 'Unused entry must be pruned');
    }

    public function testPruneKeepsEntriesAccessedViaGet(): void
    {
        $cache = $this->makeCache();
        $cache->put('hash-a', $this->tokenData('a'));
        $cache->pruneAndSave();

        // Reload and access hash-a via get().
        $cache2 = $this->makeCache();
        $result = $cache2->get('hash-a');
        $this->assertNotNull($result, 'Cache hit expected');
        $cache2->pruneAndSave();

        $manifest = $this->readManifest();
        $this->assertArrayHasKey('hash-a', $manifest, 'Entry accessed via get() must survive pruning');
    }

    // =========================================================================
    // pruneAndSave() deletes orphaned chunks
    // =========================================================================

    public function testPruneDeletesOrphanedChunkFiles(): void
    {
        $cache = $this->makeCache(chunkSize: 1);
        $cache->put('hash-a', $this->tokenData('a'));
        $cache->put('hash-b', $this->tokenData('b'));
        $cache->pruneAndSave();

        // Verify both chunk files exist after first build.
        $this->assertFileExists($this->chunkFile(0));
        $this->assertFileExists($this->chunkFile(1));

        // Second build: only 'hash-a' is used. hash-b's chunk should be deleted.
        $cache2 = $this->makeCache(chunkSize: 1);
        $cache2->get('hash-a'); // mark used
        $cache2->pruneAndSave();

        // Chunk 0 held hash-a (first flush). Chunk 1 held hash-b (second flush).
        // After pruning, one chunk must be gone.
        $manifest = $this->readManifest();
        $liveChunks = array_unique(array_values($manifest));
        foreach (glob($this->chunkDir() . '/chunk-*.php') as $file) {
            preg_match('/chunk-(\d+)\.php$/', basename($file), $m);
            $this->assertContains(
                (int) $m[1],
                $liveChunks,
                'Orphaned chunk file must be deleted by pruneAndSave()',
            );
        }
    }

    // =========================================================================
    // Corrupted chunk handled gracefully
    // =========================================================================

    public function testCorruptedChunkIsHandledGracefully(): void
    {
        $cache = $this->makeCache(chunkSize: 1);
        $cache->put('hash-a', $this->tokenData('a'));
        $cache->pruneAndSave();

        // Corrupt the chunk file.
        file_put_contents($this->chunkFile(0), 'NOT VALID SERIALIZED DATA');

        // Reload and attempt to get — must return null without throwing.
        $cache2 = $this->makeCache(chunkSize: 1);
        $result = $cache2->get('hash-a');
        $this->assertNull($result, 'Corrupted chunk must return null, not throw');
    }

    public function testCorruptedChunkDoesNotPreventSubsequentPut(): void
    {
        $cache = $this->makeCache(chunkSize: 1);
        $cache->put('hash-a', $this->tokenData('a'));
        $cache->pruneAndSave();

        // Corrupt.
        file_put_contents($this->chunkFile(0), 'CORRUPTED');

        // Reload, get (miss due to corruption), put fresh data, save.
        $cache2   = $this->makeCache(chunkSize: 1);
        $cache2->get('hash-a'); // miss — removes from manifest
        $newData = $this->tokenData('a-fresh');
        $cache2->put('hash-a', $newData);
        $cache2->pruneAndSave();

        // Reload again — must have the fresh data.
        $cache3 = $this->makeCache(chunkSize: 1);
        $this->assertSame($newData, $cache3->get('hash-a'));
    }

    // =========================================================================
    // Corrupted manifest starts fresh
    // =========================================================================

    public function testCorruptedManifestStartsFresh(): void
    {
        // Write a corrupted manifest.
        file_put_contents($this->manifestFile(), 'INVALID MANIFEST');

        $cache = $this->makeCache();
        // get() on a hash that would have been in a valid manifest → miss.
        $this->assertNull($cache->get('any-hash'), 'Corrupted manifest must result in a miss');

        // put() and pruneAndSave() must still work.
        $data = $this->tokenData('fresh');
        $cache->put('fresh-hash', $data);
        $cache->pruneAndSave();

        $manifest = $this->readManifest();
        $this->assertArrayHasKey('fresh-hash', $manifest, 'Fresh entry must be saved after corrupted manifest start');
    }

    // =========================================================================
    // Atomic write (no .tmp files linger)
    // =========================================================================

    public function testNoTmpFilesLingerAfterPruneAndSave(): void
    {
        $cache = $this->makeCache(chunkSize: 2);
        for ($i = 0; $i < 4; $i++) {
            $cache->put("hash-{$i}", $this->tokenData("item-{$i}"));
            $cache->get("hash-{$i}"); // mark used
        }
        $cache->pruneAndSave();

        // No .tmp files should exist anywhere in stateDir.
        $tmpFiles = glob($this->stateDir . '/**/*.tmp.*') ?: [];
        $tmpFiles = array_merge($tmpFiles, glob($this->stateDir . '/*.tmp.*') ?: []);
        $this->assertEmpty($tmpFiles, 'No .tmp files must linger after pruneAndSave()');
    }

    // =========================================================================
    // put() supersedes old chunk entry
    // =========================================================================

    public function testPutSupersededOldChunkEntry(): void
    {
        // First build: put hash-a.
        $cache = $this->makeCache(chunkSize: 10);
        $original = $this->tokenData('original');
        $cache->put('hash-a', $original);
        $cache->pruneAndSave();

        // Second build: put hash-a again with updated data.
        $cache2  = $this->makeCache(chunkSize: 10);
        $updated = $this->tokenData('updated');
        $cache2->put('hash-a', $updated);
        $cache2->pruneAndSave();

        // Third build (reload): get() must return updated data.
        $cache3 = $this->makeCache(chunkSize: 10);
        $result = $cache3->get('hash-a');
        $this->assertSame($updated, $result, 'put() must supersede the previously cached entry');
    }

    // =========================================================================
    // Legacy migration
    // =========================================================================

    public function testLegacyMigrationMovesDataToChunks(): void
    {
        // Write a legacy single-file cache.
        $legacyData = [
            'hash-old-1' => $this->tokenData('legacy-1'),
            'hash-old-2' => $this->tokenData('legacy-2'),
            'hash-old-3' => $this->tokenData('legacy-3'),
        ];
        file_put_contents(
            $this->stateDir . '/page-word-cache.php',
            serialize($legacyData),
        );

        // Constructing PageWordCache must trigger migration.
        $cache = $this->makeCache(chunkSize: 2);

        // All legacy entries must be accessible.
        foreach ($legacyData as $hash => $data) {
            $this->assertSame($data, $cache->get($hash), "Legacy entry {$hash} must be migrated");
        }
    }

    public function testLegacyMigrationDeletesLegacyFile(): void
    {
        $legacyFile = $this->stateDir . '/page-word-cache.php';
        file_put_contents($legacyFile, serialize(['hash-x' => $this->tokenData('x')]));

        $this->makeCache(chunkSize: 10);

        $this->assertFileDoesNotExist($legacyFile, 'Legacy file must be deleted after migration');
    }

    public function testLegacyMigrationCreatesManifest(): void
    {
        $legacyData = [
            'hash-a' => $this->tokenData('a'),
            'hash-b' => $this->tokenData('b'),
        ];
        file_put_contents($this->stateDir . '/page-word-cache.php', serialize($legacyData));

        $this->makeCache(chunkSize: 10);

        $this->assertFileExists($this->manifestFile(), 'Manifest must be created after migration');
        $manifest = $this->readManifest();
        $this->assertArrayHasKey('hash-a', $manifest);
        $this->assertArrayHasKey('hash-b', $manifest);
    }

    // =========================================================================
    // Corrupted legacy migration
    // =========================================================================

    public function testCorruptedLegacyMigrationStartsFresh(): void
    {
        $legacyFile = $this->stateDir . '/page-word-cache.php';
        file_put_contents($legacyFile, 'NOT VALID SERIALIZED DATA');

        // Construction must not throw.
        $cache = $this->makeCache();

        // Cache is empty (corrupted legacy → fresh start).
        $this->assertNull($cache->get('any-hash'), 'Corrupted legacy cache must result in a miss');

        // The corrupted legacy file should be cleaned up.
        $this->assertFileDoesNotExist($legacyFile, 'Corrupted legacy file must be removed');
    }

    public function testCorruptedLegacyMigrationAllowsNormalOperation(): void
    {
        file_put_contents($this->stateDir . '/page-word-cache.php', 'CORRUPTED');

        $cache = $this->makeCache();
        $data  = $this->tokenData('fresh');
        $cache->put('fresh-hash', $data);
        $cache->pruneAndSave();

        // Reload and verify.
        $cache2 = $this->makeCache();
        $this->assertSame($data, $cache2->get('fresh-hash'));
    }
}
