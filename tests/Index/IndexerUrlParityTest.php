<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Prove the PHP indexer stores data.url = $item->url, the canonical
 * root-relative URL, never a path derived from the item id.
 *
 * Fragments are matched by content and title, never by URL, so URL
 * divergence is visible. ReferenceComparisonTest joins by URL, which
 * structurally hides this class of bug.
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
     * Assert that the PHP indexer stores data.url == $item->url.
     *
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
                            $item->url,
                        ),
                    );
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Must find fragment for item: {$item->title}");
        }
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
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
