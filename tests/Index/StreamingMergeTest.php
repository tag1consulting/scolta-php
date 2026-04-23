<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\ChunkWriter;
use Tag1\Scolta\Index\IndexMerger;
use Tag1\Scolta\Index\StreamingFormatWriter;

/**
 * Integration tests for the streaming merge pipeline.
 *
 * Verifies that mergeStreaming() + StreamingFormatWriter produce the same
 * Pagefind-compatible output as the legacy merge() + PagefindFormatWriter path.
 */
class StreamingMergeTest extends TestCase
{
    private string $tmpDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->tmpDir    = sys_get_temp_dir() . '/scolta-streaming-merge-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-streaming-out-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        $this->removeDir($this->outputDir);
    }

    private function writeChunk(int $num, array $partial): string
    {
        $path = $this->tmpDir . sprintf('/chunk-%03d.dat', $num);
        (new ChunkWriter())->write($path, $partial);

        return $path;
    }

    public function testMergeStreamingProducesFragmentFiles(): void
    {
        $path0 = $this->writeChunk(0, [
            'pages' => [
                0 => ['url' => '/a', 'wordCount' => 5, 'content' => 'hello world', 'meta' => ['title' => 'A'], 'filters' => []],
            ],
            'index' => [
                'hello' => [0 => ['positions' => [25 => [0]], 'meta_positions' => []]],
            ],
        ]);

        $path1 = $this->writeChunk(1, [
            'pages' => [
                1 => ['url' => '/b', 'wordCount' => 3, 'content' => 'world foo', 'meta' => ['title' => 'B'], 'filters' => []],
            ],
            'index' => [
                'world' => [1 => ['positions' => [25 => [0]], 'meta_positions' => []]],
                'foo'   => [1 => ['positions' => [25 => [1]], 'meta_positions' => []]],
            ],
        ]);

        $writer = new StreamingFormatWriter(new CborEncoder());
        $writer->beginWrite($this->outputDir);

        $merger = new IndexMerger();
        $merger->mergeStreaming([$path0, $path1], $writer);
        $writer->endWrite();

        $buildDir = $this->outputDir . '/.scolta-building';

        // Fragment files for each page.
        $fragments = glob($buildDir . '/fragment/*.pf_fragment');
        $this->assertCount(2, $fragments);

        // Index files produced.
        $indexFiles = glob($buildDir . '/index/*.pf_index');
        $this->assertNotEmpty($indexFiles);

        // Entry JSON written.
        $this->assertFileExists($buildDir . '/pagefind-entry.json');
        $entry = json_decode(file_get_contents($buildDir . '/pagefind-entry.json'), true);
        $this->assertSame(2, $entry['languages']['en']['page_count']);
    }

    public function testMergeStreamingMergesOverlappingTermsAcrossChunks(): void
    {
        // Both chunks have the same term 'common' pointing to different pages.
        $path0 = $this->writeChunk(0, [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 2, 'content' => 'common text', 'meta' => ['title' => 'A'], 'filters' => []]],
            'index' => ['common' => [0 => ['positions' => [25 => [0]], 'meta_positions' => []]]],
        ]);
        $path1 = $this->writeChunk(1, [
            'pages' => [1 => ['url' => '/b', 'wordCount' => 2, 'content' => 'common stuff', 'meta' => ['title' => 'B'], 'filters' => []]],
            'index' => ['common' => [1 => ['positions' => [25 => [0]], 'meta_positions' => []]]],
        ]);

        $receivedTerms = [];
        $writerSpy     = new class (new CborEncoder()) extends StreamingFormatWriter {
            public array $seenTerms = [];

            public function writeTerm(string $term, array $termData): void
            {
                $this->seenTerms[] = [$term, array_keys(array_filter($termData, fn ($k) => $k !== '_variants', ARRAY_FILTER_USE_KEY))];
                parent::writeTerm($term, $termData);
            }
        };

        $writerSpy->beginWrite($this->outputDir);
        (new IndexMerger())->mergeStreaming([$path0, $path1], $writerSpy);
        $writerSpy->endWrite();

        // 'common' should appear exactly once with two page entries.
        $commonEntries = array_filter($writerSpy->seenTerms, fn ($t) => $t[0] === 'common');
        $this->assertCount(1, $commonEntries, "'common' should be merged into one term entry");

        $entry = array_values($commonEntries)[0];
        $this->assertCount(2, $entry[1], "'common' should reference pages 0 and 1");
    }

    public function testMergeStreamingTermsInAlphabeticalOrder(): void
    {
        $path0 = $this->writeChunk(0, [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 1, 'content' => 'x', 'meta' => ['title' => 'A'], 'filters' => []]],
            'index' => [
                'zebra' => [0 => ['positions' => [25 => [0]], 'meta_positions' => []]],
                'apple' => [0 => ['positions' => [25 => [1]], 'meta_positions' => []]],
            ],
        ]);
        $path1 = $this->writeChunk(1, [
            'pages' => [1 => ['url' => '/b', 'wordCount' => 1, 'content' => 'y', 'meta' => ['title' => 'B'], 'filters' => []]],
            'index' => [
                'mango' => [1 => ['positions' => [25 => [0]], 'meta_positions' => []]],
                'banana' => [1 => ['positions' => [25 => [1]], 'meta_positions' => []]],
            ],
        ]);

        $termsInOrder = [];
        $writerSpy    = new class (new CborEncoder()) extends StreamingFormatWriter {
            public array $termOrder = [];

            public function writeTerm(string $term, array $termData): void
            {
                $this->termOrder[] = $term;
                parent::writeTerm($term, $termData);
            }
        };

        $writerSpy->beginWrite($this->outputDir);
        (new IndexMerger())->mergeStreaming([$path0, $path1], $writerSpy);
        $writerSpy->endWrite();

        $sorted = $writerSpy->termOrder;
        sort($sorted);
        $this->assertSame($sorted, $writerSpy->termOrder, 'Terms must arrive in alphabetical order');
    }

    public function testMergeStreamingWithVariants(): void
    {
        $path0 = $this->writeChunk(0, [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 2, 'content' => 'cafe', 'meta' => ['title' => 'A'], 'filters' => []]],
            'index' => [
                'cafe' => [
                    0 => ['positions' => [25 => [0]], 'meta_positions' => []],
                    '_variants' => ['café' => [0]],
                ],
            ],
        ]);
        $path1 = $this->writeChunk(1, [
            'pages' => [1 => ['url' => '/b', 'wordCount' => 2, 'content' => 'café', 'meta' => ['title' => 'B'], 'filters' => []]],
            'index' => [
                'cafe' => [
                    1 => ['positions' => [25 => [0]], 'meta_positions' => []],
                    '_variants' => ['café' => [1]],
                ],
            ],
        ]);

        $mergedTerms = [];
        $writerSpy   = new class (new CborEncoder()) extends StreamingFormatWriter {
            public array $capturedTerms = [];

            public function writeTerm(string $term, array $termData): void
            {
                $this->capturedTerms[$term] = $termData;
                parent::writeTerm($term, $termData);
            }
        };

        $writerSpy->beginWrite($this->outputDir);
        (new IndexMerger())->mergeStreaming([$path0, $path1], $writerSpy);
        $writerSpy->endWrite();

        $this->assertArrayHasKey('cafe', $writerSpy->capturedTerms);
        $cafe = $writerSpy->capturedTerms['cafe'];
        $this->assertArrayHasKey('_variants', $cafe);
        $this->assertSame([0, 1], $cafe['_variants']['café']);
    }

    public function testMergeStreamingWithTenChunks(): void
    {
        $chunkPaths = [];
        for ($i = 0; $i < 10; $i++) {
            $chunkPaths[] = $this->writeChunk($i, [
                'pages' => [
                    $i => ['url' => "/p/{$i}", 'wordCount' => 2, 'content' => "content {$i}", 'meta' => ['title' => "P{$i}"], 'filters' => []],
                ],
                'index' => [
                    'shared' => [$i => ['positions' => [25 => [$i]], 'meta_positions' => []]],
                    "word{$i}" => [$i => ['positions' => [25 => [0]], 'meta_positions' => []]],
                ],
            ]);
        }

        $writer = new StreamingFormatWriter(new CborEncoder());
        $writer->beginWrite($this->outputDir);
        (new IndexMerger())->mergeStreaming($chunkPaths, $writer);
        $writer->endWrite();

        $buildDir = $this->outputDir . '/.scolta-building';
        $entry    = json_decode(file_get_contents($buildDir . '/pagefind-entry.json'), true);

        $this->assertSame(10, $entry['languages']['en']['page_count']);
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
