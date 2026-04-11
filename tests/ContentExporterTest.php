<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;

/**
 * Tests ContentExporter file I/O and filtering logic.
 *
 * ContentExporter now uses pure PHP HtmlCleaner and PagefindHtmlBuilder
 * directly, so no WASM stubs are needed.
 */
class ContentExporterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-exporter-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
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

    // -------------------------------------------------------------------
    // prepareOutputDir
    // -------------------------------------------------------------------

    public function testPrepareOutputDirCreatesDirectory(): void
    {
        $exporter = new ContentExporter($this->tmpDir);

        $this->assertDirectoryDoesNotExist($this->tmpDir);
        $exporter->prepareOutputDir();
        $this->assertDirectoryExists($this->tmpDir);
    }

    public function testPrepareOutputDirClearsExistingFiles(): void
    {
        mkdir($this->tmpDir, 0755, true);
        file_put_contents($this->tmpDir . '/old-file.html', 'old content');
        file_put_contents($this->tmpDir . '/another.txt', 'data');

        $exporter = new ContentExporter($this->tmpDir);
        $exporter->prepareOutputDir();

        $this->assertDirectoryExists($this->tmpDir);
        $this->assertFileDoesNotExist($this->tmpDir . '/old-file.html');
        $this->assertFileDoesNotExist($this->tmpDir . '/another.txt');
    }

    public function testPrepareOutputDirClearsSubdirectories(): void
    {
        mkdir($this->tmpDir . '/subdir', 0755, true);
        file_put_contents($this->tmpDir . '/subdir/nested.html', 'nested');

        $exporter = new ContentExporter($this->tmpDir);
        $exporter->prepareOutputDir();

        $this->assertDirectoryExists($this->tmpDir);
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/subdir');
    }

    // -------------------------------------------------------------------
    // getStats
    // -------------------------------------------------------------------

    public function testInitialStatsAreZero(): void
    {
        $exporter = new ContentExporter($this->tmpDir);
        $stats = $exporter->getStats();

        $this->assertEquals(0, $stats['exported']);
        $this->assertEquals(0, $stats['skipped']);
    }

    // -------------------------------------------------------------------
    // export — content filtering
    // -------------------------------------------------------------------

    public function testExportSkipsShortContent(): void
    {
        $exporter = new ContentExporter($this->tmpDir, minContentLength: 50);
        $exporter->prepareOutputDir();

        $item = new ContentItem(
            id: 'short-1',
            title: 'Short',
            bodyHtml: '<p>Too short</p>',
            url: 'https://x.com/short',
            date: '2024-01-01',
        );

        $result = $exporter->export($item);
        $this->assertFalse($result);
        $this->assertEquals(1, $exporter->getStats()['skipped']);
        $this->assertEquals(0, $exporter->getStats()['exported']);
        $this->assertFileDoesNotExist($this->tmpDir . '/short-1.html');
    }

    public function testExportWritesFileForSufficientContent(): void
    {
        $exporter = new ContentExporter($this->tmpDir, minContentLength: 10);
        $exporter->prepareOutputDir();

        $item = new ContentItem(
            id: 'good-1',
            title: 'Good Article',
            bodyHtml: '<p>This is a sufficiently long body text for indexing purposes.</p>',
            url: 'https://x.com/good',
            date: '2024-06-15',
            siteName: 'Test Site',
        );

        $result = $exporter->export($item);
        $this->assertTrue($result);
        $this->assertEquals(1, $exporter->getStats()['exported']);
        $this->assertEquals(0, $exporter->getStats()['skipped']);
        $this->assertFileExists($this->tmpDir . '/good-1.html');

        // Verify the exported HTML has pagefind attributes.
        $html = file_get_contents($this->tmpDir . '/good-1.html');
        $this->assertStringContainsString('data-pagefind-body', $html);
        $this->assertStringContainsString('Good Article', $html);
    }

    public function testExportTracksMultipleItems(): void
    {
        $exporter = new ContentExporter($this->tmpDir, minContentLength: 20);
        $exporter->prepareOutputDir();

        $longBody = '<p>' . str_repeat('word ', 20) . '</p>';
        $shortBody = '<p>tiny</p>';

        $exporter->export(new ContentItem('a', 'A', $longBody, 'https://x.com/a', '2024-01-01'));
        $exporter->export(new ContentItem('b', 'B', $shortBody, 'https://x.com/b', '2024-01-01'));
        $exporter->export(new ContentItem('c', 'C', $longBody, 'https://x.com/c', '2024-01-01'));
        $exporter->export(new ContentItem('d', 'D', $shortBody, 'https://x.com/d', '2024-01-01'));

        $stats = $exporter->getStats();
        $this->assertEquals(2, $stats['exported']);
        $this->assertEquals(2, $stats['skipped']);
    }

    public function testExportUsesItemIdAsFilename(): void
    {
        $exporter = new ContentExporter($this->tmpDir, minContentLength: 5);
        $exporter->prepareOutputDir();

        $exporter->export(new ContentItem(
            'my-article-42',
            'Title',
            '<p>Enough content here.</p>',
            'https://x.com',
            '2024-01-01',
        ));

        $this->assertFileExists($this->tmpDir . '/my-article-42.html');
    }

    public function testExportCustomMinLength(): void
    {
        $exporter = new ContentExporter($this->tmpDir, minContentLength: 5);
        $exporter->prepareOutputDir();

        // "Short" is 5 chars -- should pass minContentLength=5.
        $result = $exporter->export(new ContentItem(
            'x',
            'T',
            '<b>Short</b>',
            'https://x.com',
            '2024-01-01',
        ));
        $this->assertTrue($result);
    }

    public function testExportToItemsFiltersShortContent(): void
    {
        $exporter = new ContentExporter($this->tmpDir);

        $items = [
            new ContentItem('1', 'Good', '<p>This is a page with enough content to be indexed properly.</p>', '/good', '2024-01-01'),
            new ContentItem('2', 'Short', '<p>Hi</p>', '/short', '2024-01-01'),
        ];

        $result = $exporter->exportToItems($items);
        $this->assertCount(1, $result);
        $this->assertSame('1', $result[0]->id);
    }

    public function testExportToItemsPreservesValidItems(): void
    {
        $exporter = new ContentExporter($this->tmpDir);

        $items = [
            new ContentItem('a', 'Page A', '<p>Content for page A that is long enough to pass the minimum content filter.</p>', '/a', '2024-01-01'),
            new ContentItem('b', 'Page B', '<p>Content for page B that is also long enough to pass the minimum content filter.</p>', '/b', '2024-01-01'),
        ];

        $result = $exporter->exportToItems($items);
        $this->assertCount(2, $result);
        $ids = array_map(fn ($item) => $item->id, $result);
        $this->assertContains('a', $ids);
        $this->assertContains('b', $ids);
    }

    public function testExportToItemsDoesNotWriteFiles(): void
    {
        $exporter = new ContentExporter($this->tmpDir);
        $exporter->prepareOutputDir();

        $items = [
            new ContentItem('1', 'Page', '<p>Long enough content to pass filter easily.</p>', '/page', '2024-01-01'),
        ];

        $exporter->exportToItems($items);

        // No files should be written to disk.
        $files = glob($this->tmpDir . '/*.html');
        $this->assertEmpty($files);
    }
}
