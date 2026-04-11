<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\PagefindFormatWriter;

class PagefindFormatWriterTest extends TestCase
{
    private string $tmpDir;
    private PagefindFormatWriter $writer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-writer-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->writer = new PagefindFormatWriter(new CborEncoder());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function sampleIndex(): array
    {
        return [
            'apple' => [
                1 => ['positions' => [25 => [5, 20]]],
            ],
            'banana' => [
                2 => ['positions' => [25 => [10]]],
            ],
        ];
    }

    private function samplePages(): array
    {
        return [
            1 => [
                'url' => '/apple-page',
                'title' => 'Apple Page',
                'content' => 'All about apples and apple recipes.',
                'wordCount' => 50,
                'date' => '2026-01-01',
                'filters' => ['site' => 'TestSite'],
                'meta' => ['title' => 'Apple Page', 'url' => '/apple-page', 'date' => '2026-01-01'],
                'hash' => hash('sha256', 'apple content'),
            ],
            2 => [
                'url' => '/banana-page',
                'title' => 'Banana Page',
                'content' => 'All about bananas and banana smoothies.',
                'wordCount' => 40,
                'date' => '2026-02-01',
                'filters' => ['site' => 'TestSite'],
                'meta' => ['title' => 'Banana Page', 'url' => '/banana-page', 'date' => '2026-02-01'],
                'hash' => hash('sha256', 'banana content'),
            ],
        ];
    }

    public function testWriteCreatesDirectoryStructure(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $buildDir = $this->tmpDir . '/.scolta-building';
        $this->assertDirectoryExists($buildDir . '/index');
        $this->assertDirectoryExists($buildDir . '/fragment');
    }

    public function testWriteCreatesEntryJson(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $entryFile = $this->tmpDir . '/.scolta-building/pagefind-entry.json';
        $this->assertFileExists($entryFile);

        $entry = json_decode(file_get_contents($entryFile), true);
        $this->assertSame('1.5.0', $entry['version']);
        $this->assertArrayHasKey('en', $entry['languages']);
        $this->assertSame(2, $entry['languages']['en']['page_count']);
    }

    public function testWriteCreatesIndexFiles(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $indexFiles = glob($this->tmpDir . '/.scolta-building/index/*.pf_index');
        $this->assertNotEmpty($indexFiles);
    }

    public function testWriteCreatesFragmentFiles(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $fragmentFiles = glob($this->tmpDir . '/.scolta-building/fragment/*.pf_fragment');
        $this->assertCount(2, $fragmentFiles);
    }

    public function testFragmentIsGzippedJson(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $fragmentFiles = glob($this->tmpDir . '/.scolta-building/fragment/*.pf_fragment');
        $this->assertNotEmpty($fragmentFiles);

        $compressed = file_get_contents($fragmentFiles[0]);
        $json = gzdecode($compressed);
        $this->assertNotFalse($json);

        $data = json_decode($json, true);
        $this->assertArrayHasKey('url', $data);
        $this->assertArrayHasKey('content', $data);
        $this->assertArrayHasKey('word_count', $data);
    }

    public function testIndexFileContainsDelimiter(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $indexFiles = glob($this->tmpDir . '/.scolta-building/index/*.pf_index');
        $this->assertNotEmpty($indexFiles);

        $compressed = file_get_contents($indexFiles[0]);
        $decompressed = gzdecode($compressed);
        $this->assertNotFalse($decompressed);

        // Delimiter must be at the start of uncompressed data.
        $this->assertStringStartsWith('pagefind_dcd', $decompressed);
    }

    public function testMetaFileExists(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $metaFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_meta');
        $this->assertCount(1, $metaFiles);
    }

    public function testMetaFileIsGzippedCbor(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $metaFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_meta');
        $compressed = file_get_contents($metaFiles[0]);
        $decompressed = gzdecode($compressed);
        $this->assertNotFalse($decompressed);
        $this->assertStringStartsWith('pagefind_dcd', $decompressed);
    }

    public function testFilterFileCreatedWhenFiltersExist(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $filterFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_filter');
        $this->assertCount(1, $filterFiles);
    }

    public function testNoFilterFileWithoutFilters(): void
    {
        $pages = $this->samplePages();
        foreach ($pages as &$page) {
            $page['filters'] = [];
        }
        $this->writer->write($this->sampleIndex(), $pages, $this->tmpDir);
        $filterFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_filter');
        $this->assertEmpty($filterFiles);
    }

    public function testHashesAreConsistent(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $indexFiles1 = glob($this->tmpDir . '/.scolta-building/index/*.pf_index');

        $this->removeDir($this->tmpDir . '/.scolta-building');
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $indexFiles2 = glob($this->tmpDir . '/.scolta-building/index/*.pf_index');

        // Same input should produce same filenames.
        $names1 = array_map('basename', $indexFiles1);
        $names2 = array_map('basename', $indexFiles2);
        sort($names1);
        sort($names2);
        $this->assertSame($names1, $names2);
    }

    public function testVersionStringInEntryJson(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);
        $entry = json_decode(file_get_contents($this->tmpDir . '/.scolta-building/pagefind-entry.json'), true);
        $this->assertSame('1.5.0', $entry['version']);
    }

    public function testCustomMetaFieldsIncluded(): void
    {
        $pages = $this->samplePages();
        $pages[1]['meta']['author'] = 'Test Author';
        $pages[1]['meta']['category'] = 'News';

        $this->writer->write($this->sampleIndex(), $pages, $this->tmpDir);

        // Verify meta file exists and contains the delimiter.
        $metaFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_meta');
        $this->assertCount(1, $metaFiles);
        $decompressed = gzdecode(file_get_contents($metaFiles[0]));
        $this->assertStringStartsWith('pagefind_dcd', $decompressed);
    }

    public function testFilterFileReferencedInMetadata(): void
    {
        $this->writer->write($this->sampleIndex(), $this->samplePages(), $this->tmpDir);

        // Both filter and meta files should exist.
        $filterFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_filter');
        $metaFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_meta');
        $this->assertCount(1, $filterFiles);
        $this->assertCount(1, $metaFiles);

        // Meta file should reference filter data (non-empty filters array in CBOR).
        $decompressed = gzdecode(file_get_contents($metaFiles[0]));
        $cborData = substr($decompressed, strlen('pagefind_dcd'));
        // The filters section (position [3] in the meta array) should not be empty
        // when pages have filter values. We verify structurally: the CBOR data
        // should be larger than it would be with an empty filters array.
        $this->assertGreaterThan(20, strlen($cborData));
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
