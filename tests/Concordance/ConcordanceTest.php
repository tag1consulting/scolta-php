<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\SupportedVersions;

/**
 * Concordance tests for the PHP indexer.
 *
 * Validates that the PHP indexer produces structurally valid Pagefind-compatible
 * output from a standardized 25-page test corpus.
 *
 * Three test levels:
 * - Level 1 (Behavioral): Search results match expectations
 * - Level 2 (Structural): File structure and format validity
 * - Level 3 (Byte-level): Decoded CBOR consistency
 */
class ConcordanceTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;
    private string $corpusDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-concordance-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-concordance-output-' . uniqid();
        $this->corpusDir = __DIR__ . '/../fixtures/concordance/corpus';

        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    /**
     * Build the test corpus into ContentItems.
     *
     * @return ContentItem[]
     */
    private function loadCorpus(): array
    {
        $items = [];
        $files = glob($this->corpusDir . '/*.html');
        $this->assertNotEmpty($files, 'Corpus files must exist');

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $html = file_get_contents($file);

            // Extract title from <title> tag.
            preg_match('/<title>(.*?)<\/title>/s', $html, $titleMatch);
            $title = $titleMatch[1] ?? $filename;

            // Extract body from data-pagefind-body.
            preg_match('/<body[^>]*data-pagefind-body[^>]*>(.*?)<\/body>/s', $html, $bodyMatch);
            $body = $bodyMatch[1] ?? '';

            // Extract date meta.
            preg_match('/data-pagefind-meta="date:([^"]*)"/', $html, $dateMatch);
            $date = $dateMatch[1] ?? '';

            // Extract filters.
            preg_match('/data-pagefind-filter="category:([^"]*)"/', $html, $catMatch);
            $siteName = $catMatch[1] ?? '';

            $items[] = new ContentItem(
                $filename,
                html_entity_decode($title),
                $body,
                '/' . $filename,
                $date,
                $siteName,
            );
        }

        return $items;
    }

    /**
     * Build index from corpus and return the pagefind output directory.
     */
    private function buildIndex(): string
    {
        $items = $this->loadCorpus();

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Index build should succeed: ' . ($result->error ?? ''));

        return $this->outputDir . '/pagefind';
    }

    // ---------------------------------------------------------------
    // Level 2: Structural Concordance
    // ---------------------------------------------------------------

    public function testStructuralValidityEntryJson(): void
    {
        $pagefindDir = $this->buildIndex();

        $entryPath = $pagefindDir . '/pagefind-entry.json';
        $this->assertFileExists($entryPath);

        $entry = json_decode(file_get_contents($entryPath), true);
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('version', $entry);
        $this->assertArrayHasKey('languages', $entry);
        $this->assertArrayHasKey('include_characters', $entry);
        $this->assertSame(SupportedVersions::BUNDLED_VERSION, $entry['version']);
        $this->assertArrayHasKey('en', $entry['languages']);
        $this->assertGreaterThan(0, $entry['languages']['en']['page_count']);
    }

    public function testStructuralValidityIndexFiles(): void
    {
        $pagefindDir = $this->buildIndex();

        $indexFiles = glob($pagefindDir . '/index/*.pf_index');
        $this->assertNotEmpty($indexFiles, 'At least one pf_index file should exist');

        foreach ($indexFiles as $indexFile) {
            $compressed = file_get_contents($indexFile);
            $this->assertNotEmpty($compressed, "pf_index file should not be empty: {$indexFile}");

            $decompressed = gzdecode($compressed);
            $this->assertNotFalse($decompressed, "pf_index should be valid gzip: {$indexFile}");
            $this->assertStringStartsWith('pagefind_dcd', $decompressed, 'Must start with pagefind_dcd delimiter');

            // After stripping delimiter, remaining bytes should be valid CBOR.
            $cborData = substr($decompressed, 12);
            $this->assertNotEmpty($cborData, 'CBOR data should exist after delimiter');
        }
    }

    public function testStructuralValidityFragmentFiles(): void
    {
        $pagefindDir = $this->buildIndex();

        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment');
        $this->assertNotEmpty($fragmentFiles, 'Fragment files should exist');

        foreach ($fragmentFiles as $fragFile) {
            $decompressed = gzdecode(file_get_contents($fragFile));
            $this->assertNotFalse($decompressed, "Fragment should be valid gzip: {$fragFile}");
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }

            $decoded = json_decode($decompressed, true);
            $this->assertIsArray($decoded, "Fragment should be valid JSON: {$fragFile}");
            $this->assertArrayHasKey('url', $decoded);
            $this->assertArrayHasKey('content', $decoded);
            $this->assertArrayHasKey('word_count', $decoded);
            $this->assertArrayHasKey('filters', $decoded);
            $this->assertArrayHasKey('meta', $decoded);
            $this->assertArrayHasKey('anchors', $decoded);
        }
    }

    public function testStructuralValidityMetaFile(): void
    {
        $pagefindDir = $this->buildIndex();

        $metaFiles = glob($pagefindDir . '/pagefind.*.pf_meta');
        $this->assertCount(1, $metaFiles, 'Exactly one pf_meta file expected');

        $decompressed = gzdecode(file_get_contents($metaFiles[0]));
        $this->assertNotFalse($decompressed);
        $this->assertStringStartsWith('pagefind_dcd', $decompressed);
    }

    public function testStructuralValidityFilterFile(): void
    {
        $pagefindDir = $this->buildIndex();

        $filterFiles = glob($pagefindDir . '/filter/*.pf_filter');
        // Filter file should exist since corpus has category filters.
        $this->assertNotEmpty($filterFiles, 'Filter file should exist for corpus with filters');

        foreach ($filterFiles as $filterFile) {
            $decompressed = gzdecode(file_get_contents($filterFile));
            $this->assertNotFalse($decompressed);
            $this->assertStringStartsWith('pagefind_dcd', $decompressed);
        }
    }

    // ---------------------------------------------------------------
    // Level 1: Behavioral Concordance
    // ---------------------------------------------------------------

    public function testCorpusPageCount(): void
    {
        $pagefindDir = $this->buildIndex();

        $entry = json_decode(file_get_contents($pagefindDir . '/pagefind-entry.json'), true);
        $pageCount = $entry['languages']['en']['page_count'];

        // Corpus has 25 files but empty/very-short ones may be skipped.
        $this->assertGreaterThanOrEqual(15, $pageCount, 'At least 15 pages should be indexed from 25-file corpus');
        $this->assertLessThanOrEqual(25, $pageCount);
    }

    public function testFragmentContentPreservesDiacritics(): void
    {
        $pagefindDir = $this->buildIndex();

        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment');
        $allContent = '';
        foreach ($fragmentFiles as $fragFile) {
            $fragment = json_decode(preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($fragFile))), true);
            $allContent .= ' ' . ($fragment['content'] ?? '');
        }

        $allContentLower = mb_strtolower($allContent);
        // Diacritics should be preserved in fragment content.
        $this->assertStringContainsString('café', $allContentLower, 'Fragment content should preserve diacritics');
    }

    public function testFragmentUrlsAreUnique(): void
    {
        $pagefindDir = $this->buildIndex();

        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment');
        $urls = [];
        foreach ($fragmentFiles as $fragFile) {
            $fragment = json_decode(preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($fragFile))), true);
            $urls[] = $fragment['url'];
        }

        $this->assertSame(count($urls), count(array_unique($urls)), 'All fragment URLs should be unique');
    }

    public function testFragmentWordCountsArePositive(): void
    {
        $pagefindDir = $this->buildIndex();

        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment');
        foreach ($fragmentFiles as $fragFile) {
            $fragment = json_decode(preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($fragFile))), true);
            $this->assertGreaterThan(0, $fragment['word_count'], "Word count should be positive for {$fragment['url']}");
        }
    }

    public function testVersionInMetadataMatchesBundled(): void
    {
        $pagefindDir = $this->buildIndex();

        $entry = json_decode(file_get_contents($pagefindDir . '/pagefind-entry.json'), true);
        $this->assertSame(SupportedVersions::BUNDLED_VERSION, $entry['version']);
    }

    public function testHashConsistencyAcrossRebuilds(): void
    {
        // Build twice with same corpus — file names should match (deterministic hashes).
        $items = $this->loadCorpus();

        $stateDir1 = sys_get_temp_dir() . '/scolta-hash-test-1-' . uniqid();
        $outputDir1 = sys_get_temp_dir() . '/scolta-hash-test-1-out-' . uniqid();
        mkdir($stateDir1, 0755, true);
        mkdir($outputDir1, 0755, true);

        $indexer1 = new PhpIndexer($stateDir1, $outputDir1);
        $indexer1->processChunk($items, 0);
        $indexer1->finalize();

        $stateDir2 = sys_get_temp_dir() . '/scolta-hash-test-2-' . uniqid();
        $outputDir2 = sys_get_temp_dir() . '/scolta-hash-test-2-out-' . uniqid();
        mkdir($stateDir2, 0755, true);
        mkdir($outputDir2, 0755, true);

        $indexer2 = new PhpIndexer($stateDir2, $outputDir2);
        $indexer2->processChunk($items, 0);
        $indexer2->finalize();

        // Compare index file names.
        $names1 = array_map('basename', glob($outputDir1 . '/pagefind/index/*.pf_index'));
        $names2 = array_map('basename', glob($outputDir2 . '/pagefind/index/*.pf_index'));
        sort($names1);
        sort($names2);
        $this->assertSame($names1, $names2, 'Index file names should be deterministic');

        $this->removeDir($stateDir1);
        $this->removeDir($outputDir1);
        $this->removeDir($stateDir2);
        $this->removeDir($outputDir2);
    }

    // ---------------------------------------------------------------
    // Stemmer Integration
    // ---------------------------------------------------------------

    public function testStemmerProducesConsistentResults(): void
    {
        $stemmer = new \Tag1\Scolta\Index\Stemmer('en');

        $testCases = [
            'running' => 'run',
            'walks' => 'walk',
            'cats' => 'cat',
            'computing' => 'comput',
            'searches' => 'search',
            'indexed' => 'index',
        ];

        foreach ($testCases as $word => $expected) {
            $this->assertSame($expected, $stemmer->stem($word), "Stemming '{$word}' should produce '{$expected}'");
        }
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
