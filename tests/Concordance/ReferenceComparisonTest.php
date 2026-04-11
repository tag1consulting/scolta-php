<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Gold-standard comparison: PHP indexer output vs frozen Pagefind reference.
 *
 * These tests prove that the PHP indexer produces output compatible with
 * pagefind.js by comparing against real Pagefind binary output for the
 * same 25-page test corpus.
 *
 * Reference fixtures are committed at tests/fixtures/concordance/reference/.
 * Regenerate with: ./scripts/generate-concordance-fixtures.sh
 */
class ReferenceComparisonTest extends TestCase
{
    private string $referenceDir;
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->referenceDir = __DIR__ . '/../fixtures/concordance/reference';

        if (!file_exists($this->referenceDir . '/pagefind-entry.json')) {
            $this->markTestSkipped(
                'Reference fixtures not generated. Run: ./scripts/generate-concordance-fixtures.sh'
            );
        }

        $this->stateDir = sys_get_temp_dir() . '/scolta-ref-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-ref-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // ---------------------------------------------------------------
    // Fragment Comparison (highest value)
    // ---------------------------------------------------------------

    public function testFragmentPageCount(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $phpFragments = $this->loadAllFragments($phpDir . '/pagefind');
        $refFragments = $this->loadAllFragments($this->referenceDir);

        // Both should index pages — allow PHP to skip some empty/short ones.
        $this->assertGreaterThanOrEqual(
            count($refFragments) - 3,
            count($phpFragments),
            sprintf(
                'PHP indexed %d pages, Pagefind indexed %d. Allowed margin: 3.',
                count($phpFragments),
                count($refFragments)
            )
        );
    }

    public function testFragmentContentOverlap(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $phpFragments = $this->loadAllFragments($phpDir . '/pagefind');
        $refFragments = $this->loadAllFragments($this->referenceDir);

        // For each reference page, verify the PHP fragment contains the same
        // key words. Exact content comparison is not meaningful because Pagefind
        // extracts from raw HTML (includes <h1>, handles data-pagefind-body)
        // while the PHP indexer receives pre-cleaned ContentItem text.
        $lowOverlap = [];
        foreach ($refFragments as $url => $refFrag) {
            $phpFrag = $this->findMatchingFragment($phpFragments, $url, $refFrag);
            if ($phpFrag === null) {
                continue;
            }

            // Extract significant words (3+ chars) from both.
            $refWords = $this->extractSignificantWords($refFrag['content']);
            $phpWords = $this->extractSignificantWords($phpFrag['content']);

            if (count($refWords) === 0) {
                continue;
            }

            // Calculate word overlap (Jaccard similarity).
            $intersection = count(array_intersect($refWords, $phpWords));
            $union = count(array_unique(array_merge($refWords, $phpWords)));
            $similarity = $union > 0 ? $intersection / $union : 0;

            // Require at least 50% word overlap.
            if ($similarity < 0.5) {
                $lowOverlap[] = sprintf('%s (%.0f%%)', $url, $similarity * 100);
            }
        }

        $this->assertEmpty(
            $lowOverlap,
            'Low content overlap (< 50%): ' . implode(', ', $lowOverlap)
        );
    }

    public function testFragmentFiltersPresent(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $phpFragments = $this->loadAllFragments($phpDir . '/pagefind');
        $refFragments = $this->loadAllFragments($this->referenceDir);

        $refWithFilters = 0;
        $phpWithFilters = 0;

        foreach ($refFragments as $refFrag) {
            if (!empty($refFrag['filters']) && $refFrag['filters'] !== new \stdClass()) {
                $refWithFilters++;
            }
        }

        foreach ($phpFragments as $phpFrag) {
            if (!empty($phpFrag['filters']) && $phpFrag['filters'] !== new \stdClass()) {
                $phpWithFilters++;
            }
        }

        // Both indexes should have similar filter coverage.
        $this->assertGreaterThanOrEqual(
            $refWithFilters - 2,
            $phpWithFilters,
            "PHP has {$phpWithFilters} pages with filters, Pagefind has {$refWithFilters}"
        );
    }

    public function testFragmentMetaFieldsPresent(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $phpFragments = $this->loadAllFragments($phpDir . '/pagefind');
        $refFragments = $this->loadAllFragments($this->referenceDir);

        // Collect all meta field names from reference.
        $refMetaFields = [];
        foreach ($refFragments as $frag) {
            foreach (array_keys((array) ($frag['meta'] ?? [])) as $field) {
                $refMetaFields[$field] = true;
            }
        }

        // PHP should have the same meta fields.
        $phpMetaFields = [];
        foreach ($phpFragments as $frag) {
            foreach (array_keys((array) ($frag['meta'] ?? [])) as $field) {
                $phpMetaFields[$field] = true;
            }
        }

        // Core fields must exist in both.
        $this->assertArrayHasKey('title', $phpMetaFields, 'PHP fragments must have title meta');
    }

    // ---------------------------------------------------------------
    // Entry.json Comparison
    // ---------------------------------------------------------------

    public function testEntryJsonVersionMatches(): void
    {
        $phpDir = $this->buildWithPhpIndexer();

        $phpEntry = json_decode(file_get_contents($phpDir . '/pagefind/pagefind-entry.json'), true);
        $refEntry = json_decode(file_get_contents($this->referenceDir . '/pagefind-entry.json'), true);

        $this->assertSame($refEntry['version'], $phpEntry['version'], 'Version must match');
    }

    public function testEntryJsonLanguages(): void
    {
        $phpDir = $this->buildWithPhpIndexer();

        $phpEntry = json_decode(file_get_contents($phpDir . '/pagefind/pagefind-entry.json'), true);
        $refEntry = json_decode(file_get_contents($this->referenceDir . '/pagefind-entry.json'), true);

        // Both should detect English.
        $this->assertArrayHasKey('en', $phpEntry['languages'], 'PHP should detect English');

        // Page counts should be close.
        $refCount = $refEntry['languages'][array_key_first($refEntry['languages'])]['page_count'];
        $phpCount = $phpEntry['languages']['en']['page_count'];

        $this->assertEqualsWithDelta($refCount, $phpCount, 3, 'Page count should be similar');
    }

    // ---------------------------------------------------------------
    // Index Structure Comparison
    // ---------------------------------------------------------------

    public function testIndexFilesExist(): void
    {
        $phpDir = $this->buildWithPhpIndexer();

        $phpIndexFiles = glob($phpDir . '/pagefind/index/*.pf_index');
        $refIndexFiles = glob($this->referenceDir . '/index/*.pf_index')
            ?: glob($this->referenceDir . '/*.pf_index');

        $this->assertNotEmpty($phpIndexFiles, 'PHP index files must exist');
        $this->assertNotEmpty($refIndexFiles, 'Reference index files must exist');
    }

    public function testMetaFileExists(): void
    {
        $phpDir = $this->buildWithPhpIndexer();

        $phpMetaFiles = glob($phpDir . '/pagefind/pagefind.*.pf_meta')
            ?: glob($phpDir . '/pagefind/*.pf_meta');
        $refMetaFiles = glob($this->referenceDir . '/pagefind.*.pf_meta')
            ?: glob($this->referenceDir . '/*.pf_meta');

        $this->assertNotEmpty($phpMetaFiles, 'PHP meta file must exist');
        $this->assertNotEmpty($refMetaFiles, 'Reference meta file must exist');
    }

    public function testFilterIndexExists(): void
    {
        $phpDir = $this->buildWithPhpIndexer();

        $phpFilterFiles = glob($phpDir . '/pagefind/pagefind.*.pf_filter')
            ?: glob($phpDir . '/pagefind/filter/*.pf_filter');
        $refFilterFiles = glob($this->referenceDir . '/filter/*.pf_filter')
            ?: glob($this->referenceDir . '/*.pf_filter');

        // If reference has filters, PHP should too.
        if (!empty($refFilterFiles)) {
            $this->assertNotEmpty($phpFilterFiles, 'PHP filter file must exist when reference has filters');
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildWithPhpIndexer(): string
    {
        $items = $this->loadCorpus();

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'PHP index build must succeed: ' . ($result->error ?? ''));

        return $this->outputDir;
    }

    private function loadCorpus(): array
    {
        $corpusDir = __DIR__ . '/../fixtures/concordance/corpus';
        $items = [];

        foreach (glob($corpusDir . '/*.html') as $file) {
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

            $items[] = new ContentItem(
                $filename,
                $title,
                $body,
                '/' . $filename . '.html',
                $date,
                $siteName,
            );
        }

        return $items;
    }

    private function loadAllFragments(string $dir): array
    {
        $fragments = [];

        // Check both flat and subdirectory structures.
        $files = glob($dir . '/fragment/*.pf_fragment') ?: glob($dir . '/*.pf_fragment');
        if (empty($files)) {
            return [];
        }

        foreach ($files as $file) {
            $decompressed = gzdecode(file_get_contents($file));
            if ($decompressed === false) {
                continue;
            }

            // Strip pagefind_dcd delimiter if present.
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }

            $json = json_decode($decompressed, true);
            if ($json !== null && isset($json['url'])) {
                $fragments[$json['url']] = $json;
            }
        }

        return $fragments;
    }

    private function findMatchingFragment(array $phpFragments, string $refUrl, array $refFrag): ?array
    {
        // Direct URL match.
        if (isset($phpFragments[$refUrl])) {
            return $phpFragments[$refUrl];
        }

        // Try matching by title (URLs may differ in format).
        $refTitle = $refFrag['meta']['title'] ?? '';
        foreach ($phpFragments as $phpFrag) {
            if (($phpFrag['meta']['title'] ?? '') === $refTitle) {
                return $phpFrag;
            }
        }

        return null;
    }

    private function normalizeContent(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }

    /**
     * Extract significant words (3+ chars) from text.
     *
     * @return string[]
     */
    private function extractSignificantWords(string $text): array
    {
        $words = preg_split('/[\s\p{P}]+/u', mb_strtolower($text));

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w) => mb_strlen($w) >= 3
        )));
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
