<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\Scolta\Prompt\NullEnricher;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

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

    public function testCacheTtlZeroNeverReadsCache(): void
    {
        $cache = new TrackingCacheDriver();
        $ai = new MockAiService('["term1", "term2"]');
        $handler = $this->makeHandler(aiService: $ai, cache: $cache, cacheTtl: 0);

        $handler->handleExpandQuery('test query');

        $this->assertEquals(0, $cache->getCalls, 'cache->get() should never be called when cacheTtl=0');
    }

    public function testCacheTtlZeroNeverWritesCache(): void
    {
        $cache = new TrackingCacheDriver();
        $ai = new MockAiService('["term1", "term2"]');
        $handler = $this->makeHandler(aiService: $ai, cache: $cache, cacheTtl: 0);

        $handler->handleExpandQuery('test query');

        $this->assertEquals(0, $cache->setCalls, 'cache->set() should never be called when cacheTtl=0');
    }

    public function testMaxFollowUpsZeroBlocksImmediately(): void
    {
        $handler = $this->makeHandler(maxFollowUps: 0);

        // Even the very first follow-up (3 messages: initial + reply + follow-up)
        // should be rejected when maxFollowUps=0.
        // Formula: intdiv(3 - 2, 2) = 0 >= maxFollowUps=0 => rejected.
        $messages = [
            ['role' => 'user', 'content' => 'initial question'],
            ['role' => 'assistant', 'content' => 'first reply'],
            ['role' => 'user', 'content' => 'follow-up attempt'],
        ];

        $result = $handler->handleFollowUp($messages);

        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
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
    // Prompt enrichment
    // ===================================================================

    public function testNullEnricherPassesThroughUnchanged(): void
    {
        $enricher = new NullEnricher();
        $original = 'You are a helpful search assistant.';

        $result = $enricher->enrich($original, 'summarize', ['query' => 'test']);

        $this->assertSame($original, $result);
    }

    public function testExpandQueryCallsEnricherBeforeAiService(): void
    {
        $enricher = new SpyEnricher('ENRICHED: ');
        $ai = new PromptCapturingAiService('["term1", "term2", "term3"]');
        $handler = $this->makeHandler(aiService: $ai, enricher: $enricher);

        $result = $handler->handleExpandQuery('test query');

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $enricher->callCount);
        $this->assertEquals('expand_query', $enricher->lastPromptName);
        $this->assertEquals(['query' => 'test query'], $enricher->lastContext);
        $this->assertStringStartsWith('ENRICHED: ', $ai->lastSystemPrompt);
    }

    public function testSummarizeCallsEnricherBeforeAiService(): void
    {
        $enricher = new SpyEnricher('ENRICHED: ');
        $ai = new PromptCapturingAiService('A helpful summary.');
        $handler = $this->makeHandler(aiService: $ai, enricher: $enricher);

        $result = $handler->handleSummarize('test query', 'some context');

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $enricher->callCount);
        $this->assertEquals('summarize', $enricher->lastPromptName);
        $this->assertEquals(['query' => 'test query', 'context' => 'some context'], $enricher->lastContext);
        $this->assertStringStartsWith('ENRICHED: ', $ai->lastSystemPrompt);
    }

    public function testFollowUpCallsEnricherBeforeAiService(): void
    {
        $enricher = new SpyEnricher('ENRICHED: ');
        $ai = new PromptCapturingAiService('follow up response', captureConversation: true);
        $handler = $this->makeHandler(aiService: $ai, enricher: $enricher);

        $messages = [['role' => 'user', 'content' => 'hello']];
        $result = $handler->handleFollowUp($messages);

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $enricher->callCount);
        $this->assertEquals('follow_up', $enricher->lastPromptName);
        $this->assertEquals(['messages' => $messages], $enricher->lastContext);
        $this->assertStringStartsWith('ENRICHED: ', $ai->lastSystemPrompt);
    }

    public function testCustomEnricherModifiesPrompt(): void
    {
        $enricher = new class () implements PromptEnricherInterface {
            public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string
            {
                return $resolvedPrompt . "\n\nAlways mention our return policy.";
            }
        };

        $ai = new PromptCapturingAiService('["term1", "term2"]');
        $handler = $this->makeHandler(aiService: $ai, enricher: $enricher);

        $handler->handleExpandQuery('pricing');

        $this->assertStringContainsString('Always mention our return policy.', $ai->lastSystemPrompt);
    }

    public function testDefaultEnricherIsNullEnricher(): void
    {
        // Handler without explicit enricher should use NullEnricher (no modification).
        $ai = new PromptCapturingAiService('["term1", "term2"]');
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
        );

        $handler->handleExpandQuery('test');

        // The prompt should be the raw prompt from the AI service, unmodified.
        $this->assertEquals('Expand the following search query.', $ai->lastSystemPrompt);
    }

    // ===================================================================
    // Language instruction
    // ===================================================================

    public function testSingleLanguageDoesNotAddInstruction(): void
    {
        $ai = new PromptCapturingAiService('A helpful summary.');
        $handler = $this->makeHandler(aiService: $ai, aiLanguages: ['en']);

        $handler->handleSummarize('test query', 'some context');

        $this->assertStringNotContainsString('supported languages', $ai->lastSystemPrompt);
    }

    public function testMultipleLanguagesAddsInstructionToSummarize(): void
    {
        $ai = new PromptCapturingAiService('A helpful summary.');
        $handler = $this->makeHandler(aiService: $ai, aiLanguages: ['en', 'es', 'fr']);

        $handler->handleSummarize('test query', 'some context');

        $this->assertStringContainsString('en, es, fr', $ai->lastSystemPrompt);
        $this->assertStringContainsString('Respond in the same language', $ai->lastSystemPrompt);
        $this->assertStringContainsString('Otherwise respond in en', $ai->lastSystemPrompt);
    }

    public function testMultipleLanguagesAddsInstructionToExpandQuery(): void
    {
        $ai = new PromptCapturingAiService('["term1", "term2", "term3"]');
        $handler = $this->makeHandler(aiService: $ai, aiLanguages: ['en', 'de']);

        $handler->handleExpandQuery('test query');

        $this->assertStringContainsString('en, de', $ai->lastSystemPrompt);
        $this->assertStringContainsString('Return expansion terms', $ai->lastSystemPrompt);
    }

    public function testMultipleLanguagesAddsInstructionToFollowUp(): void
    {
        $ai = new PromptCapturingAiService('follow up response', captureConversation: true);
        $handler = $this->makeHandler(aiService: $ai, aiLanguages: ['en', 'ja']);

        $messages = [['role' => 'user', 'content' => 'hello']];
        $handler->handleFollowUp($messages);

        $this->assertStringContainsString('en, ja', $ai->lastSystemPrompt);
        $this->assertStringContainsString('Respond in the same language', $ai->lastSystemPrompt);
    }

    public function testLanguageInstructionMentionsAllConfiguredLanguages(): void
    {
        $ai = new PromptCapturingAiService('A helpful summary.');
        $handler = $this->makeHandler(aiService: $ai, aiLanguages: ['en', 'es', 'fr', 'de', 'ja']);

        $handler->handleSummarize('test query', 'some context');

        $this->assertStringContainsString('en, es, fr, de, ja', $ai->lastSystemPrompt);
    }

    public function testDefaultLanguagesDoNotAddInstruction(): void
    {
        // Default constructor value (['en']) should not add instruction.
        $ai = new PromptCapturingAiService('A helpful summary.');
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
        );

        $handler->handleSummarize('test query', 'some context');

        $this->assertStringNotContainsString('supported languages', $ai->lastSystemPrompt);
    }

    // ===================================================================
    // AI feature toggles
    // ===================================================================

    public function testExpandQueryDisabledReturns404(): void
    {
        $handler = $this->makeHandler(aiExpandQuery: false);
        $result = $handler->handleExpandQuery('test query');

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['status']);
        $this->assertEquals('Feature disabled', $result['error']);
    }

    public function testExpandQueryDisabledDoesNotCallAiService(): void
    {
        $ai = new MockAiService('["term1", "term2"]');
        $handler = $this->makeHandler(aiService: $ai, aiExpandQuery: false);
        $handler->handleExpandQuery('test query');

        $this->assertEquals(0, $ai->callCount, 'AI service should not be called when expand query is disabled');
    }

    public function testSummarizeDisabledReturns404(): void
    {
        $handler = $this->makeHandler(aiSummarize: false);
        $result = $handler->handleSummarize('test query', 'some context');

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['status']);
        $this->assertEquals('Feature disabled', $result['error']);
    }

    public function testSummarizeDisabledDoesNotCallAiService(): void
    {
        $ai = new MockAiService('A summary.');
        $handler = $this->makeHandler(aiService: $ai, aiSummarize: false);
        $handler->handleSummarize('test query', 'some context');

        $this->assertEquals(0, $ai->callCount, 'AI service should not be called when summarize is disabled');
    }

    public function testFollowUpUnaffectedByExpandQueryToggle(): void
    {
        // follow-up has no enable/disable toggle; it should always proceed.
        $ai = new MockAiService('follow up response');
        $handler = $this->makeHandler(aiService: $ai, aiExpandQuery: false, aiSummarize: false);
        $messages = [['role' => 'user', 'content' => 'hello']];
        $result = $handler->handleFollowUp($messages);

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $ai->callCount);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function makeHandler(
        ?MockAiService $aiService = null,
        ?CacheDriverInterface $cache = null,
        int $generation = 1,
        int $cacheTtl = 0,
        int $maxFollowUps = 3,
        ?PromptEnricherInterface $enricher = null,
        array $aiLanguages = ['en'],
        bool $aiExpandQuery = true,
        bool $aiSummarize = true,
    ): AiEndpointHandler {
        return new AiEndpointHandler(
            aiService: $aiService ?? new MockAiService('["term1", "term2"]'),
            cache: $cache ?? new InMemoryCacheDriver(),
            generation: $generation,
            cacheTtl: $cacheTtl,
            maxFollowUps: $maxFollowUps,
            promptEnricher: $enricher ?? new NullEnricher(),
            aiLanguages: $aiLanguages,
            aiExpandQuery: $aiExpandQuery,
            aiSummarize: $aiSummarize,
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
    ) {
    }

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

/**
 * Cache driver that tracks how many times get() and set() are called.
 */
class TrackingCacheDriver implements CacheDriverInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;

    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        $this->getCalls++;

        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->setCalls++;
        $this->store[$key] = $value;
    }
}

/**
 * Spy enricher that records calls and prepends a prefix.
 */
class SpyEnricher implements PromptEnricherInterface
{
    public int $callCount = 0;
    public ?string $lastPromptName = null;
    public ?array $lastContext = null;
    public ?string $lastResolvedPrompt = null;

    public function __construct(
        private readonly string $prefix = '',
    ) {
    }

    public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string
    {
        $this->callCount++;
        $this->lastResolvedPrompt = $resolvedPrompt;
        $this->lastPromptName = $promptName;
        $this->lastContext = $context;

        return $this->prefix . $resolvedPrompt;
    }
}

/**
 * AI service that captures the system prompt passed to message()/conversation().
 */
class PromptCapturingAiService extends MockAiService
{
    public ?string $lastSystemPrompt = null;

    public function __construct(
        string $response = '',
        private readonly bool $captureConversation = false,
    ) {
        parent::__construct($response);
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        $this->lastSystemPrompt = $systemPrompt;
        return parent::message($systemPrompt, $userMessage, $maxTokens);
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        $this->lastSystemPrompt = $systemPrompt;
        return parent::conversation($systemPrompt, $messages, $maxTokens);
    }
}
