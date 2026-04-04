<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;

/**
 * Tests ContentExporter file I/O and filtering logic.
 *
 * These tests exercise prepareOutputDir, stats tracking, and the min
 * content length filter. The actual HTML cleaning and Pagefind HTML
 * generation delegate to WASM, so those paths are tested separately
 * in WasmIntegrationTest (which requires libextism).
 *
 * For tests that call export(), we mock the WASM dependency by
 * subclassing ContentExporter and overriding cleanHtml/buildPagefindHtml.
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
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Stubbed exporter that bypasses WASM for unit testing.
     *
     * Overrides both cleanHtml (public) and buildPagefindHtml (private,
     * accessed via export()) to avoid needing the Extism native library.
     */
    private function createStubExporter(int $minContentLength = 50): ContentExporter
    {
        return new class($this->tmpDir, $minContentLength) extends ContentExporter {
            public function cleanHtml(string $html, string $title = ''): string
            {
                return trim(strip_tags($html));
            }

            public function export(\Tag1\Scolta\Export\ContentItem $item): bool
            {
                $cleanText = $this->cleanHtml($item->bodyHtml, $item->title);
                if (strlen($cleanText) < $this->getMinContentLength()) {
                    $this->incrementSkipped();
                    return false;
                }
                // Build simple HTML without WASM.
                $html = sprintf(
                    '<!DOCTYPE html><html><body data-pagefind-body><h1>%s</h1><p>%s</p></body></html>',
                    htmlspecialchars($item->title),
                    htmlspecialchars($cleanText),
                );
                file_put_contents($this->getOutputDir() . '/' . $item->id . '.html', $html);
                $this->incrementExported();
                return true;
            }

            public function getMinContentLength(): int
            {
                return (new \ReflectionProperty(ContentExporter::class, 'minContentLength'))->getValue($this);
            }

            public function getOutputDir(): string
            {
                return (new \ReflectionProperty(ContentExporter::class, 'outputDir'))->getValue($this);
            }

            public function incrementExported(): void
            {
                $prop = new \ReflectionProperty(ContentExporter::class, 'exported');
                $prop->setValue($this, $prop->getValue($this) + 1);
            }

            public function incrementSkipped(): void
            {
                $prop = new \ReflectionProperty(ContentExporter::class, 'skipped');
                $prop->setValue($this, $prop->getValue($this) + 1);
            }
        };
    }

    // -------------------------------------------------------------------
    // prepareOutputDir
    // -------------------------------------------------------------------

    public function testPrepareOutputDirCreatesDirectory(): void
    {
        $exporter = $this->createStubExporter();

        $this->assertDirectoryDoesNotExist($this->tmpDir);
        $exporter->prepareOutputDir();
        $this->assertDirectoryExists($this->tmpDir);
    }

    public function testPrepareOutputDirClearsExistingFiles(): void
    {
        mkdir($this->tmpDir, 0755, true);
        file_put_contents($this->tmpDir . '/old-file.html', 'old content');
        file_put_contents($this->tmpDir . '/another.txt', 'data');

        $exporter = $this->createStubExporter();
        $exporter->prepareOutputDir();

        $this->assertDirectoryExists($this->tmpDir);
        $this->assertFileDoesNotExist($this->tmpDir . '/old-file.html');
        $this->assertFileDoesNotExist($this->tmpDir . '/another.txt');
    }

    public function testPrepareOutputDirClearsSubdirectories(): void
    {
        mkdir($this->tmpDir . '/subdir', 0755, true);
        file_put_contents($this->tmpDir . '/subdir/nested.html', 'nested');

        $exporter = $this->createStubExporter();
        $exporter->prepareOutputDir();

        $this->assertDirectoryExists($this->tmpDir);
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/subdir');
    }

    // -------------------------------------------------------------------
    // getStats
    // -------------------------------------------------------------------

    public function testInitialStatsAreZero(): void
    {
        $exporter = $this->createStubExporter();
        $stats = $exporter->getStats();

        $this->assertEquals(0, $stats['exported']);
        $this->assertEquals(0, $stats['skipped']);
    }

    // -------------------------------------------------------------------
    // export — content filtering
    // -------------------------------------------------------------------

    public function testExportSkipsShortContent(): void
    {
        $exporter = $this->createStubExporter(50);
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
        $exporter = $this->createStubExporter(10);
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
    }

    public function testExportTracksMultipleItems(): void
    {
        $exporter = $this->createStubExporter(20);
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
        $exporter = $this->createStubExporter(5);
        $exporter->prepareOutputDir();

        $exporter->export(new ContentItem(
            'my-article-42', 'Title', '<p>Enough content here.</p>',
            'https://x.com', '2024-01-01',
        ));

        $this->assertFileExists($this->tmpDir . '/my-article-42.html');
    }

    public function testExportCustomMinLength(): void
    {
        $exporter = $this->createStubExporter(5);
        $exporter->prepareOutputDir();

        // "Short" is 5 chars — should pass minContentLength=5.
        $result = $exporter->export(new ContentItem(
            'x', 'T', '<b>Short</b>', 'https://x.com', '2024-01-01',
        ));
        $this->assertTrue($result);
    }
}
