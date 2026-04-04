<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Wasm\ScoltaWasm;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;
use Tag1\Scolta\Scorer\DefaultScorer;

/**
 * Tests that require the Extism runtime and WASM binary.
 *
 * These are skipped if libextism is not installed. When available,
 * they verify the full PHP→WASM pipeline works correctly.
 */
class WasmIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // Skip all tests if the Extism native library is not available.
        try {
            ScoltaWasm::version();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Extism runtime not available: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------
    // ScoltaWasm core functions
    // -------------------------------------------------------------------

    public function testVersion(): void
    {
        $version = ScoltaWasm::version();
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function testGetPrompt(): void
    {
        $prompt = ScoltaWasm::getPrompt('expand_query');
        $this->assertStringContainsString('{SITE_NAME}', $prompt);
        $this->assertStringContainsString('JSON array', $prompt);
    }

    public function testResolvePrompt(): void
    {
        $resolved = ScoltaWasm::resolvePrompt('expand_query', 'Acme Corp', 'technology company');
        $this->assertStringContainsString('Acme Corp', $resolved);
        $this->assertStringContainsString('technology company', $resolved);
        $this->assertStringNotContainsString('{SITE_NAME}', $resolved);
    }

    public function testCleanHtml(): void
    {
        $html = '<nav>Skip</nav><div id="main-content"><p>Good content</p></div><footer>Skip</footer>';
        $cleaned = ScoltaWasm::cleanHtml($html, '');
        $this->assertStringContainsString('Good content', $cleaned);
        $this->assertStringNotContainsString('Skip', $cleaned);
    }

    public function testBuildPagefindHtml(): void
    {
        $html = ScoltaWasm::buildPagefindHtml(
            'doc-1', 'Test Title', 'Body text', 'https://example.com', '2024-01-01', 'Site',
        );
        $this->assertStringContainsString('data-pagefind-body', $html);
        $this->assertStringContainsString('Test Title', $html);
        $this->assertStringContainsString('Body text', $html);
    }

    public function testToJsScoringConfig(): void
    {
        $result = ScoltaWasm::toJsScoringConfig([
            'recency_boost_max' => 0.5,
            'title_match_boost' => 1.0,
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('RECENCY_BOOST_MAX', $result);
        $this->assertArrayHasKey('TITLE_MATCH_BOOST', $result);
    }

    public function testParseExpansion(): void
    {
        $terms = ScoltaWasm::parseExpansion('["term one", "term two"]');
        $this->assertIsArray($terms);
        $this->assertContains('term one', $terms);
        $this->assertContains('term two', $terms);
    }

    public function testScoreResults(): void
    {
        $results = [
            ['url' => 'https://a.com', 'title' => 'Drupal Guide', 'excerpt' => 'Learn Drupal', 'date' => '2024-01-01'],
            ['url' => 'https://b.com', 'title' => 'PHP Tips', 'excerpt' => 'PHP best practices', 'date' => '2024-06-01'],
        ];
        $scored = ScoltaWasm::scoreResults($results, [], 'drupal');
        $this->assertIsArray($scored);
        $this->assertCount(2, $scored);
        // Results should have score field.
        $this->assertArrayHasKey('score', $scored[0]);
    }

    public function testMergeResults(): void
    {
        $original = [
            ['url' => 'https://a.com', 'title' => 'A', 'excerpt' => 'A content', 'date' => '2024-01-01', 'score' => 1.5],
        ];
        $expanded = [
            ['url' => 'https://b.com', 'title' => 'B', 'excerpt' => 'B content', 'date' => '2024-01-01', 'score' => 1.0],
        ];
        $merged = ScoltaWasm::mergeResults($original, $expanded, 0.7);
        $this->assertIsArray($merged);
        $this->assertGreaterThanOrEqual(2, count($merged));
    }

    // -------------------------------------------------------------------
    // Debug mode
    // -------------------------------------------------------------------

    public function testDebugLogging(): void
    {
        ScoltaWasm::enableDebug();
        ScoltaWasm::clearDebugLog();

        ScoltaWasm::version();
        ScoltaWasm::getPrompt('expand_query');

        $log = ScoltaWasm::getDebugLog();
        $this->assertCount(2, $log);
        $this->assertEquals('version', $log[0]['function']);
        $this->assertEquals('get_prompt', $log[1]['function']);
        $this->assertArrayHasKey('time_ms', $log[0]);
        $this->assertArrayHasKey('input_size', $log[0]);
        $this->assertArrayHasKey('output_size', $log[0]);

        ScoltaWasm::disableDebug();
        ScoltaWasm::clearDebugLog();
    }

    // -------------------------------------------------------------------
    // DefaultPrompts via WASM
    // -------------------------------------------------------------------

    public function testDefaultPromptsResolveKnownTemplate(): void
    {
        $result = DefaultPrompts::resolve(DefaultPrompts::EXPAND_QUERY, 'Test Site', 'docs');
        $this->assertStringContainsString('Test Site', $result);
        $this->assertStringContainsString('docs', $result);
        $this->assertStringNotContainsString('{SITE_NAME}', $result);
    }

    public function testDefaultPromptsGetTemplate(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);
        $this->assertStringContainsString('{SITE_NAME}', $template);
    }

    // -------------------------------------------------------------------
    // ScoltaConfig → JS config via WASM
    // -------------------------------------------------------------------

    public function testScoltaConfigToJsScoringConfig(): void
    {
        $config = ScoltaConfig::fromArray([
            'title_match_boost' => 2.0,
            'results_per_page' => 25,
            'ai_expand_query' => false,
        ]);

        $jsConfig = $config->toJsScoringConfig();
        $this->assertIsArray($jsConfig);
        $this->assertEquals(2.0, $jsConfig['TITLE_MATCH_BOOST']);
        $this->assertEquals(25, $jsConfig['RESULTS_PER_PAGE']);
        $this->assertFalse($jsConfig['AI_EXPAND_QUERY']);
    }

    // -------------------------------------------------------------------
    // DefaultScorer via WASM
    // -------------------------------------------------------------------

    public function testDefaultScorerScore(): void
    {
        $scorer = new DefaultScorer();
        $results = [
            ['url' => 'https://a.com', 'title' => 'PHP Guide', 'excerpt' => 'Learn PHP basics', 'date' => '2024-01-01'],
        ];
        $scored = $scorer->score($results, ['query' => 'php']);
        $this->assertIsArray($scored);
        $this->assertNotEmpty($scored);
    }

    public function testDefaultScorerMerge(): void
    {
        $scorer = new DefaultScorer();
        $original = [['url' => 'https://a.com', 'title' => 'A', 'excerpt' => '', 'date' => '2024-01-01', 'score' => 2.0]];
        $expanded = [['url' => 'https://b.com', 'title' => 'B', 'excerpt' => '', 'date' => '2024-01-01', 'score' => 1.0]];

        $merged = $scorer->merge($original, $expanded);
        $this->assertIsArray($merged);
    }

    public function testDefaultScorerParseExpansion(): void
    {
        $scorer = new DefaultScorer();
        $terms = $scorer->parseExpansion('["search optimization", "SEO tips"]');
        $this->assertContains('search optimization', $terms);
        $this->assertContains('SEO tips', $terms);
    }
}
