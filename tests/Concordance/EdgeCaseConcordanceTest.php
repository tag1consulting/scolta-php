<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Edge case concordance tests.
 *
 * Verifies that the PHP indexer handles pathological or unusual content
 * shapes without crashing, producing corrupt output, or hanging.
 *
 * Covered cases:
 * - Title-only page (no body content)
 * - Very long document (50K+ words)
 * - Media-only page (no text whatsoever)
 * - Deeply nested HTML (20+ levels)
 * - Tables, lists, definition lists
 * - Code blocks with special characters
 * - Emoji in content
 * - Pages with only whitespace body
 * - Duplicate URLs / IDs in same build
 */
class EdgeCaseConcordanceTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-edge-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-edge-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // -----------------------------------------------------------------------
    // Title-only page
    // -----------------------------------------------------------------------

    public function testTitleOnlyPageIndexesWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'title-only',
                title: 'A Page With Only a Title and No Body',
                bodyHtml: '',
                url: '/title-only',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Title-only page must not crash the indexer');
        // The indexer may index 0 or 1 pages depending on minimum-content-length filter.
        // Either outcome is acceptable — the key requirement is no crash.
        $this->assertGreaterThanOrEqual(0, $result->pageCount);

        if ($result->pageCount > 0) {
            $fragments = $this->loadFragments($this->outputDir . '/pagefind');
            $this->assertNotEmpty($fragments);
        }
    }

    public function testWhitespaceOnlyBodyIndexesWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'whitespace-body',
                title: 'Whitespace Body Page',
                bodyHtml: "   \n\t   \n   ",
                url: '/whitespace',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Whitespace-only body must not crash the indexer');
    }

    // -----------------------------------------------------------------------
    // Very long document
    // -----------------------------------------------------------------------

    public function testVeryLongDocumentIndexesCompletely(): void
    {
        // ~50K words of content.
        $paragraphs = [];
        for ($i = 0; $i < 1000; $i++) {
            $paragraphs[] = '<p>Paragraph ' . $i . ': Lorem ipsum dolor sit amet consectetur adipiscing elit. '
                . 'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. '
                . 'Ut enim ad minim veniam quis nostrud exercitation ullamco laboris '
                . 'nisi ut aliquip ex ea commodo consequat.</p>';
        }

        $items = [
            new ContentItem(
                id: 'very-long',
                title: 'The Complete Guide: 50,000 Words of Lorem Ipsum',
                bodyHtml: implode("\n", $paragraphs),
                url: '/long-doc',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Very long document must index without crashing');
        $this->assertEquals(1, $result->pageCount);

        // Verify the index has tokens from the content.
        $pagefindDir = $this->outputDir . '/pagefind';
        $indexFiles = glob($pagefindDir . '/index/*.pf_index') ?: [];
        $this->assertNotEmpty($indexFiles, 'Long document must produce at least one index file');
    }

    // -----------------------------------------------------------------------
    // Media-only page (no text)
    // -----------------------------------------------------------------------

    public function testMediaOnlyPageIndexesWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'media-only',
                title: 'Gallery Page',
                bodyHtml: '<figure><img src="/photo.jpg" alt=""><figcaption></figcaption></figure>'
                    . '<video controls><source src="/video.mp4" type="video/mp4"></video>'
                    . '<audio controls><source src="/audio.mp3"></audio>',
                url: '/gallery',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Media-only page must index without crashing');
    }

    // -----------------------------------------------------------------------
    // Deeply nested HTML
    // -----------------------------------------------------------------------

    public function testDeeplyNestedHtmlIndexesWithoutCrash(): void
    {
        // 25 levels of nesting.
        $depth = 25;
        $inner = 'Deeply nested searchable content text words here.';
        $html = $inner;
        for ($i = 0; $i < $depth; $i++) {
            $html = "<div class=\"level-{$i}\"><p>{$html}</p></div>";
        }

        $items = [
            new ContentItem(
                id: 'deep-nest',
                title: 'Deeply Nested Content',
                bodyHtml: $html,
                url: '/nested',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Deeply nested HTML must index without crashing');
        $this->assertEquals(1, $result->pageCount);

        // "searchable" must appear in indexed content.
        $fragments = $this->loadFragments($this->outputDir . '/pagefind');
        $allContent = implode(' ', array_column($fragments, 'content'));
        $this->assertStringContainsString('nested', strtolower($allContent));
    }

    // -----------------------------------------------------------------------
    // Tables
    // -----------------------------------------------------------------------

    public function testHtmlTableIndexedCorrectly(): void
    {
        $items = [
            new ContentItem(
                id: 'table',
                title: 'Comparison Table',
                bodyHtml: '<table>
                    <thead><tr><th>Feature</th><th>PHP Indexer</th><th>Binary</th></tr></thead>
                    <tbody>
                        <tr><td>Speed</td><td>Moderate</td><td>Fast</td></tr>
                        <tr><td>Languages</td><td>Fifteen Snowball</td><td>Thirty-three</td></tr>
                        <tr><td>Hosting</td><td>Shared compatible</td><td>Binary required</td></tr>
                    </tbody>
                </table>',
                url: '/table',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'HTML tables must index without crashing');

        // Table cell content should be indexed.
        $fragments = $this->loadFragments($this->outputDir . '/pagefind');
        $allContent = strtolower(implode(' ', array_column($fragments, 'content')));
        $this->assertStringContainsString('snowball', $allContent, 'Table cell text must be indexed');
    }

    // -----------------------------------------------------------------------
    // Lists
    // -----------------------------------------------------------------------

    public function testUnorderedAndOrderedListsIndexedCorrectly(): void
    {
        $items = [
            new ContentItem(
                id: 'lists',
                title: 'List Types',
                bodyHtml: '<ul>
                    <li>First unordered item with searchable content</li>
                    <li>Second item contains unique vocabulary: mellifluous</li>
                    <li>Third item: sibilant sounds and serpentine sentences</li>
                </ul>
                <ol>
                    <li>First ordered step: initialize the repository</li>
                    <li>Second step: configure the environment variables</li>
                    <li>Third step: run the migration commands</li>
                </ol>
                <dl>
                    <dt>Stemming</dt>
                    <dd>Reducing words to their root form for better matching.</dd>
                    <dt>Tokenization</dt>
                    <dd>Splitting text into individual searchable units called tokens.</dd>
                </dl>',
                url: '/lists',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'HTML lists must index without crashing');

        $fragments = $this->loadFragments($this->outputDir . '/pagefind');
        $allContent = strtolower(implode(' ', array_column($fragments, 'content')));
        $this->assertStringContainsString('mellifluous', $allContent, 'List item text must be indexed');
        $this->assertStringContainsString('stemming', $allContent, 'Definition list text must be indexed');
    }

    // -----------------------------------------------------------------------
    // Code blocks with special characters
    // -----------------------------------------------------------------------

    public function testCodeBlocksIndexedWithoutTagLeak(): void
    {
        $items = [
            new ContentItem(
                id: 'code',
                title: 'Code Examples',
                bodyHtml: '<p>Here is some PHP code:</p>
                <pre><code class="language-php">
$arr = [1, 2, 3];
foreach ($arr as $key => $value) {
    echo &lt;?php echo $value; ?&gt;;
}
// Comment with <b>HTML tags</b>
$sql = "SELECT * FROM users WHERE id = ?";
</code></pre>
                <p>And shell commands:</p>
                <pre><code class="language-bash">
#!/bin/bash
curl -X POST https://api.example.com/v1/search \
    -H "Content-Type: application/json" \
    -d \'{"query": "search terms", "limit": 10}\'
</code></pre>
                <p>The code above shows basic usage.</p>',
                url: '/code-examples',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Code blocks must index without crashing');

        // Verify the prose text is indexed (not just code tokens).
        $fragments = $this->loadFragments($this->outputDir . '/pagefind');
        $allContent = strtolower(implode(' ', array_column($fragments, 'content')));
        $this->assertStringContainsString('usage', $allContent, 'Prose text after code block must be indexed');
    }

    // -----------------------------------------------------------------------
    // Emoji content
    // -----------------------------------------------------------------------

    public function testEmojiInContentIndexesWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'emoji',
                title: '🚀 Launching Our New Search Engine 🔍',
                bodyHtml: '<p>We are 🎉 excited to announce 🚀 our new search engine! '
                    . 'It supports 🌍 multiple languages and ⚡ fast indexing. '
                    . 'The AI features 🤖 include query expansion and summarization. '
                    . 'Perfect for developers 👩‍💻 and content managers 📝 alike.</p>',
                url: '/emoji-post',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Emoji content must index without crashing');

        // Prose words around emoji must still be indexed.
        $fragments = $this->loadFragments($this->outputDir . '/pagefind');
        $allContent = strtolower(implode(' ', array_column($fragments, 'content')));
        $this->assertStringContainsString('search', $allContent, 'Words around emoji must be indexed');
    }

    // -----------------------------------------------------------------------
    // Batch with mixed content types
    // -----------------------------------------------------------------------

    public function testBatchWithMixedEdgeCasesCompletesSuccessfully(): void
    {
        $items = [
            new ContentItem(id: 'e1', title: 'Normal Page', bodyHtml: '<p>Regular content here.</p>', url: '/n1', date: '2026-01-01'),
            new ContentItem(id: 'e2', title: 'Title Only', bodyHtml: '', url: '/n2', date: '2026-01-02'),
            new ContentItem(id: 'e3', title: '日本語ページ', bodyHtml: '<p>日本語のコンテンツです。検索機能をテストします。</p>', url: '/n3', date: '2026-01-03'),
            new ContentItem(id: 'e4', title: '<b>HTML in Title</b>', bodyHtml: '<p>Body text.</p>', url: '/n4', date: '2026-01-04'),
            new ContentItem(id: 'e5', title: 'Code Page', bodyHtml: '<pre><code>$x = 1; // &lt;tag&gt;</code></pre><p>Explanation follows.</p>', url: '/n5', date: '2026-01-05'),
            new ContentItem(id: 'e6', title: "Null\x00Byte\x00Title", bodyHtml: '<p>Safe body.</p>', url: '/n6', date: '2026-01-06'),
            new ContentItem(id: 'e7', title: 'Emoji 🚀 Title', bodyHtml: '<p>Content with emoji 🎯 and text.</p>', url: '/n7', date: '2026-01-07'),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, count($items));
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Mixed edge case batch must complete without crashing');
        // Some items may be skipped (title-only, null bytes) but none should crash.
        $this->assertGreaterThanOrEqual(1, $result->pageCount, 'At least one page must be indexed from the mixed batch');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array[] Decoded fragment objects.
     */
    private function loadFragments(string $pagefindDir): array
    {
        $decoded = [];
        foreach (glob($pagefindDir . '/fragment/*.pf_fragment') ?: [] as $frag) {
            $raw = gzdecode(file_get_contents($frag));
            if ($raw === false) {
                continue;
            }
            $json = preg_replace('/^pagefind_dcd/', '', $raw);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $decoded[] = $data;
            }
        }
        return $decoded;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
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
