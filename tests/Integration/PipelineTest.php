<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Wasm\ScoltaWasm;

/**
 * End-to-end pipeline test: content items through export and scoring.
 *
 * Requires libextism for the WASM-based export and scoring steps.
 */
class PipelineTest extends TestCase
{
    private string $tempDir;
    private bool $wasmAvailable = false;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scolta_pipeline_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // WASM binary must exist.
        $wasmPath = dirname(__DIR__, 2) . '/wasm/scolta_core.wasm';
        $this->assertFileExists(
            $wasmPath,
            "WASM binary not found at {$wasmPath}. This is a build error."
        );

        // Check if Extism is available.
        try {
            ScoltaWasm::version();
            $this->wasmAvailable = true;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'FFI')
                || str_contains($e->getMessage(), 'libextism')
                || str_contains($e->getMessage(), 'Extism')
            ) {
                $this->markTestSkipped('Extism native runtime not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testContentToScoringPipeline(): void
    {
        // Step 1: Create ContentItems.
        $items = [
            new ContentItem(
                id: 'article-1',
                title: 'Getting Started with PHP',
                bodyHtml: '<article><h1>Getting Started with PHP</h1><p>'
                    . str_repeat('PHP is a server-side scripting language. ', 10)
                    . '</p></article>',
                url: 'https://example.com/php-guide',
                date: '2024-06-15',
                siteName: 'Dev Docs',
            ),
            new ContentItem(
                id: 'article-2',
                title: 'Advanced PHP Patterns',
                bodyHtml: '<article><h1>Advanced PHP Patterns</h1><p>'
                    . str_repeat('Design patterns improve code quality. ', 10)
                    . '</p></article>',
                url: 'https://example.com/php-patterns',
                date: '2024-08-01',
                siteName: 'Dev Docs',
            ),
        ];

        // Step 2: Export to HTML via ContentExporter.
        $exporter = new ContentExporter($this->tempDir, minContentLength: 20);
        $exporter->prepareOutputDir();

        foreach ($items as $item) {
            $exporter->export($item);
        }

        $stats = $exporter->getStats();
        $this->assertEquals(2, $stats['exported'], 'Both items should be exported');

        // Step 3: Verify exported HTML has pagefind attributes.
        $html1 = file_get_contents($this->tempDir . '/article-1.html');
        $html2 = file_get_contents($this->tempDir . '/article-2.html');

        $this->assertStringContainsString('data-pagefind-body', $html1);
        $this->assertStringContainsString('Getting Started with PHP', $html1);
        $this->assertStringContainsString('data-pagefind-body', $html2);
        $this->assertStringContainsString('Advanced PHP Patterns', $html2);

        // Step 4: Score results via WASM.
        $searchResults = [
            [
                'url' => 'https://example.com/php-guide',
                'title' => 'Getting Started with PHP',
                'excerpt' => 'PHP is a server-side scripting language.',
                'date' => '2024-06-15',
            ],
            [
                'url' => 'https://example.com/php-patterns',
                'title' => 'Advanced PHP Patterns',
                'excerpt' => 'Design patterns improve code quality.',
                'date' => '2024-08-01',
            ],
        ];

        $scored = ScoltaWasm::scoreResults($searchResults, [], 'PHP');

        $this->assertIsArray($scored);
        $this->assertCount(2, $scored);

        // Each result should have a score.
        foreach ($scored as $result) {
            $this->assertArrayHasKey('score', $result);
            $this->assertIsFloat($result['score']);
        }
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
