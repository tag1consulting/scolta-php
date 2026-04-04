<?php

declare(strict_types=1);

namespace Tag1\Scolta\Export;

use Tag1\Scolta\Wasm\ScoltaWasm;

/**
 * Exports content items as minimal HTML files for Pagefind indexing.
 *
 * Handles all CMS-agnostic export logic:
 *   - Output directory preparation (clean + create)
 *   - HTML content cleaning (strip page chrome, footer, scripts, styles, nav)
 *   - Pagefind-compatible HTML file generation with metadata attributes
 *
 * Platform adapters are responsible for querying their CMS, extracting
 * fields, and constructing ContentItem objects. This class does the rest.
 *
 * Usage:
 *   $exporter = new ContentExporter('/path/to/output');
 *   $exporter->prepareOutputDir();
 *   foreach ($items as $item) {
 *       $result = $exporter->export($item);
 *       // $result is true if exported, false if skipped (insufficient content)
 *   }
 *   $stats = $exporter->getStats();
 */
class ContentExporter
{
    private int $exported = 0;
    private int $skipped = 0;

    /** Minimum cleaned text length to be worth indexing. */
    private int $minContentLength;

    public function __construct(
        private readonly string $outputDir,
        int $minContentLength = 50,
    ) {
        $this->minContentLength = $minContentLength;
    }

    /**
     * Remove all files in the output directory and ensure it exists.
     */
    public function prepareOutputDir(): void
    {
        if (is_dir($this->outputDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->outputDir,
                    \RecursiveDirectoryIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir()
                    ? rmdir($file->getRealPath())
                    : unlink($file->getRealPath());
            }
        }
        @mkdir($this->outputDir, 0755, true);
    }

    /**
     * Export a single content item as a Pagefind-ready HTML file.
     *
     * @return bool True if exported, false if skipped (insufficient content).
     */
    public function export(ContentItem $item): bool
    {
        $cleanText = $this->cleanHtml($item->bodyHtml, $item->title);

        if (strlen($cleanText) < $this->minContentLength) {
            $this->skipped++;
            return false;
        }

        $html = $this->buildPagefindHtml($item, $cleanText);
        file_put_contents("{$this->outputDir}/{$item->id}.html", $html);
        $this->exported++;
        return true;
    }

    /**
     * Get export statistics.
     *
     * @return array{exported: int, skipped: int}
     */
    public function getStats(): array
    {
        return [
            'exported' => $this->exported,
            'skipped' => $this->skipped,
        ];
    }

    /**
     * Clean raw HTML body into plain text suitable for indexing.
     *
     * Delegates to WASM module for consistent cross-platform cleaning.
     * Strips page chrome (main-content region extraction), footer,
     * script/style/nav elements, and normalizes whitespace.
     */
    public function cleanHtml(string $html, string $title = ''): string
    {
        return ScoltaWasm::cleanHtml($html, $title);
    }

    /**
     * Build a minimal HTML document with Pagefind data attributes.
     *
     * Delegates to WASM module for consistent cross-platform generation.
     */
    private function buildPagefindHtml(ContentItem $item, string $cleanText): string
    {
        return ScoltaWasm::buildPagefindHtml($item->id, $item->title, $cleanText, $item->url, $item->date, $item->siteName);
    }
}
