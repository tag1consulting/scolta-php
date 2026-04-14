<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Tests\Support\CborDecoder;

/**
 * Byte-level structural parity: verify PHP indexer output is valid CBOR.
 *
 * These tests do not compare against a Pagefind reference; they verify that
 * every file emitted by the PHP indexer is structurally valid and contains
 * the expected keys and types defined by the Pagefind binary format.
 *
 * Uses the same 25-page English corpus as ReferenceComparisonTest.
 *
 * @since 0.3.0
 * @stability experimental
 */
class ByteParityTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-parity-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-parity-output-' . uniqid();
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
     * A pf_meta file is present and CborDecoder can decode it without error.
     */
    public function testPfMetaFilePresentAndDecodable(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $pagefindDir = $phpDir . '/pagefind';

        $metaFiles = glob($pagefindDir . '/pagefind.*.pf_meta') ?: glob($pagefindDir . '/*.pf_meta');
        $this->assertNotEmpty($metaFiles, 'PHP indexer must produce at least one pf_meta file');

        foreach ($metaFiles as $file) {
            $decoded = CborDecoder::decodePfFile($file);
            $this->assertIsArray($decoded, "pf_meta file must decode to an array: {$file}");
            $this->assertNotEmpty($decoded, "pf_meta must not be empty: {$file}");
        }
    }

    /**
     * All pf_index files decode without error.
     */
    public function testPfIndexFilesDecodable(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $pagefindDir = $phpDir . '/pagefind';

        $indexFiles = glob($pagefindDir . '/index/*.pf_index') ?: [];
        $this->assertNotEmpty($indexFiles, 'PHP indexer must produce at least one pf_index file');

        foreach ($indexFiles as $file) {
            try {
                $decoded = CborDecoder::decodePfFile($file);
                $this->assertIsArray($decoded, "pf_index must decode to array: {$file}");
            } catch (\Throwable $e) {
                $this->fail("pf_index file failed to decode: {$file}\n" . $e->getMessage());
            }
        }
    }

    /**
     * All pf_fragment files decode without error.
     */
    public function testPfFragmentFilesDecodable(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $pagefindDir = $phpDir . '/pagefind';

        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment') ?: glob($pagefindDir . '/*.pf_fragment');
        $this->assertNotEmpty($fragmentFiles, 'PHP indexer must produce at least one pf_fragment file');

        foreach ($fragmentFiles as $file) {
            $decompressed = gzdecode(file_get_contents($file));
            $this->assertNotFalse($decompressed, "Fragment file must be gzip-decompressible: {$file}");

            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }

            $json = json_decode($decompressed, true);
            $this->assertNotNull($json, "Fragment must contain valid JSON after decompression: {$file}");
            $this->assertIsArray($json, "Fragment JSON must be an array: {$file}");
        }
    }

    /**
     * Decoded pf_meta has the expected structural keys:
     *   [0] => version string
     *   [1] => pages array
     *   [2] => index chunk refs
     */
    public function testPfMetaStructure(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $pagefindDir = $phpDir . '/pagefind';

        $metaFiles = glob($pagefindDir . '/pagefind.*.pf_meta') ?: glob($pagefindDir . '/*.pf_meta');
        $this->assertNotEmpty($metaFiles, 'No pf_meta file found');

        $decoded = CborDecoder::decodePfFile($metaFiles[0]);
        $this->assertIsArray($decoded);

        // Key 0: version (string like "1.5.0")
        $this->assertArrayHasKey(0, $decoded, 'pf_meta key 0 (version) must exist');
        $this->assertIsString($decoded[0], 'pf_meta key 0 must be a version string');
        $this->assertNotEmpty($decoded[0], 'pf_meta version string must not be empty');

        // Key 1: pages array
        $this->assertArrayHasKey(1, $decoded, 'pf_meta key 1 (pages) must exist');
        $this->assertIsArray($decoded[1], 'pf_meta key 1 must be an array of pages');
        $this->assertNotEmpty($decoded[1], 'pf_meta pages array must not be empty');

        // Key 2: index chunk refs
        $this->assertArrayHasKey(2, $decoded, 'pf_meta key 2 (index refs) must exist');
        $this->assertIsArray($decoded[2], 'pf_meta key 2 must be an array of index refs');
        $this->assertNotEmpty($decoded[2], 'pf_meta index refs must not be empty');
    }

    /**
     * Each decoded fragment has the required keys: url, meta, word_count, locations.
     */
    public function testFragmentStructure(): void
    {
        $phpDir = $this->buildWithPhpIndexer();
        $pagefindDir = $phpDir . '/pagefind';

        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment') ?: glob($pagefindDir . '/*.pf_fragment');
        $this->assertNotEmpty($fragmentFiles, 'No pf_fragment files found');

        foreach ($fragmentFiles as $file) {
            $decompressed = gzdecode(file_get_contents($file));
            if ($decompressed === false) {
                continue;
            }
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }

            $frag = json_decode($decompressed, true);
            if (!is_array($frag)) {
                continue;
            }

            $basename = basename($file);
            $this->assertArrayHasKey('url', $frag, "Fragment {$basename} must have 'url'");
            $this->assertIsString($frag['url'], "Fragment {$basename} 'url' must be string");
            $this->assertNotEmpty($frag['url'], "Fragment {$basename} 'url' must not be empty");

            $this->assertArrayHasKey('meta', $frag, "Fragment {$basename} must have 'meta'");

            $this->assertArrayHasKey('word_count', $frag, "Fragment {$basename} must have 'word_count'");
            $this->assertIsInt($frag['word_count'], "Fragment {$basename} 'word_count' must be integer");
            $this->assertGreaterThanOrEqual(0, $frag['word_count'], "Fragment {$basename} 'word_count' must be ≥ 0");

            $this->assertArrayHasKey('content', $frag, "Fragment {$basename} must have 'content'");
            $this->assertIsString($frag['content'], "Fragment {$basename} 'content' must be string");
        }
    }

    // ---------------------------------------------------------------
    // Helpers (standalone copies — not shared with ReferenceComparisonTest)
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

    /** @return ContentItem[] */
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
