<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Tests\Support\CborDecoder;

/**
 * Verify PHP indexer produces word-sequential positions (not character offsets).
 *
 * This is the primary regression test for the position semantics bug.
 * Pagefind uses word indices (0, 1, 2, 3...) not character offsets (0, 6, 12, 20...).
 * If this test fails, phrase proximity scoring is broken.
 */
class PositionSemanticsTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-possem-state-' . bin2hex(random_bytes(8));
        $this->outputDir = sys_get_temp_dir() . '/scolta-possem-output-' . bin2hex(random_bytes(8));
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    /**
     * Core test: positions must be word-sequential indices, not character offsets.
     *
     * Index a page with known content, then verify the position values in the
     * pf_index are small sequential integers matching word indices.
     */
    public function testPositionsAreWordIndices(): void
    {
        // Content: "Quick Brown Fox" (title) + "The quick brown fox jumps over the lazy dog." (body)
        // Expected title word indices: quick=0, brown=1, fox=2
        // Expected body word indices: the=3, quick=4, brown=5, fox=6, jumps=7, over=8, the=9, lazy=10, dog=11
        $items = [
            new ContentItem(
                'test-1',
                'Quick Brown Fox',
                '<p>The quick brown fox jumps over the lazy dog.</p>',
                '/test-page',
                '2026-04-27'
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success);

        $pagefindDir = $this->outputDir . '/pagefind';
        $indexFiles = glob($pagefindDir . '/index/*.pf_index');
        $this->assertNotEmpty($indexFiles);

        $wordPositions = $this->extractWordPositions($indexFiles);

        // 'fox' should appear in body at word index positions
        // Title: Quick(0) Brown(1) Fox(2)
        // Body: The(3) quick(4) brown(5) fox(6) jumps(7) over(8) the(9) lazy(10) dog(11)
        $this->assertArrayHasKey('fox', $wordPositions, "'fox' must be in index");
        $foxData = $wordPositions['fox'];
        $this->assertNotEmpty($foxData, "'fox' must have page entries");

        $foxBodyPositions = $foxData[0]['body_positions'];
        foreach ($foxBodyPositions as $pos) {
            $this->assertLessThan(
                20,
                $pos,
                "Position {$pos} looks like a character offset, not a word index. "
                . "Expected small sequential integers (0-11 for this content)."
            );
        }

        // 'quick' should have word-index positions
        if (isset($wordPositions['quick'])) {
            $quickPositions = $wordPositions['quick'][0]['body_positions'] ?? [];
            foreach ($quickPositions as $pos) {
                $this->assertLessThan(
                    20,
                    $pos,
                    "Position {$pos} for 'quick' looks like a character offset."
                );
            }
        }

        // 'lazi' (stem of 'lazy') should be at position 10
        if (isset($wordPositions['lazi'])) {
            $lazyPositions = $wordPositions['lazi'][0]['body_positions'] ?? [];
            $this->assertContains(10, $lazyPositions, "'lazy' (stem: 'lazi') should be at word index 10");
        }
    }

    /**
     * Title words must NOT appear in body positions.
     */
    public function testTitleWordsNotInBodyPositions(): void
    {
        $items = [
            new ContentItem(
                'test-2',
                'Unique Alpha Beta',
                '<p>Gamma delta epsilon zeta eta.</p>',
                '/test-page-2',
                '2026-04-27'
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success);

        $pagefindDir = $this->outputDir . '/pagefind';
        $indexFiles = glob($pagefindDir . '/index/*.pf_index');
        $wordPositions = $this->extractWordPositions($indexFiles);

        // 'uniqu' (stem of 'unique') should be in meta_positions only.
        // Title: Unique(0) Alpha(1) Beta(2)
        // Body: Gamma(3) delta(4) epsilon(5) zeta(6) eta(7)
        // 'uniqu' must have meta_pos=[0] and body_pos=[] (not in body text).
        if (isset($wordPositions['uniqu'])) {
            $uniqueBodyPos = $wordPositions['uniqu'][0]['body_positions'] ?? [];
            $uniqueMetaPos = $wordPositions['uniqu'][0]['meta_positions'] ?? [];

            $this->assertNotEmpty($uniqueMetaPos, "'unique' should have meta positions");
            $this->assertEmpty(
                $uniqueBodyPos,
                "'unique' is title-only — should NOT have body positions. Got: " . json_encode($uniqueBodyPos)
            );
        }
    }

    /**
     * Word count must exclude URL tokens.
     */
    public function testWordCountExcludesUrlTokens(): void
    {
        $items = [
            new ContentItem(
                'test-3',
                'Simple Title',
                '<p>Body content with several words for testing purposes here today.</p>',
                '/some/complex/url/path',
                '2026-04-27'
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success);

        $pagefindDir = $this->outputDir . '/pagefind';
        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment');
        $this->assertNotEmpty($fragmentFiles);

        $fragment = json_decode(
            preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($fragmentFiles[0]))),
            true
        );

        // content = "Simple Title. Body content with several words for testing purposes here today."
        $contentWordCount = count(explode(' ', $fragment['content']));
        $this->assertSame(
            $contentWordCount,
            $fragment['word_count'],
            "word_count should match content word count (no URL tokens). "
            . "content_words={$contentWordCount}, word_count={$fragment['word_count']}"
        );
    }

    /**
     * Compare PHP positions against Pagefind reference for shared words.
     *
     * For word-sequential indexing, no body position for any word may exceed
     * the word count of its page. With character offsets, positions scale with
     * character count (≈6× word count), so they overflow the word-count bound
     * for any page with > 1 word. This test loads fragment word counts and
     * verifies the invariant directly.
     */
    public function testPositionRangeMatchesReference(): void
    {
        $referenceDir = __DIR__ . '/../fixtures/concordance/reference';
        if (!file_exists($referenceDir . '/pagefind-entry.json')) {
            $this->markTestSkipped('Reference fixtures not generated.');
        }

        $corpusDir = __DIR__ . '/../fixtures/concordance/corpus';
        $items = $this->loadCorpus($corpusDir);
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $indexer->finalize();

        $phpDir = $this->outputDir . '/pagefind';

        // Compute the maximum word count across all PHP fragments. No word-index
        // body position may exceed this value; character offsets for a page of N
        // words would average N×6, well above the word-count cap.
        $maxWordCount = 0;
        foreach (glob($phpDir . '/fragment/*.pf_fragment') ?: [] as $file) {
            $decompressed = gzdecode(file_get_contents($file));
            if ($decompressed === false) {
                continue;
            }
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }
            $frag = json_decode($decompressed, true);
            $maxWordCount = max($maxWordCount, $frag['word_count'] ?? 0);
        }

        $this->assertGreaterThan(0, $maxWordCount, 'Should have at least one fragment with words');

        // URL tokens receive positions after all body tokens (up to maxWordCount + urlTokens).
        // Allow a small margin for URL path segments (typically 2-5 tokens per URL).
        $cap = $maxWordCount + 20;

        $phpPositions = $this->extractWordPositions(glob($phpDir . '/index/*.pf_index') ?: []);
        $overflows = [];
        foreach ($phpPositions as $word => $pages) {
            foreach ($pages as $pageData) {
                foreach ($pageData['body_positions'] as $pos) {
                    if ($pos > $cap) {
                        $overflows[] = sprintf(
                            "'%s': pos=%d > cap=%d (maxWordCount=%d)",
                            $word, $pos, $cap, $maxWordCount
                        );
                    }
                }
            }
        }

        $this->assertEmpty(
            $overflows,
            "Body positions exceed word-count cap (likely character offsets):\n"
            . implode("\n", array_slice($overflows, 0, 20))
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Extract word → [{page, body_positions, meta_positions}] from pf_index files.
     *
     * @param string[] $indexFiles
     * @return array<string, list<array{page: int, body_positions: list<int>, meta_positions: list<int>}>>
     */
    private function extractWordPositions(array $indexFiles): array
    {
        $words = [];
        foreach ($indexFiles as $file) {
            $decoded = CborDecoder::decodePfFile($file);
            $entries = (count($decoded) === 1 && is_array($decoded[0] ?? null))
                ? $decoded[0]
                : $decoded;

            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry[0]) || !is_string($entry[0])) {
                    continue;
                }
                $word = $entry[0];
                $words[$word] = [];
                $prevPage = 0;

                foreach ($entry[1] ?? [] as $pe) {
                    $delta = $pe[0] ?? 0;
                    $absPage = $prevPage + $delta;
                    $prevPage = $absPage;

                    $locs = $pe[1] ?? [];
                    $metaLocs = $pe[2] ?? [];

                    $bodyPos = [];
                    $running = 0;
                    foreach ($locs as $v) {
                        if ($v < 0) {
                            continue;
                        }
                        $running += $v;
                        $bodyPos[] = $running;
                    }

                    $metaPos = [];
                    $running = 0;
                    foreach ($metaLocs as $v) {
                        if ($v < 0) {
                            continue;
                        }
                        $running += $v;
                        $metaPos[] = $running;
                    }

                    $words[$word][] = [
                        'page' => $absPage,
                        'body_positions' => $bodyPos,
                        'meta_positions' => $metaPos,
                    ];
                }
            }
        }
        return $words;
    }

    /** @return ContentItem[] */
    private function loadCorpus(string $corpusDir): array
    {
        $items = [];
        foreach (glob($corpusDir . '/*.html') ?: [] as $file) {
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
            $items[] = new ContentItem($filename, $title, $body, '/' . $filename . '.html', $date, $siteName);
        }
        return $items;
    }

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
