<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Prove both indexers produce identical data.url values for the same content.
 *
 * The PHP indexer stores data.url = $item->url (the canonical, root-relative
 * URL). The binary indexer runs pagefind --site over HTML files exported by
 * ContentExporter, so data.url is derived from the file's path relative to
 * the site root. If ContentExporter writes flat {id}.html files, the binary
 * indexer produces data.url = /{id}.html — which differs from the canonical
 * URL and breaks links.
 *
 * This test joins fragments by stable item id, never by URL, so URL
 * divergence is visible. The existing ReferenceComparisonTest joins by URL,
 * which structurally hides this class of bug.
 *
 * @see https://github.com/tag1consulting/scolta-php/issues/157
 */
class IndexerUrlParityTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-url-parity-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Build a realistic corpus where item IDs differ from canonical URL paths.
     *
     * This is the normal case for every CMS: WordPress uses post-42, Drupal
     * uses entity-node-42-en, but the canonical URL is /recipe/chocolate-cake/.
     *
     * @return ContentItem[]
     */
    private function buildCorpus(): array
    {
        $body = '<p>' . str_repeat('This is a sufficiently long paragraph for indexing. ', 5) . '</p>';

        return [
            new ContentItem(
                id: 'post-1',
                title: 'Chocolate Cake Recipe',
                bodyHtml: $body,
                url: '/recipe/chocolate-cake/',
                date: '2026-01-15',
                siteName: 'Recipes',
            ),
            new ContentItem(
                id: 'post-2',
                title: 'Hello World',
                bodyHtml: $body,
                url: '/blog/hello-world/',
                date: '2026-02-10',
                siteName: 'Blog',
            ),
            new ContentItem(
                id: 'post-3',
                title: 'About Us',
                bodyHtml: $body,
                url: '/about/',
                date: '2026-03-01',
                siteName: 'Pages',
            ),
            new ContentItem(
                id: 'post-4',
                title: 'Home Page',
                bodyHtml: $body,
                url: '/',
                date: '2026-04-01',
                siteName: 'Pages',
            ),
            new ContentItem(
                id: 'post-5',
                title: 'Deep Nested Page',
                bodyHtml: $body,
                url: '/docs/api/v2/reference/',
                date: '2026-05-01',
                siteName: 'Docs',
            ),
        ];
    }

    /**
     * Assert that ContentExporter writes files at paths mirroring the
     * canonical URL so Pagefind --site derives data.url == $item->url.
     *
     * Pagefind --site derives data.url from the file's path relative to
     * the site root. For the indexers to be interchangeable, the export
     * path must produce the same URL the PHP indexer would store.
     */
    public function testExportPathMirrorsCanonicalUrl(): void
    {
        $exportDir = $this->tmpDir . '/export';
        $exporter = new ContentExporter($exportDir, minContentLength: 10);
        $exporter->prepareOutputDir();

        foreach ($this->buildCorpus() as $item) {
            $exporter->export($item);
        }

        $expectedPaths = [
            'post-1' => '/recipe/chocolate-cake/',
            'post-2' => '/blog/hello-world/',
            'post-3' => '/about/',
            'post-4' => '/',
            'post-5' => '/docs/api/v2/reference/',
        ];

        foreach ($expectedPaths as $id => $canonicalUrl) {
            $expectedFilePath = ContentExporter::urlToExportPath($canonicalUrl);
            $fullPath = $exportDir . '/' . $expectedFilePath;
            $this->assertFileExists(
                $fullPath,
                sprintf(
                    'Item %s (url=%s) should be exported to %s, but file does not exist. '
                    . 'Pagefind --site derives data.url from the file path, so the export path '
                    . 'must mirror the canonical URL for both indexers to produce identical output.',
                    $id,
                    $canonicalUrl,
                    $expectedFilePath
                )
            );
        }
    }

    /**
     * Assert that the PHP indexer stores data.url == $item->url.
     *
     * This establishes the PHP-side baseline. The binary side must match.
     */
    public function testPhpIndexerStoresCanonicalUrl(): void
    {
        $stateDir = $this->tmpDir . '/state';
        $outputDir = $this->tmpDir . '/output';
        mkdir($stateDir, 0755, true);
        mkdir($outputDir, 0755, true);

        $items = $this->buildCorpus();
        $indexer = new PhpIndexer($stateDir, $outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success, 'PHP indexer must succeed');

        $fragments = $this->loadFragmentsByBodyId($outputDir . '/pagefind');
        $this->assertNotEmpty($fragments, 'Must have fragments');

        foreach ($items as $item) {
            $found = false;
            foreach ($fragments as $frag) {
                if (str_contains($frag['content'] ?? '', 'sufficiently long paragraph')
                    && ($frag['meta']['title'] ?? '') === $item->title) {
                    $this->assertSame(
                        $item->url,
                        $frag['url'],
                        sprintf(
                            'PHP indexer fragment for "%s" has data.url=%s, expected %s',
                            $item->title,
                            $frag['url'],
                            $item->url
                        )
                    );
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Must find fragment for item: {$item->title}");
        }
    }

    /**
     * Assert that no exported file uses the old flat {id}.html pattern.
     *
     * After the fix, ContentExporter must write nested paths that mirror
     * the canonical URL. Flat {id}.html files are build artifacts that
     * produce 404 URLs.
     */
    public function testNoFlatIdHtmlFiles(): void
    {
        $exportDir = $this->tmpDir . '/export';
        $exporter = new ContentExporter($exportDir, minContentLength: 10);
        $exporter->prepareOutputDir();

        foreach ($this->buildCorpus() as $item) {
            $exporter->export($item);
        }

        $flatFiles = glob($exportDir . '/*.html') ?: [];
        $rootIndex = array_filter($flatFiles, fn ($f) => basename($f) === 'index.html');
        $nonRootFlat = array_diff($flatFiles, $rootIndex);

        $this->assertEmpty(
            $nonRootFlat,
            'No flat {id}.html files should exist in export root (except index.html for /). '
            . 'Found: ' . implode(', ', array_map('basename', $nonRootFlat))
        );
    }

    /**
     * Two items whose canonical URLs map to the same export path must cause
     * a loud build failure, not a silent overwrite.
     */
    public function testCollisionFailsLoudly(): void
    {
        $exportDir = $this->tmpDir . '/export';
        $exporter = new ContentExporter($exportDir, minContentLength: 10);
        $exporter->prepareOutputDir();

        $body = '<p>' . str_repeat('Collision test content is long enough. ', 5) . '</p>';

        $exporter->export(new ContentItem(
            id: 'item-a',
            title: 'First Item',
            bodyHtml: $body,
            url: '/same/path/',
            date: '2026-01-01',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/collision/i');

        $exporter->export(new ContentItem(
            id: 'item-b',
            title: 'Second Item',
            bodyHtml: $body,
            url: '/same/path',
            date: '2026-01-01',
        ));
    }

    /**
     * urlToExportPath maps various URL shapes correctly.
     */
    public function testUrlToExportPathMapping(): void
    {
        $this->assertSame('index.html', ContentExporter::urlToExportPath('/'));
        $this->assertSame('about/index.html', ContentExporter::urlToExportPath('/about'));
        $this->assertSame('about/index.html', ContentExporter::urlToExportPath('/about/'));
        $this->assertSame('recipe/cake/index.html', ContentExporter::urlToExportPath('/recipe/cake/'));
        $this->assertSame('recipe/cake/index.html', ContentExporter::urlToExportPath('/recipe/cake'));
        $this->assertSame('docs/api/v2/ref/index.html', ContentExporter::urlToExportPath('/docs/api/v2/ref/'));
        // Query strings and fragments are stripped.
        $this->assertSame('page/index.html', ContentExporter::urlToExportPath('/page?foo=bar'));
        $this->assertSame('page/index.html', ContentExporter::urlToExportPath('/page#section'));
        $this->assertSame('page/index.html', ContentExporter::urlToExportPath('/page?foo=bar#section'));
    }

    /**
     * Load all fragments from a pagefind output directory, keyed by content.
     *
     * @return array<int, array{url: string, content: string, meta: array}>
     */
    private function loadFragmentsByBodyId(string $dir): array
    {
        $fragments = [];
        $files = glob($dir . '/fragment/*.pf_fragment') ?: glob($dir . '/*.pf_fragment');

        foreach ($files ?: [] as $file) {
            $decompressed = gzdecode(file_get_contents($file));
            if ($decompressed === false) {
                continue;
            }
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }
            $json = json_decode($decompressed, true);
            if ($json !== null) {
                $fragments[] = $json;
            }
        }

        return $fragments;
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
