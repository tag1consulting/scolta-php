<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * Tests for the shared AiEndpointHandler class.
 */
class AiEndpointHandlerTest extends TestCase
{
    // ===================================================================
    // Validation — expandQuery
    // ===================================================================

    public function testExpandQueryRejectsEmptyString(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handleExpandQuery('');

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function testExpandQueryRejectsOverMaxLength(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handleExpandQuery(str_repeat('a', 501));

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function testExpandQueryAcceptsMaxLength(): void
    {
        $ai = new MockAiService('["term1", "term2", "term3"]');
        $handler = $this->makeHandler(aiService: $ai);
        $result = $handler->handleExpandQuery(str_repeat('a', 500));

        $this->assertTrue($result['ok']);
    }

    // ===================================================================
    // Validation — summarize
    // ===================================================================

    public function testSummarizeRejectsEmptyQuery(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handleSummarize('', 'some context');

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function testSummarizeRejectsOverMaxContext(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handleSummarize('query', str_repeat('x', 50001));

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    // ===================================================================
    // Validation — followUp
    // ===================================================================

    public function testFollowUpRejectsEmptyMessages(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handleFollowUp([]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function testFollowUpRejectsWhenLimitReached(): void
    {
        // maxFollowUps=2, formula is: followUpsSoFar = intdiv(count - 2, 2)
        // For 6 messages: intdiv(6-2, 2) = 2, which >= maxFollowUps=2 => rejected
        $handler = $this->makeHandler(maxFollowUps: 2);

        $messages = [
            ['role' => 'user', 'content' => 'initial question'],
            ['role' => 'assistant', 'content' => 'first reply'],
            ['role' => 'user', 'content' => 'follow-up 1'],
            ['role' => 'assistant', 'content' => 'reply 1'],
            ['role' => 'user', 'content' => 'follow-up 2'],
            ['role' => 'assistant', 'content' => 'reply 2'],
            ['role' => 'user', 'content' => 'follow-up 3 — too many'],
        ];

        // 7 messages: intdiv(7-2, 2) = 2, which >= 2 => rejected.
        $result = $handler->handleFollowUp($messages);

        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
    }

    public function testFollowUpCountsCorrectly(): void
    {
        // maxFollowUps=2
        // 4 messages: intdiv(4-2, 2) = 1, which < 2 => allowed
        $ai = new MockAiService('follow up response');
        $handler = $this->makeHandler(aiService: $ai, maxFollowUps: 2);

        $messages = [
            ['role' => 'user', 'content' => 'initial question'],
            ['role' => 'assistant', 'content' => 'first reply'],
            ['role' => 'user', 'content' => 'follow-up 1'],
        ];

        $result = $handler->handleFollowUp($messages);
        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['data']['remaining']);
    }

    // ===================================================================
    // Caching
    // ===================================================================

    public function testExpandQueryReturnsCachedResult(): void
    {
        $cache = new InMemoryCacheDriver();
        $ai = new MockAiService('should not be called');

        // Pre-populate cache.
        $handler = $this->makeHandler(aiService: $ai, cache: $cache, cacheTtl: 3600);
        $cacheKey = $handler->cacheKey('expand', 'test query');
        $cache->set($cacheKey, ['cached term'], 3600);

        $result = $handler->handleExpandQuery('test query');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['cached term'], $result['data']);
        $this->assertEquals(0, $ai->callCount, 'AI service should not have been called');
    }

    public function testExpandQueryStoresResultInCache(): void
    {
        $cache = new InMemoryCacheDriver();
        $ai = new MockAiService('["expanded1", "expanded2", "expanded3"]');
        $handler = $this->makeHandler(aiService: $ai, cache: $cache, cacheTtl: 3600);

        $handler->handleExpandQuery('store test');

        $cacheKey = $handler->cacheKey('expand', 'store test');
        $this->assertNotNull($cache->get($cacheKey), 'Result should be stored in cache');
    }

    public function testSummarizeUsesCacheWithGeneration(): void
    {
        $cache = new InMemoryCacheDriver();
        $ai = new MockAiService('should not be called');

        $handler = $this->makeHandler(aiService: $ai, cache: $cache, cacheTtl: 3600, generation: 5);
        $cacheKey = $handler->cacheKey('summarize', 'query', 'context');
        $cache->set($cacheKey, ['summary' => 'cached summary'], 3600);

        $result = $handler->handleSummarize('query', 'context');

        $this->assertTrue($result['ok']);
        $this->assertEquals('cached summary', $result['data']['summary']);
        $this->assertEquals(0, $ai->callCount);
    }

    public function testCacheKeyIncludesGeneration(): void
    {
        $handler1 = $this->makeHandler(generation: 1);
        $handler2 = $this->makeHandler(generation: 2);

        $key1 = $handler1->cacheKey('expand', 'test');
        $key2 = $handler2->cacheKey('expand', 'test');

        $this->assertNotEquals($key1, $key2, 'Different generations should produce different cache keys');
        $this->assertStringContainsString('_1_', $key1);
        $this->assertStringContainsString('_2_', $key2);
    }

    // ===================================================================
    // Response parsing
    // ===================================================================

    public function testParseExpansionStripsCodeFences(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->parseExpansionResponse(
            "```json\n[\"term1\", \"term2\", \"term3\"]\n```",
            'original'
        );

        $this->assertEquals(['term1', 'term2', 'term3'], $result);
    }

    public function testParseExpansionHandlesRawJson(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->parseExpansionResponse(
            '["alpha", "beta", "gamma"]',
            'original'
        );

        $this->assertEquals(['alpha', 'beta', 'gamma'], $result);
    }

    public function testParseExpansionFallsBackOnInvalidJson(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->parseExpansionResponse(
            'this is not json at all',
            'original query'
        );

        $this->assertEquals(['original query'], $result);
    }

    public function testParseExpansionFallsBackOnSingleTerm(): void
    {
        $handler = $this->makeHandler();

        // A valid JSON array with only one element should fall back.
        $result = $handler->parseExpansionResponse(
            '["only_one"]',
            'original'
        );

        $this->assertEquals(['original'], $result);
    }

    // ===================================================================
    // Error paths
    // ===================================================================

    public function testExpandQueryReturns503OnAiException(): void
    {
        $ai = new MockAiService('', throwOnMessage: true);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleExpandQuery('test query');

        $this->assertFalse($result['ok']);
        $this->assertEquals(503, $result['status']);
    }

    public function testSummarizeReturns503OnAiException(): void
    {
        $ai = new MockAiService('', throwOnMessage: true);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleSummarize('test', 'some context');

        $this->assertFalse($result['ok']);
        $this->assertEquals(503, $result['status']);
    }

    public function testFollowUpReturns503OnAiException(): void
    {
        $ai = new MockAiService('', throwOnConversation: true);
        $handler = $this->makeHandler(aiService: $ai);

        $messages = [
            ['role' => 'user', 'content' => 'hello'],
        ];

        $result = $handler->handleFollowUp($messages);

        $this->assertFalse($result['ok']);
        $this->assertEquals(503, $result['status']);
    }

    public function testExpandQueryHandlesEmptyAiResponse(): void
    {
        $ai = new MockAiService('');
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleExpandQuery('test query');

        // Empty response should fallback to original query.
        $this->assertTrue($result['ok']);
        $this->assertEquals(['test query'], $result['data']);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function makeHandler(
        ?MockAiService $aiService = null,
        ?InMemoryCacheDriver $cache = null,
        int $generation = 1,
        int $cacheTtl = 0,
        int $maxFollowUps = 3,
    ): AiEndpointHandler {
        return new AiEndpointHandler(
            aiService: $aiService ?? new MockAiService('["term1", "term2"]'),
            cache: $cache ?? new InMemoryCacheDriver(),
            generation: $generation,
            cacheTtl: $cacheTtl,
            maxFollowUps: $maxFollowUps,
        );
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * In-memory mock AI service implementing the duck-typed interface.
 */
class MockAiService
{
    public int $callCount = 0;

    public function __construct(
        private readonly string $response = '',
        private readonly bool $throwOnMessage = false,
        private readonly bool $throwOnConversation = false,
    ) {}

    public function getExpandPrompt(): string
    {
        return 'Expand the following search query.';
    }

    public function getSummarizePrompt(): string
    {
        return 'Summarize the following search results.';
    }

    public function getFollowUpPrompt(): string
    {
        return 'Continue the conversation.';
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        if ($this->throwOnMessage) {
            throw new \RuntimeException('AI service unavailable');
        }
        $this->callCount++;
        return $this->response;
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        if ($this->throwOnConversation) {
            throw new \RuntimeException('AI service unavailable');
        }
        $this->callCount++;
        return $this->response;
    }
}

/**
 * Simple in-memory cache driver for testing.
 */
class InMemoryCacheDriver implements CacheDriverInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store[$key] = $value;
    }
}
