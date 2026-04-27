<?php

declare(strict_types=1);

namespace Tag1\Scolta\Export;

use Tag1\Scolta\Html\HtmlCleaner;
use Tag1\Scolta\Html\PagefindHtmlBuilder;

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
        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0755, true)) {
            throw new \RuntimeException(
                sprintf('Failed to create output directory: %s', $this->outputDir)
            );
        }
    }

    /**
     * Export a single content item as a Pagefind-ready HTML file.
     *
     * @return bool True if exported, false if skipped (insufficient content).
     */
    public function export(ContentItem $item): bool
    {
        $cleanText = HtmlCleaner::clean($item->bodyHtml, $item->title);

        if (strlen($cleanText) < $this->minContentLength) {
            $this->skipped++;
            return false;
        }

        $html = PagefindHtmlBuilder::build(
            $item->id,
            $item->title,
            $cleanText,
            $item->url,
            $item->date,
            $item->siteName,
        );

        $exportPath = "{$this->outputDir}/{$item->id}.html";
        if (file_put_contents($exportPath, $html) === false) {
            throw new \RuntimeException("Failed to write export file: {$exportPath}");
        }
        $this->exported++;
        return true;
    }

    /**
     * Filter content items by minimum content length without writing to disk.
     *
     * Used by PhpIndexer to process content directly in memory.
     *
     * @param ContentItem[] $items Raw content items from platform adapter.
     * @return ContentItem[] Items that pass the minimum content length filter.
     */
    public function exportToItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $cleaned = HtmlCleaner::clean($item->bodyHtml);
            if (mb_strlen($cleaned) >= $this->minContentLength) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Filter content items lazily by minimum content length.
     *
     * Generator version of exportToItems() — yields ContentItem objects one at
     * a time without pre-loading the entire result set into RAM. Use this in
     * framework adapters where the input comes from a paginated generator so
     * that peak RSS stays bounded regardless of corpus size.
     *
     * @param iterable<ContentItem> $items Items to filter (array or generator).
     * @return \Generator<ContentItem>     Items that pass the minimum length check.
     *
     * @since 0.3.2
     * @stability experimental
     */
    public function filterItems(iterable $items): \Generator
    {
        foreach ($items as $item) {
            $cleaned = HtmlCleaner::clean($item->bodyHtml);
            if (mb_strlen($cleaned) >= $this->minContentLength) {
                yield $item;
            }
        }
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
}
