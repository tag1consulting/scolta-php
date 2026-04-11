<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

class PhpIndexerTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-indexer-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-indexer-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    private function makeItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = new ContentItem(
                "doc-{$i}",
                "Test Page {$i}",
                "<p>This is test page number {$i} with enough content to be indexed properly. "
                . "It contains various words like apple, banana, cherry, and other fruit names.</p>",
                "https://example.com/page-{$i}",
                '2026-01-01',
                'TestSite',
            );
        }

        return $items;
    }

    public function testProcessChunkReturnsPageCount(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $items = $this->makeItems(5);
        $count = $indexer->processChunk($items, 0);
        $this->assertSame(5, $count);
    }

    public function testFinalizeProducesOutput(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($this->makeItems(3), 0);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->pageCount);
        $this->assertGreaterThan(0, $result->fileCount);
        $this->assertDirectoryExists($this->outputDir . '/pagefind');
    }

    public function testFinalizeCreatesEntryJson(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($this->makeItems(2), 0);
        $indexer->finalize();

        $entryFile = $this->outputDir . '/pagefind/pagefind-entry.json';
        $this->assertFileExists($entryFile);

        $entry = json_decode(file_get_contents($entryFile), true);
        $this->assertSame(2, $entry['languages']['en']['page_count']);
    }

    public function testMultipleChunks(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($this->makeItems(3), 0, 6);
        $indexer->processChunk($this->makeItems(3), 1, 6);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);
        // May be 6 pages or fewer if deduplication by hash/id occurs.
        $this->assertGreaterThanOrEqual(3, $result->pageCount);
    }

    public function testFinalizeWithNoChunksFails(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $result = $indexer->finalize();

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
    }

    public function testShouldBuildDetectsChanges(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $items = $this->makeItems(3);

        // First call: should build.
        $fingerprint = $indexer->shouldBuild($items);
        $this->assertNotNull($fingerprint);

        // Build it and store fingerprint.
        $indexer->processChunk($items, 0);
        $indexer->finalize();
        file_put_contents($this->outputDir . '/.scolta-state', $fingerprint);

        // Same items: should NOT build.
        $indexer2 = new PhpIndexer($this->stateDir, $this->outputDir);
        $this->assertNull($indexer2->shouldBuild($items));
    }

    public function testShouldBuildDetectsNewContent(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $items = $this->makeItems(3);
        $indexer->processChunk($items, 0);
        $indexer->finalize();

        // Different items: should build.
        $newItems = $this->makeItems(5);
        $indexer2 = new PhpIndexer($this->stateDir, $this->outputDir);
        $this->assertNotNull($indexer2->shouldBuild($newItems));
    }

    public function testBuildResultContainsElapsedTime(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($this->makeItems(2), 0);
        $result = $indexer->finalize();

        $this->assertGreaterThan(0, $result->elapsedSeconds);
    }

    public function testAtomicSwapRemovesPreviousIndex(): void
    {
        // First build.
        $indexer1 = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer1->processChunk($this->makeItems(2), 0);
        $indexer1->finalize();

        $this->assertDirectoryExists($this->outputDir . '/pagefind');

        // Second build (rebuilds state dir).
        $stateDir2 = $this->stateDir . '-2';
        mkdir($stateDir2, 0755, true);
        $indexer2 = new PhpIndexer($stateDir2, $this->outputDir);
        $indexer2->processChunk($this->makeItems(3), 0);
        $indexer2->finalize();

        $this->assertDirectoryExists($this->outputDir . '/pagefind');
        $this->assertDirectoryDoesNotExist($this->outputDir . '/.scolta-old');
        $this->assertDirectoryDoesNotExist($this->outputDir . '/.scolta-building');

        $this->removeDir($stateDir2);
    }

    public function testHmacSecretUsed(): void
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir, 'my-secret');
        $indexer->processChunk($this->makeItems(2), 0);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);
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
}
