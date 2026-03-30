<?php

declare(strict_types=1);

namespace Tag1\Scolta\Export;

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
     * Strips page chrome (main-content region extraction), footer,
     * script/style/nav elements, and normalizes whitespace.
     */
    public function cleanHtml(string $html, string $title = ''): string
    {
        // Extract main content region if present.
        $mainPos = strpos($html, 'id="main-content"');
        if ($mainPos !== false) {
            $closePos = strpos($html, '>', $mainPos);
            $html = $closePos !== false
                ? substr($html, $closePos + 1)
                : substr($html, $mainPos);
        }

        // Remove footer region.
        $footerMarkers = ['<footer', 'id="footer"', 'class="footer', 'region-footer'];
        foreach ($footerMarkers as $marker) {
            $footerPos = strpos($html, $marker);
            if ($footerPos !== false) {
                $html = substr($html, 0, $footerPos);
                break;
            }
        }

        // Remove non-content elements.
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html);

        // Strip all remaining tags and normalize whitespace.
        $cleanText = strip_tags($html);
        $cleanText = preg_replace('/\s+/', ' ', $cleanText);
        $cleanText = trim($cleanText);

        // Remove title from beginning of text (avoids duplication in index).
        $titlePlain = trim($title);
        if ($titlePlain !== '' && stripos($cleanText, $titlePlain) === 0) {
            $cleanText = trim(substr($cleanText, strlen($titlePlain)));
        }

        return $cleanText;
    }

    /**
     * Build a minimal HTML document with Pagefind data attributes.
     */
    private function buildPagefindHtml(ContentItem $item, string $cleanText): string
    {
        $titleEsc = htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8');
        $urlEsc = htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8');
        $siteNameEsc = htmlspecialchars($item->siteName, ENT_QUOTES, 'UTF-8');
        $dateEsc = htmlspecialchars($item->date, ENT_QUOTES, 'UTF-8');
        $bodyEsc = htmlspecialchars($cleanText, ENT_QUOTES, 'UTF-8');

        $siteMeta = $item->siteName !== ''
            ? "\n  <meta data-pagefind-meta=\"site:{$siteNameEsc}\">\n  <meta data-pagefind-filter=\"site:{$siteNameEsc}\">"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$titleEsc}</title>
  <meta data-pagefind-meta="url:{$urlEsc}">{$siteMeta}
  <meta data-pagefind-meta="date:{$dateEsc}">
</head>
<body>
  <main data-pagefind-body>
    <h1>{$titleEsc}</h1>
    <p>{$bodyEsc}</p>
  </main>
</body>
</html>
HTML;
    }
}
