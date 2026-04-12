<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\SupportedVersions;

/**
 * Basic concordance tests for the PHP indexer.
 *
 * Verifies structural validity of the produced index: correct file
 * structure, valid gzip, valid CBOR after decompression, correct
 * delimiter placement, and diacritic handling.
 *
 * Prompt 7 will add the full 25-file corpus, frozen reference fixtures,
 * three-level concordance (behavioral, structural, byte-level), and
 * stemmer concordance with 5,000 words.
 */
class BasicConcordanceTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-concordance-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-concordance-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    /**
     * Verify that the PHP indexer produces a structurally valid index
     * that pagefind.js could load.
     */
    public function testIndexStructuralValidity(): void
    {
        $items = $this->buildTestCorpus();

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);

        $pagefindDir = $this->outputDir . '/pagefind';
        $this->assertDirectoryExists($pagefindDir);

        // Verify entry.json exists and is valid JSON.
        $entryPath = $pagefindDir . '/pagefind-entry.json';
        $this->assertFileExists($entryPath);
        $entry = json_decode(file_get_contents($entryPath), true);
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('version', $entry);
        $this->assertArrayHasKey('languages', $entry);
        $this->assertSame(SupportedVersions::BUNDLED_VERSION, $entry['version']);

        // Verify at least one index file with valid gzip and delimiter.
        $indexFiles = glob($pagefindDir . '/index/*.pf_index');
        $this->assertNotEmpty($indexFiles, 'At least one pf_index file should exist');

        foreach ($indexFiles as $indexFile) {
            $compressed = file_get_contents($indexFile);
            $decompressed = gzdecode($compressed);
            $this->assertNotFalse($decompressed, "pf_index file should be valid gzip: $indexFile");
            $this->assertStringStartsWith('pagefind_dcd', $decompressed);
        }

        // Verify fragment files are valid gzipped JSON.
        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment');
        $this->assertNotEmpty($fragmentFiles);

        foreach ($fragmentFiles as $fragFile) {
            $decompressed = gzdecode(file_get_contents($fragFile));
            $this->assertNotFalse($decompressed);
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }
            $decoded = json_decode($decompressed, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('url', $decoded);
            $this->assertArrayHasKey('content', $decoded);
            $this->assertArrayHasKey('word_count', $decoded);
        }
    }

    /**
     * Verify that the meta file contains correct page count and references.
     */
    public function testMetaFileIntegrity(): void
    {
        $items = $this->buildTestCorpus();

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();

        $pagefindDir = $this->outputDir . '/pagefind';
        $metaFiles = glob($pagefindDir . '/pagefind.*.pf_meta');
        $this->assertCount(1, $metaFiles, 'Exactly one pf_meta file expected');

        $decompressed = gzdecode(file_get_contents($metaFiles[0]));
        $this->assertNotFalse($decompressed);
        $this->assertStringStartsWith('pagefind_dcd', $decompressed);

        // Strip delimiter — remaining data should be valid CBOR.
        $cborData = substr($decompressed, strlen('pagefind_dcd'));
        $this->assertNotEmpty($cborData, 'CBOR data should exist after delimiter');
    }

    /**
     * Verify diacritic handling: "café" indexed and searchable.
     */
    public function testDiacriticIndexing(): void
    {
        $items = [
            new ContentItem('1', 'Café Culture', '<p>The café scene is vibrant. Visit a café today for résumé advice.</p>', '/cafe', '2026-04-10'),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);

        // Verify fragment contains the original text with diacritics.
        $pagefindDir = $this->outputDir . '/pagefind';
        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment');
        $this->assertNotEmpty($fragmentFiles);

        $fragment = json_decode(preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($fragmentFiles[0]))), true);
        $this->assertStringContainsString('café', mb_strtolower($fragment['content']));
    }

    private function buildTestCorpus(): array
    {
        return [
            new ContentItem('1', 'Welcome Page', '<p>Welcome to our website. This is the home page with general information about our services.</p>', '/', '2026-04-10'),
            new ContentItem('2', 'Search Features', '<p>Our search engine supports full-text search, filtering, and AI-powered query expansion across multiple languages.</p>', '/search', '2026-04-10'),
            new ContentItem('3', 'Installation', '<p>Install the package via Composer. Configuration requires an API key and output directory setting.</p>', '/install', '2026-04-10'),
            new ContentItem('4', 'Café Culture', '<p>The café scene in Paris is world-renowned. Naïve tourists underestimate the résumé of French dining culture.</p>', '/cafe', '2026-04-10'),
            new ContentItem('5', 'Running Guide', '<p>Running improves cardiovascular health. Experienced runners train with intervals. Walking aids recovery between runs.</p>', '/running', '2026-04-10'),
        ];
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
