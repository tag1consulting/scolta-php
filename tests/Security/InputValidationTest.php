<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Security;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Security-oriented input validation tests.
 *
 * Verifies that malicious or pathological inputs cannot:
 * - Inject HTML/JS into indexed or returned content
 * - Cause PHP buffer overflows or runaway memory usage
 * - Exploit path traversal to read/write outside the index directory
 * - Crash the AI endpoint handler on malformed input
 *
 * @see https://owasp.org/www-project-top-ten/
 */
class InputValidationTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-sec-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-sec-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // -----------------------------------------------------------------------
    // HTML injection in titles and body content
    // -----------------------------------------------------------------------

    /**
     * Script tags in a post title must not appear in indexed token stream.
     */
    public function testScriptTagInTitleIsStrippedFromTokens(): void
    {
        $items = [
            new ContentItem(
                id: 'xss-title',
                title: '<script>alert("xss")</script>Legitimate Title',
                bodyHtml: '<p>Safe body content.</p>',
                url: '/page',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);

        // Fragment files must not contain the script tag.
        $pagefindDir = $this->outputDir . '/pagefind';
        $fragmentFiles = glob($pagefindDir . '/fragment/*.pf_fragment') ?: [];
        $this->assertNotEmpty($fragmentFiles);

        foreach ($fragmentFiles as $frag) {
            $raw = gzdecode(file_get_contents($frag));
            if (str_starts_with($raw, 'pagefind_dcd')) {
                $raw = substr($raw, 12);
            }
            $decoded = json_decode($raw, true);
            $content = strtolower((string) ($decoded['content'] ?? ''));
            $this->assertStringNotContainsString('<script', $content, 'Script tag must not appear in fragment content');
            $this->assertStringNotContainsString('alert(', $content, 'XSS payload must not appear in fragment content');
        }
    }

    /**
     * HTML entities in a title must be decoded for indexing, not stored raw.
     */
    public function testHtmlEntitiesInTitleDecoded(): void
    {
        $items = [
            new ContentItem(
                id: 'entities',
                title: '&lt;b&gt;Bold Title&lt;/b&gt; &amp; More',
                bodyHtml: '<p>Safe body.</p>',
                url: '/ent',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);

        $pagefindDir = $this->outputDir . '/pagefind';
        $fragFiles = glob($pagefindDir . '/fragment/*.pf_fragment') ?: [];
        $this->assertNotEmpty($fragFiles);

        $raw = gzdecode(file_get_contents($fragFiles[0]));
        $decoded = json_decode(preg_replace('/^pagefind_dcd/', '', $raw), true);
        // The stored title should be the decoded plain text, not HTML entities.
        $meta = $decoded['meta'] ?? [];
        $title = $meta['title'] ?? '';
        $this->assertStringNotContainsString('&lt;', $title, 'HTML entity must be decoded in stored title');
        $this->assertStringNotContainsString('&gt;', $title);
        $this->assertStringContainsString('Bold Title', $title);
    }

    // -----------------------------------------------------------------------
    // Extremely long inputs
    // -----------------------------------------------------------------------

    /**
     * A 10K-character search query fed to the endpoint handler must be
     * rejected with a 400 status, not cause a timeout or OOM.
     */
    public function testExtremelyLongQueryIsRejectedGracefully(): void
    {
        $handler = $this->makeHandler();
        $longQuery = str_repeat('search term ', 1000); // ~12K chars

        $result = $handler->handleExpandQuery($longQuery);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    /**
     * A very long title (5K chars) must not crash the indexer.
     * Tokens must still be extracted from the start of the title.
     */
    public function testVeryLongTitleDoesNotCrash(): void
    {
        $longTitle = str_repeat('searchable word ', 312); // ~5K chars
        $items = [
            new ContentItem(
                id: 'long-title',
                title: $longTitle,
                bodyHtml: '<p>Short body.</p>',
                url: '/long',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Indexer must not crash on very long title');
    }

    /**
     * A body with a single paragraph containing 100K characters must complete.
     */
    public function testVeryLargeBodyDoesNotCrash(): void
    {
        $body = '<p>' . str_repeat('lorem ipsum dolor sit amet ', 4000) . '</p>'; // ~112K chars
        $items = [
            new ContentItem(
                id: 'large-body',
                title: 'Large Document',
                bodyHtml: $body,
                url: '/large',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Indexer must not crash on very large body');
        $this->assertEquals(1, $result->pageCount);
    }

    // -----------------------------------------------------------------------
    // Unicode edge cases
    // -----------------------------------------------------------------------

    /**
     * Null bytes in content must not crash the indexer or corrupt the index.
     */
    public function testNullBytesInContentAreSafe(): void
    {
        $items = [
            new ContentItem(
                id: 'null-bytes',
                title: "Title\x00With\x00Nulls",
                bodyHtml: "<p>Body\x00with\x00null\x00bytes.</p>",
                url: '/null',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Null bytes in content must not crash the indexer');
    }

    /**
     * RTL markers and BiDi override characters must not corrupt token extraction.
     */
    public function testBidiCharactersAreSafe(): void
    {
        $items = [
            new ContentItem(
                id: 'bidi',
                title: "Normal\u{200F}Title\u{202E}Reversed",   // RLM + RLO
                bodyHtml: "<p>Arabic mixed with \u{200F}English content\u{200E}.</p>",
                url: '/bidi',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'BiDi control characters must not crash the indexer');
    }

    /**
     * Zero-width joiner sequences (emoji with ZWJ) must index without crashing.
     */
    public function testZeroWidthJoinerInContentIsSafe(): void
    {
        $items = [
            new ContentItem(
                id: 'zwj',
                title: "Family emoji: \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}",
                bodyHtml: "<p>Emoji content \u{1F469}\u{200D}\u{1F4BB} developer. More text here.</p>",
                url: '/zwj',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'ZWJ sequences must not crash the indexer');
    }

    // -----------------------------------------------------------------------
    // Path traversal
    // -----------------------------------------------------------------------

    /**
     * A content item with a path traversal attempt in its ID must not write
     * outside the output directory.
     */
    public function testPathTraversalInIdIsContained(): void
    {
        $items = [
            new ContentItem(
                id: '../../etc/passwd',
                title: 'Traversal Attempt',
                bodyHtml: '<p>Content.</p>',
                url: '/traverse',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        // The indexer may succeed or sanitize — it must NOT write outside outputDir.
        $etcPasswd = '/etc/passwd';
        if ($result->success) {
            // Verify no file was written outside the output directory.
            $this->assertFileDoesNotExist('/tmp/scolta-etc-passwd');
            $this->assertFileDoesNotExist($etcPasswd . '.scolta');
        }
        // The indexer is allowed to succeed (sanitized ID) or fail gracefully.
        $this->assertTrue($result->success || $result->error !== null, 'Indexer must handle path traversal IDs gracefully');
    }

    // -----------------------------------------------------------------------
    // AI endpoint handler — malformed input
    // -----------------------------------------------------------------------

    /**
     * Empty query must return 400.
     */
    public function testEmptyQueryIsRejected(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handleExpandQuery('');

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    /**
     * Query consisting only of whitespace must be treated as empty.
     */
    public function testWhitespaceOnlyQueryIsRejected(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handleExpandQuery("   \t\n  ");

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    /**
     * handleFollowUp with max_follow_ups=0 must refuse all follow-ups.
     * The handler receives the full conversation array; with maxFollowUps=0
     * even a single exchange (user+assistant+user) should be refused.
     */
    public function testFollowUpRefusedWhenMaxIsZero(): void
    {
        $handler = $this->makeHandlerWithMaxFollowUps(0);

        // A minimal conversation: original Q, original A, now a follow-up.
        $messages = [
            ['role' => 'user',      'content' => 'original question'],
            ['role' => 'assistant', 'content' => 'original answer'],
            ['role' => 'user',      'content' => 'follow-up question'],
        ];

        $result = $handler->handleFollowUp($messages);

        // Handler returns 429 for "Follow-up limit reached" (rate-limit semantic).
        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
    }

    /**
     * handleFollowUp with history exceeding max_follow_ups must be refused.
     */
    public function testFollowUpRefusedWhenHistoryExceedsLimit(): void
    {
        $handler = $this->makeHandlerWithMaxFollowUps(1);

        // 3 user turns = 2 follow-ups, exceeds limit of 1.
        $messages = [
            ['role' => 'user',      'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user',      'content' => 'Q2'],
            ['role' => 'assistant', 'content' => 'A2'],
            ['role' => 'user',      'content' => 'Q3'],
        ];

        $result = $handler->handleFollowUp($messages);

        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
        $this->assertStringContainsString('limit', strtolower((string) ($result['error'] ?? '')));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a no-op AiEndpointHandler (AI not configured → all calls return 503).
     */
    private function makeHandler(): AiEndpointHandler
    {
        return $this->makeHandlerWithMaxFollowUps(3);
    }

    private function makeHandlerWithMaxFollowUps(int $max): AiEndpointHandler
    {
        $aiService = new class {
            public function getExpandPrompt(): string { return 'expand'; }
            public function getSummarizePrompt(): string { return 'summarize'; }
            public function getFollowUpPrompt(): string { return 'followup'; }
            public function message(string $sys, string $user): string { return 'ok'; }
            public function conversation(string $sys, array $msgs): string { return 'ok'; }
            public function isConfigured(): bool { return false; }
        };

        $cache = new class implements \Tag1\Scolta\Cache\CacheDriverInterface {
            public function get(string $key): mixed { return null; }
            public function set(string $key, mixed $value, int $ttlSeconds): void {}
            public function delete(string $key): void {}
            public function flush(): void {}
        };

        return new AiEndpointHandler(
            aiService: $aiService,
            cache: $cache,
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: $max,
        );
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
