<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Memory regression test for the processChunk() → finalize() pipeline.
 *
 * Catches the specific pattern from #133: if processChunk() accumulates all
 * items' token arrays in a flat list before indexing, peak memory spikes
 * proportionally to corpus size. The streaming fix (generator-based
 * tokenization) keeps only one item's tokens in memory at a time.
 *
 * Threshold is set at 2x measured baseline (~6 MB for 100 pages with sort
 * metadata). The flat-list pattern would spike well beyond this because 100
 * pages × ~36KB token data = ~3.6 MB of token arrays held simultaneously,
 * on top of the inverted index accumulation.
 */
class WriterMemoryRegressionTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $uid = uniqid('', true);
        $this->stateDir = sys_get_temp_dir() . "/scolta-memreg-state-{$uid}";
        $this->outputDir = sys_get_temp_dir() . "/scolta-memreg-out-{$uid}";
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    /**
     * processChunk() + finalize() with 100 pages carrying sortable and metadata
     * must stay under 12 MB peak memory delta.
     *
     * This threshold catches the pre-fix pattern where all token arrays were
     * held in a flat $tokenDataList. With the generator-based streaming fix,
     * measured baseline is ~6 MB; threshold is set at 2x to absorb GC timing
     * variance across platforms.
     */
    public function testProcessChunkWriterPhaseMemoryDoesNotRegress(): void
    {
        $items = $this->makeSortableCorpus(100);

        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        $peakBefore = memory_get_peak_usage(true);

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 100);
        $result = $indexer->finalize();

        $peakAfter = memory_get_peak_usage(true);
        $deltaMb = ($peakAfter - $peakBefore) / 1_048_576;

        $this->assertTrue($result->success, 'Build must succeed: ' . ($result->error ?? ''));

        $maxMb = 12.0;
        $this->assertLessThanOrEqual(
            $maxMb,
            $deltaMb,
            sprintf(
                'Writer phase peak memory delta %.1f MB exceeds %.1f MB threshold. '
                . 'This likely means token arrays are being accumulated instead of streamed.',
                $deltaMb,
                $maxMb,
            ),
        );
    }

    /**
     * Verify sort metadata survives the streaming path — the feature from
     * PR #128 must still produce sort data in the output index.
     */
    public function testSortMetadataPresentInOutputAfterStreamingBuild(): void
    {
        $items = $this->makeSortableCorpus(10);

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 10);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);

        // Verify pagefind-entry.json exists (exit-code gate).
        $entryPath = $this->outputDir . '/pagefind/pagefind-entry.json';
        $this->assertFileExists($entryPath);

        // Verify at least one fragment contains sort field values in meta.
        $fragmentFiles = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertNotEmpty($fragmentFiles);

        $foundSortField = false;
        foreach ($fragmentFiles as $file) {
            $json = preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($file)));
            $data = json_decode($json, true);
            if (isset($data['meta']['price'])) {
                $foundSortField = true;
                break;
            }
        }

        $this->assertTrue($foundSortField, 'Sort field "price" must appear in fragment meta after streaming build');
    }

    /**
     * Build a corpus where every page has sortable and metadata fields populated.
     *
     * @return ContentItem[]
     */
    private function makeSortableCorpus(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = new ContentItem(
                id: "page-{$i}",
                title: "Test Page {$i} with Sort Metadata",
                bodyHtml: '<p>' . str_repeat("Word{$i} content body text for testing memory usage in the indexer pipeline. ", 20) . '</p>',
                url: "https://example.com/page-{$i}",
                date: '2026-01-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                siteName: 'TestSite',
                filters: ['category' => ['cat-' . ($i % 5)], 'tag' => ['tag-' . ($i % 10)]],
                metadata: ['author' => 'Author ' . ($i % 3), 'summary' => str_repeat('Summary text. ', 5)],
                sortable: ['price' => (string) (10.00 + $i * 0.5), 'rating' => (string) (1.0 + ($i % 5) * 0.8)],
            );
        }

        return $items;
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
