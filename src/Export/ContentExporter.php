<?php

declare(strict_types=1);

namespace Tag1\Scolta\Export;

use Tag1\Scolta\Html\HtmlCleaner;
use Tag1\Scolta\Html\PagefindHtmlBuilder;
use Tag1\Scolta\Index\CachedContentReference;

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

    /** Tracks export paths to detect collisions. Maps export-relative path → item id. */
    private array $exportedPaths = [];

    public function __construct(
        private readonly string $outputDir,
        int $minContentLength = 50,
    ) {
        $this->minContentLength = $minContentLength;
    }

    /**
     * Map a canonical URL path to an export file path.
     *
     * Pagefind --site derives data.url from the crawled file's path relative
     * to the site root. This method produces a file path that mirrors the
     * canonical URL so both indexers yield identical data.url values.
     *
     *   /recipe/chocolate-cake/  → recipe/chocolate-cake/index.html
     *   /recipe/chocolate-cake   → recipe/chocolate-cake/index.html
     *   /about                   → about/index.html
     *   /                        → index.html
     *
     * @param string $url Root-relative canonical URL (from ContentItem::$url).
     * @return string Export-relative file path (no leading slash).
     *
     * @since 1.1.0
     * @stability experimental
     */
    public static function urlToExportPath(string $url): string
    {
        $path = strtok($url, '?#') ?: '/';

        $path = ltrim($path, '/');

        if ($path === '') {
            return 'index.html';
        }

        $path = rtrim($path, '/');

        return $path . '/index.html';
    }

    /**
     * Remove all files in the output directory and ensure it exists.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function prepareOutputDir(): void
    {
        if (is_dir($this->outputDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->outputDir,
                    \RecursiveDirectoryIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir()
                    ? rmdir($file->getRealPath())
                    : unlink($file->getRealPath());
            }
        }
        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0755, true)) {
            throw new \RuntimeException(
                sprintf('Failed to create output directory: %s', $this->outputDir),
            );
        }
        $this->exportedPaths = [];
    }

    /**
     * Export a single content item as a Pagefind-ready HTML file.
     *
     * @return bool True if exported, false if skipped (insufficient content).
     * @since 1.0.0
     * @stability stable
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
            $item->language,
            $item->filters,
            $item->metadata,
            $item->sortable,
        );

        $relativePath = self::urlToExportPath($item->url);

        if (isset($this->exportedPaths[$relativePath])) {
            throw new \RuntimeException(sprintf(
                'Export path collision: items "%s" and "%s" both map to "%s" (URLs: "%s" and the prior item\'s URL)',
                $this->exportedPaths[$relativePath],
                $item->id,
                $relativePath,
                $item->url,
            ));
        }
        $this->exportedPaths[$relativePath] = $item->id;

        $exportPath = $this->outputDir . '/' . $relativePath;
        $exportDir = dirname($exportPath);
        if (!is_dir($exportDir) && !mkdir($exportDir, 0755, true)) {
            throw new \RuntimeException("Failed to create export directory: {$exportDir}");
        }

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
     * @since 1.0.0
     * @stability stable
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
     * CachedContentReference objects (cache-hit markers for unchanged posts)
     * pass through without inspection — they carry no bodyHtml and are handled
     * downstream by IndexBuildOrchestrator.
     *
     * @param iterable<ContentItem|CachedContentReference> $items Items to filter (array or generator).
     * @return \Generator<ContentItem|CachedContentReference>      Items that pass the minimum length check, plus all cached references.
     *
     * @since 0.3.2
     * @stability experimental
     */
    public function filterItems(iterable $items): \Generator
    {
        foreach ($items as $item) {
            if ($item instanceof CachedContentReference) {
                yield $item;
                continue;
            }
            $cleaned = HtmlCleaner::clean($item->bodyHtml);
            if (mb_strlen($cleaned) >= $this->minContentLength) {
                yield $item;
            }
        }
    }

    /**
     * Count HTML files in the output directory recursively.
     *
     * Replaces flat glob('*.html') patterns in platform adapters now that
     * export files are written in a nested directory structure.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public static function countHtmlFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'html') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete the export file for a content item by its canonical URL.
     *
     * @param string $url The item's canonical URL (same value used during export).
     * @return bool True if a file was deleted, false if it didn't exist.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function deleteByUrl(string $url): bool
    {
        $relativePath = self::urlToExportPath($url);
        $fullPath = $this->outputDir . '/' . $relativePath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
            unset($this->exportedPaths[$relativePath]);

            return true;
        }

        return false;
    }

    /**
     * Delete the export file for a content item by its ID.
     *
     * Looks up the ID in the export manifest to find the nested path.
     * Falls back to flat {id}.html for backward compatibility with indexes
     * built before path-mirroring export.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function deleteById(string $id): bool
    {
        $manifest = self::readManifest($this->outputDir);
        if (isset($manifest[$id])) {
            $fullPath = $this->outputDir . '/' . $manifest[$id];
            if (file_exists($fullPath)) {
                unlink($fullPath);

                return true;
            }
        }

        $flatPath = $this->outputDir . '/' . $id . '.html';
        if (file_exists($flatPath)) {
            unlink($flatPath);

            return true;
        }

        return false;
    }

    /**
     * Write the export manifest mapping item IDs to their export paths.
     *
     * Call after all export() calls to persist the ID → path mapping for
     * incremental deletes. The manifest is a JSON file stored alongside
     * the exported HTML files.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function writeManifest(): void
    {
        $manifest = array_flip($this->exportedPaths);
        $manifestPath = $this->outputDir . '/.scolta-export-manifest.json';
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException("Failed to write export manifest: {$manifestPath}");
        }
    }

    /**
     * Read the export manifest for a given output directory.
     *
     * @return array<string, string> Map of item ID → export-relative path.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public static function readManifest(string $outputDir): array
    {
        $manifestPath = $outputDir . '/.scolta-export-manifest.json';
        if (!file_exists($manifestPath)) {
            return [];
        }

        $data = json_decode(file_get_contents($manifestPath), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Get export statistics.
     *
     * @return array{exported: int, skipped: int}
     * @since 1.0.0
     * @stability stable
     */
    public function getStats(): array
    {
        return [
            'exported' => $this->exported,
            'skipped' => $this->skipped,
        ];
    }
}
