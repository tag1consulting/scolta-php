<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\DeltaEncoder;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Tests\Support\CborDecoder;

/**
 * Integration tests for delta encoding in the serialized pf_index format.
 *
 * @since 0.3.0
 * @stability experimental
 */
class DeltaEncodingIntegrationTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-delta-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-delta-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // ---------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------

    /**
     * Build a two-page index and verify delta encoding of page refs for "cat".
     *
     * Page 0: "The cat sat on the mat" — contains cat
     * Page 1: "A cat and a dog"        — contains cat
     *
     * After delta encoding:
     *   - first page ref for "cat" must be 0 (absolute)
     *   - second page ref must be 1 (delta = 1 − 0 = 1)
     * Decoded absolute page numbers must be [0, 1].
     */
    public function testDeltaEncodingInSerializedIndex(): void
    {
        $stemmer = new Stemmer('en');
        $catStem = $stemmer->stem('cat');

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk([
            new ContentItem('page0', 'Page Zero', '<p>The cat sat on the mat</p>', '/page0', ''),
            new ContentItem('page1', 'Page One', '<p>A cat and a dog</p>', '/page1', ''),
        ], 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success, 'Index build must succeed: ' . ($result->error ?? ''));

        $pagefindDir = $this->outputDir . '/pagefind';
        $indexFiles = glob($pagefindDir . '/index/*.pf_index') ?: [];
        $this->assertNotEmpty($indexFiles, 'Must produce at least one pf_index file');

        // Find the entry for the stemmed form of "cat".
        $catEntry = null;
        foreach ($indexFiles as $indexFile) {
            $decoded = CborDecoder::decodePfFile($indexFile);
            $entries = $decoded[0] ?? [];
            foreach ($entries as $entry) {
                if ($entry[0] === $catStem) {
                    $catEntry = $entry;
                    break 2;
                }
            }
        }

        $this->assertNotNull($catEntry, "No posting list entry found for stemmed 'cat' ('{$catStem}')");

        $pageRefs = $catEntry[1];
        $this->assertCount(2, $pageRefs, 'Cat must appear in exactly 2 pages');

        // Decode absolute page numbers.
        $absPages = [];
        $running = 0;
        foreach ($pageRefs as $pageRef) {
            // pageRef = [delta_int, locs_array, meta_locs_array]
            $delta = $pageRef[0];
            $running += $delta;
            $absPages[] = $running;
        }

        $this->assertContains(0, $absPages, 'Page 0 must appear in cat posting list');
        $this->assertContains(1, $absPages, 'Page 1 must appear in cat posting list');

        // Verify raw delta values before summing.
        // First page ref: must be 0 (absolute page 0).
        $this->assertSame(0, $pageRefs[0][0], 'First delta must be 0 (absolute page 0)');
        // Second page ref: must be 1 (delta = 1 − 0 = 1).
        $this->assertSame(1, $pageRefs[1][0], 'Second delta must be 1 (page 1 − page 0 = 1)');
    }

    /**
     * Unit tests for DeltaEncoder::deltaEncode().
     */
    public function testDeltaEncodeAndDecode(): void
    {
        $this->assertSame(
            [3, 2, 3, 5, 8],
            DeltaEncoder::deltaEncode([3, 5, 8, 13, 21]),
            'deltaEncode([3, 5, 8, 13, 21]) must return [3, 2, 3, 5, 8]'
        );

        $this->assertSame(
            [0, 1, 1],
            DeltaEncoder::deltaEncode([0, 1, 2]),
            'deltaEncode([0, 1, 2]) must return [0, 1, 1]'
        );

        $this->assertSame(
            [],
            DeltaEncoder::deltaEncode([]),
            'deltaEncode([]) must return []'
        );

        $this->assertSame(
            [7],
            DeltaEncoder::deltaEncode([7]),
            'deltaEncode([7]) must return [7]'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
