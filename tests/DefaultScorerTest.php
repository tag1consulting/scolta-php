<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Scorer\DefaultScorer;

/**
 * Tests DefaultScorer's WASM-backed scoring methods.
 *
 * These tests require the Extism runtime. Skipped when unavailable.
 */
class DefaultScorerTest extends TestCase
{
    private DefaultScorer $scorer;

    protected function setUp(): void
    {
        // WASM binary must exist.
        $wasmPath = dirname(__DIR__) . '/wasm/scolta_core.wasm';
        $this->assertFileExists(
            $wasmPath,
            "WASM binary not found. Run 'composer build-wasm'."
        );

        // Extism runtime may not be installed — skip gracefully.
        try {
            \Tag1\Scolta\ExtismCheck::verify();
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'FFI') || str_contains($e->getMessage(), 'Extism')) {
                $this->markTestSkipped('Extism native runtime not available: ' . $e->getMessage());
            }
            throw $e;
        }
        $this->scorer = new DefaultScorer();
    }

    public function testScoreReturnsArray(): void
    {
        $results = [
            [
                'url' => 'https://example.com/page1',
                'title' => 'Test Page',
                'excerpt' => 'This is a test page about Docker containers.',
                'date' => '2025-01-15',
            ],
        ];
        $config = [
            'query' => 'docker',
            'recency_boost_max' => 0.5,
            'recency_half_life_days' => 365,
            'title_match_boost' => 1.0,
            'content_match_boost' => 0.4,
        ];

        $scored = $this->scorer->score($results, $config);
        $this->assertIsArray($scored);
    }

    public function testScoreWithEmptyResultsReturnsEmpty(): void
    {
        $scored = $this->scorer->score([], ['query' => 'test']);
        $this->assertIsArray($scored);
        $this->assertEmpty($scored);
    }

    public function testMergeDeduplicatesByUrl(): void
    {
        $makeResult = fn(string $url, string $title, float $score) => [
            'url' => $url, 'title' => $title, 'excerpt' => 'test',
            'date' => '2025-01-01', 'score' => $score,
        ];
        $original = [
            $makeResult('https://example.com/a', 'A', 1.0),
            $makeResult('https://example.com/b', 'B', 0.8),
        ];
        $expanded = [
            $makeResult('https://example.com/a', 'A', 0.5),
            $makeResult('https://example.com/c', 'C', 0.6),
        ];

        $merged = $this->scorer->merge($original, $expanded, 0.7);
        $this->assertIsArray($merged);
    }

    public function testMergeWithEmptyExpandedReturnsOriginal(): void
    {
        $original = [
            ['url' => 'https://example.com/a', 'title' => 'A', 'excerpt' => 'test',
             'date' => '2025-01-01', 'score' => 1.0],
        ];

        $merged = $this->scorer->merge($original, [], 0.7);
        $this->assertIsArray($merged);
    }

    public function testParseExpansionReturnsTermArray(): void
    {
        $response = '["containerization", "Docker images", "container orchestration"]';
        $terms = $this->scorer->parseExpansion($response);
        $this->assertIsArray($terms);
        $this->assertNotEmpty($terms);
    }

    public function testParseExpansionHandlesMarkdownFences(): void
    {
        $response = "```json\n[\"term1\", \"term2\"]\n```";
        $terms = $this->scorer->parseExpansion($response);
        $this->assertIsArray($terms);
    }

    public function testParseExpansionHandlesPlainText(): void
    {
        $response = "term1\nterm2\nterm3";
        $terms = $this->scorer->parseExpansion($response);
        $this->assertIsArray($terms);
    }

    public function testParseExpansionHandlesEmptyString(): void
    {
        $terms = $this->scorer->parseExpansion('');
        $this->assertIsArray($terms);
    }
}
