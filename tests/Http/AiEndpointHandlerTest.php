<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Exception\RateLimitException;
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
        $result = $handler->handleSummarize('query', str_repeat('x', 100001));

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

    public function testFollowUpAcceptsLiteralZeroContent(): void
    {
        // empty('0') is true — the previous empty() check rejected the
        // legitimate one-character message "0".
        $ai = new MockAiService('zero is fine');
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => '0'],
        ]);

        $this->assertTrue($result['ok']);
    }

    public function testFollowUpRejectsNonStringContent(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => ['nested' => 'array']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function testFollowUpRejectsOversizedMessage(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => str_repeat('x', 100001)],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
        $this->assertEquals('Message too long', $result['error']);
    }

    public function testFollowUpRejectsOversizedConversationTotal(): void
    {
        // Each message is under the per-message cap, but together they
        // exceed the total cap (5 × 90k = 450k > 400k).
        $handler = $this->makeHandler(maxFollowUps: 50);

        $big = str_repeat('x', 90000);
        $messages = [];
        for ($i = 0; $i < 2; $i++) {
            $messages[] = ['role' => 'user', 'content' => $big];
            $messages[] = ['role' => 'assistant', 'content' => $big];
        }
        $messages[] = ['role' => 'user', 'content' => $big];

        $result = $handler->handleFollowUp($messages);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
        $this->assertEquals('Conversation too long', $result['error']);
    }

    public function testFollowUpAcceptsLargeButLegitimateContextMessage(): void
    {
        // The first user turn legitimately embeds ~50k of search context.
        $ai = new MockAiService('ok');
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => str_repeat('c', 50000)],
        ]);

        $this->assertTrue($result['ok']);
    }

    // ===================================================================
    // Caching
    // ===================================================================

    public function testExpandQueryReturnsCachedResult(): void
    {
        $cache = new InMemoryCacheDriver();
        $ai = new MockAiService('should not be called');

        // Pre-populate cache with new payload format.
        $handler = $this->makeHandler(aiService: $ai, cache: $cache, cacheTtl: 3600);
        $cacheKey = $handler->cacheKey('expand', 'test query');
        $cache->set($cacheKey, ['terms' => ['cached term'], 'expand_primary_weight' => 0.5], 3600);

        $result = $handler->handleExpandQuery('test query');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['cached term'], $result['data']['terms']);
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
            'original',
        );

        $this->assertEquals(['term1', 'term2', 'term3'], $result);
    }

    public function testParseExpansionHandlesRawJson(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->parseExpansionResponse(
            '["alpha", "beta", "gamma"]',
            'original',
        );

        $this->assertEquals(['alpha', 'beta', 'gamma'], $result);
    }

    public function testParseExpansionHandlesObjectFormat(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->parseExpansionResponse(
            '{"terms": ["alpha", "beta", "gamma"]}',
            'original',
        );

        $this->assertEquals(['alpha', 'beta', 'gamma'], $result);
    }

    public function testParseExpansionFallsBackOnInvalidJson(): void
    {
        $handler = $this->makeHandler();

        $result = $handler->parseExpansionResponse(
            'this is not json at all',
            'original query',
        );

        $this->assertEquals(['original query'], $result);
    }

    public function testParseExpansionFallsBackOnSingleTerm(): void
    {
        $handler = $this->makeHandler();

        // A valid JSON array with only one element should fall back.
        $result = $handler->parseExpansionResponse(
            '["only_one"]',
            'original',
        );

        $this->assertEquals(['original'], $result);
    }

    // ===================================================================
    // Sort hint — parsing
    // ===================================================================

    public function testSortHintParsedFromObjectFormat(): void
    {
        $ai = new MockAiService('{"terms": ["gem", "gemstone", "rock"], "sort": {"field": "price", "direction": "desc"}}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price', 'date']);

        $result = $handler->handleExpandQuery('most expensive stone');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'price', 'direction' => 'desc'], $result['data']['sort_hint']);
    }

    public function testSortHintAbsentWhenLlmOmitsIt(): void
    {
        $ai = new MockAiService('{"terms": ["gem", "gemstone", "mineral"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('blue stones');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    public function testSortHintAbsentWhenNoSortableFieldsConfigured(): void
    {
        // Even if LLM hallucinated a sort hint, with no sortable fields it should be ignored.
        $ai = new MockAiService('{"terms": ["gem", "rock", "mineral"], "sort": {"field": "price", "direction": "desc"}}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: []);

        $result = $handler->handleExpandQuery('most expensive stone');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    public function testSortHintIgnoredWhenFieldNotInSortableList(): void
    {
        $ai = new MockAiService('{"terms": ["gem", "rock"], "sort": {"field": "unknown_field", "direction": "desc"}}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price', 'date']);

        $result = $handler->handleExpandQuery('most expensive stone');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    public function testSortHintIgnoredWhenDirectionInvalid(): void
    {
        $ai = new MockAiService('{"terms": ["gem", "rock"], "sort": {"field": "price", "direction": "invalid"}}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive stone');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    public function testSortHintIgnoredWhenSortIsNotAnArray(): void
    {
        $ai = new MockAiService('{"terms": ["gem", "rock"], "sort": "price:desc"}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive stone');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    public function testSortHintAscDirectionAllowed(): void
    {
        $ai = new MockAiService('{"terms": ["affordable", "budget"], "sort": {"field": "price", "direction": "asc"}}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('cheapest stone');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'price', 'direction' => 'asc'], $result['data']['sort_hint']);
    }

    // ===================================================================
    // Sort hint — ascending price vocabulary (#124)
    // ===================================================================

    public function testPromptContainsAscendingPricePatterns(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $handler->handleExpandQuery('cheapest crystals');

        $this->assertStringContainsString('cheapest', $ai->lastSystemPrompt);
        $this->assertStringContainsString('lowest price', $ai->lastSystemPrompt);
        $this->assertStringContainsString('most affordable', $ai->lastSystemPrompt);
        $this->assertStringContainsString('least expensive', $ai->lastSystemPrompt);
        $this->assertStringContainsString('budget', $ai->lastSystemPrompt);
    }

    public function testPromptSpecifiesAscDirectionForCheapestPatterns(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $handler->handleExpandQuery('cheapest crystals');

        $this->assertStringContainsString('Price/cost (asc)', $ai->lastSystemPrompt);
        $this->assertStringContainsString('direction asc', $ai->lastSystemPrompt);
    }

    public function testPromptSpecifiesDescDirectionForExpensivePatterns(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $handler->handleExpandQuery('most expensive crystals');

        $this->assertStringContainsString('Price/cost (desc)', $ai->lastSystemPrompt);
        $this->assertStringContainsString('direction desc', $ai->lastSystemPrompt);
    }

    public function testCheapestQueryParsesAscSortHint(): void
    {
        $ai = new MockAiService('{"terms": ["crystal", "gem"], "sort": {"field": "price", "direction": "asc"}, "subject_terms": ["crystals"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('cheapest crystals');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'price', 'direction' => 'asc'], $result['data']['sort_hint']);
        $this->assertEquals(['crystals'], $result['data']['subject_terms']);
    }

    public function testLowestPriceQueryParsesAscSortHint(): void
    {
        $ai = new MockAiService('{"terms": ["crystal", "gem"], "sort": {"field": "price", "direction": "asc"}, "subject_terms": ["crystals"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('lowest price crystals');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'price', 'direction' => 'asc'], $result['data']['sort_hint']);
    }

    public function testMostAffordableQueryParsesAscSortHint(): void
    {
        $ai = new MockAiService('{"terms": ["crystal", "gem"], "sort": {"field": "price", "direction": "asc"}, "subject_terms": ["crystals"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most affordable crystals');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'price', 'direction' => 'asc'], $result['data']['sort_hint']);
    }

    public function testLeastExpensiveQueryParsesAscSortHint(): void
    {
        $ai = new MockAiService('{"terms": ["crystal", "gem"], "sort": {"field": "price", "direction": "asc"}, "subject_terms": ["crystals"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('least expensive crystals');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'price', 'direction' => 'asc'], $result['data']['sort_hint']);
    }

    public function testMostExpensiveStillParsesDescSortHint(): void
    {
        $ai = new MockAiService('{"terms": ["crystal", "gem"], "sort": {"field": "price", "direction": "desc"}, "subject_terms": ["crystals"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive crystals');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'price', 'direction' => 'desc'], $result['data']['sort_hint']);
    }

    public function testNonSortQueryOmitsSortHint(): void
    {
        $ai = new MockAiService('{"terms": ["crystal", "healing", "meditation"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('crystals for meditation');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    public function testLegacyArrayResponseStillWorksWithSortableFields(): void
    {
        // Custom prompt or cached response may still return a JSON array — must parse without error.
        $ai = new MockAiService('["gem", "gemstone", "mineral"]');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('blue stones');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['gem', 'gemstone', 'mineral'], $result['data']['terms']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    // ===================================================================
    // Sort hint — sortable fields in prompt
    // ===================================================================

    public function testSortableFieldsAppendedToPromptWhenConfigured(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock", "mineral"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price', 'date', 'rating']);

        $handler->handleExpandQuery('test query');

        $this->assertStringContainsString('- price', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- date', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- rating', $ai->lastSystemPrompt);
        $this->assertStringContainsString('SORT INTENT', $ai->lastSystemPrompt);
    }

    public function testSortableFieldsNotAppendedWhenEmpty(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock", "mineral"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: []);

        $handler->handleExpandQuery('test query');

        $this->assertStringNotContainsString('SORT INTENT', $ai->lastSystemPrompt);
        $this->assertStringNotContainsString('sortable', $ai->lastSystemPrompt);
    }

    // ===================================================================
    // Sort hint — prompt content (false positive guard)
    // ===================================================================

    public function testSortIntentPromptForbidsSuperlativeQualifiers(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock", "mineral"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $handler->handleExpandQuery('test query');

        $this->assertStringContainsString(
            'SUPERLATIVES AS QUALIFIERS',
            $ai->lastSystemPrompt,
            'Sort intent prompt must explicitly address superlatives used as qualifiers',
        );
        $this->assertStringContainsString(
            'most popular',
            $ai->lastSystemPrompt,
            'Sort intent prompt must list "most popular" as a counter-example',
        );
    }

    public function testSortIntentPromptRequiresSemanticFieldMatch(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price', 'date']);

        $handler->handleExpandQuery('test');

        $this->assertMatchesRegularExpression(
            '/semantically? map|direct.*semantic|semantic.*match/i',
            $ai->lastSystemPrompt,
            'Sort intent prompt must require a semantic match between the sort signal and the field',
        );
    }

    public function testSortIntentPromptPrefersFalseNegatives(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $handler->handleExpandQuery('test');

        $this->assertMatchesRegularExpression(
            '/false negative|prefer.*omit|uncertain.*omit|when.*doubt.*omit/i',
            $ai->lastSystemPrompt,
            'Sort intent prompt must instruct the LLM to prefer omitting the sort key over false positives',
        );
    }

    // ===================================================================
    // Subject terms — parsing
    // ===================================================================

    public function testSubjectTermsParsedWhenPresentWithSort(): void
    {
        $ai = new MockAiService('{"terms": ["gem", "gemstone"], "sort": {"field": "price", "direction": "desc"}, "subject_terms": ["tooth"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive tooth');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['tooth'], $result['data']['subject_terms']);
        $this->assertArrayHasKey('sort_hint', $result['data']);
    }

    public function testSubjectTermsMultipleWords(): void
    {
        $ai = new MockAiService('{"terms": ["gemstone", "mineral"], "sort": {"field": "price", "direction": "asc"}, "subject_terms": ["blue stone"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('cheapest blue stone');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['blue stone'], $result['data']['subject_terms']);
    }

    public function testSubjectTermsAbsentWhenOnlySortIntent(): void
    {
        // "most expensive" — no subject — LLM returns empty subject_terms.
        $ai = new MockAiService('{"terms": ["high price", "costly"], "sort": {"field": "price", "direction": "desc"}, "subject_terms": []}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('subject_terms', $result['data']);
    }

    public function testSubjectTermsAbsentWhenOmittedByLlm(): void
    {
        // LLM didn't return subject_terms at all.
        $ai = new MockAiService('{"terms": ["gem", "rock"], "sort": {"field": "price", "direction": "desc"}}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive stone');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('subject_terms', $result['data']);
    }

    public function testSubjectTermsAbsentWhenNoSort(): void
    {
        // No sort intent — subject_terms should not be present.
        $ai = new MockAiService('{"terms": ["gem", "gemstone", "mineral"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('blue stones');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('subject_terms', $result['data']);
        $this->assertArrayNotHasKey('sort_hint', $result['data']);
    }

    public function testSubjectTermsMalformedNotArrayIgnored(): void
    {
        // LLM returned subject_terms as a string instead of array.
        $ai = new MockAiService('{"terms": ["gem", "rock"], "sort": {"field": "price", "direction": "desc"}, "subject_terms": "tooth"}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive tooth');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('subject_terms', $result['data']);
    }

    public function testSubjectTermsFiltersNonStringEntries(): void
    {
        // LLM returned mixed array — only valid strings should survive.
        $ai = new MockAiService('{"terms": ["gem", "rock"], "sort": {"field": "price", "direction": "desc"}, "subject_terms": ["tooth", null, 42, "fossil"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $result = $handler->handleExpandQuery('most expensive tooth fossil');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['tooth', 'fossil'], $result['data']['subject_terms']);
    }

    public function testSubjectTermsInPromptWhenSortableFieldsConfigured(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock", "mineral"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $handler->handleExpandQuery('test query');

        $this->assertStringContainsString('SUBJECT TERMS', $ai->lastSystemPrompt);
        $this->assertStringContainsString('subject_terms', $ai->lastSystemPrompt);
    }

    public function testSubjectTermsExampleInPromptShowsEmptyForSortOnlyQuery(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price']);

        $handler->handleExpandQuery('test');

        $this->assertStringContainsString('most expensive', $ai->lastSystemPrompt);
        $this->assertStringContainsString('subject_terms: []', $ai->lastSystemPrompt);
    }

    // ===================================================================
    // Sort hint — cache round-trip
    // ===================================================================

    public function testSortHintSurvivesCacheRoundTrip(): void
    {
        $cache = new InMemoryCacheDriver();
        $ai = new MockAiService('{"terms": ["gem", "rock"], "sort": {"field": "price", "direction": "desc"}}');
        $handler = $this->makeHandler(aiService: $ai, cache: $cache, cacheTtl: 3600, sortableFields: ['price']);

        // First call — populates cache.
        $result1 = $handler->handleExpandQuery('most expensive stone');

        // Second call — served from cache via a fresh handler (same cache).
        $handler2 = $this->makeHandler(aiService: new MockAiService('should not be called'), cache: $cache, cacheTtl: 3600, sortableFields: ['price']);
        $result2 = $handler2->handleExpandQuery('most expensive stone');

        $this->assertEquals($result1['data'], $result2['data']);
        $this->assertEquals(['field' => 'price', 'direction' => 'desc'], $result2['data']['sort_hint']);
    }

    // ===================================================================
    // Error paths: expand/summarize degrade, follow-up still 503
    //
    // Query expansion and summarization are non-essential search enhancements:
    // any provider failure must degrade to HTTP 200 (unexpanded results / no
    // summary) rather than a 503 that blocks the search path or surfaces an
    // error banner. The underlying error is still logged server-side. Follow-up
    // is the request's primary purpose, so it keeps its distinct 503.
    // ===================================================================

    public function testExpandQueryDegradesToUnexpandedOnAiException(): void
    {
        $ai = new MockAiService('', throwOnMessage: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $result = $handler->handleExpandQuery('test query');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertEquals(['test query'], $result['data']['terms']);
        $this->assertArrayHasKey('expand_primary_weight', $result['data']);
        $this->assertNotEmpty($logger->errors, 'Underlying failure should be logged for diagnosis');
    }

    public function testSummarizeDegradesToNoSummaryOnAiException(): void
    {
        $ai = new MockAiService('', throwOnMessage: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $result = $handler->handleSummarize('test', 'some context');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertEquals([], $result['data']);
        $this->assertNotEmpty($logger->errors, 'Underlying failure should be logged for diagnosis');
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

    // ===================================================================
    // Invalid API key: expand/summarize degrade, follow-up keeps 401
    // ===================================================================

    public function testExpandQueryDegradesToUnexpandedOnInvalidApiKey(): void
    {
        $ai = new MockAiService('', throwApiKeyInvalid: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $result = $handler->handleExpandQuery('test query');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertEquals(['test query'], $result['data']['terms']);
        $this->assertNotEmpty($logger->errors, 'The 401 should be preserved in the server log');
    }

    public function testSummarizeDegradesToNoSummaryOnInvalidApiKey(): void
    {
        $ai = new MockAiService('', throwApiKeyInvalid: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $result = $handler->handleSummarize('test', 'some context');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertEquals([], $result['data']);
        $this->assertNotEmpty($logger->errors, 'The 401 should be preserved in the server log');
    }

    public function testFollowUpReturns401OnInvalidApiKey(): void
    {
        $ai = new MockAiService('', throwApiKeyInvalid: true);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleFollowUp([['role' => 'user', 'content' => 'hello']]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(401, $result['status']);
    }

    // ===================================================================
    // Rate limiting: expand/summarize degrade, follow-up keeps 429
    // ===================================================================

    public function testExpandQueryDegradesToUnexpandedOnRateLimit(): void
    {
        $ai = new MockAiService('', throwRateLimit: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $result = $handler->handleExpandQuery('test query');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertEquals(['test query'], $result['data']['terms']);
        $this->assertNotEmpty($logger->errors, 'The 429 should be preserved in the server log');
    }

    public function testSummarizeDegradesToNoSummaryOnRateLimit(): void
    {
        $ai = new MockAiService('', throwRateLimit: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $result = $handler->handleSummarize('test', 'some context');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertEquals([], $result['data']);
        $this->assertNotEmpty($logger->errors, 'The 429 should be preserved in the server log');
    }

    public function testFollowUpReturns429OnRateLimit(): void
    {
        $ai = new MockAiService('', throwRateLimit: true);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleFollowUp([['role' => 'user', 'content' => 'hello']]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
    }

    public function testFollowUpRateLimitIncludesRetryAfterWhenPresent(): void
    {
        $ai = new MockAiService('', throwRateLimit: true, rateLimitRetryAfter: '60');
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleFollowUp([['role' => 'user', 'content' => 'hello']]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
        $this->assertEquals('60', $result['retry_after']);
    }

    public function testFollowUpRateLimitOmitsRetryAfterWhenAbsent(): void
    {
        $ai = new MockAiService('', throwRateLimit: true, rateLimitRetryAfter: null);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleFollowUp([['role' => 'user', 'content' => 'hello']]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
        $this->assertArrayNotHasKey('retry_after', $result);
    }

    // ===================================================================
    // No API key — graceful degradation
    // ===================================================================

    public function testSummarizeReturns200WithEmptyDataWhenNoApiKey(): void
    {
        $ai = new MockAiService('', throwApiKeyMissing: true);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleSummarize('test query', 'some context');

        $this->assertTrue($result['ok']);
        $this->assertEquals([], $result['data']);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testExpandQueryReturns200WithOriginalQueryWhenNoApiKey(): void
    {
        $ai = new MockAiService('', throwApiKeyMissing: true);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleExpandQuery('my search query');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['my search query'], $result['data']['terms']);
        $this->assertArrayHasKey('expand_primary_weight', $result['data']);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testFollowUpReturns200WithEmptyResponseWhenNoApiKey(): void
    {
        $ai = new MockAiService('', throwApiKeyMissing: true);
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleFollowUp([['role' => 'user', 'content' => 'hello']]);

        $this->assertTrue($result['ok']);
        $this->assertEquals('', $result['data']['response']);
        $this->assertEquals(0, $result['data']['remaining']);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testSummarizeNoApiKeyDoesNotLog503(): void
    {
        $ai = new MockAiService('', throwApiKeyMissing: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $handler->handleSummarize('test', 'context');

        $this->assertEmpty($logger->errors, 'Missing API key should not be logged as an error');
    }

    public function testExpandQueryNoApiKeyDoesNotLog503(): void
    {
        $ai = new MockAiService('', throwApiKeyMissing: true);
        $logger = new SpyLogger();
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger,
        );

        $handler->handleExpandQuery('test query');

        $this->assertEmpty($logger->errors, 'Missing API key should not be logged as an error');
    }

    public function testExpandQueryHandlesEmptyAiResponse(): void
    {
        $ai = new MockAiService('');
        $handler = $this->makeHandler(aiService: $ai);

        $result = $handler->handleExpandQuery('test query');

        // Empty response should fallback to original query.
        $this->assertTrue($result['ok']);
        $this->assertEquals(['test query'], $result['data']['terms']);
    }

    public function testExpandQueryResponseIncludesExpandPrimaryWeight(): void
    {
        $ai = new MockAiService('["term1", "term2"]');
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: new InMemoryCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            expandPrimaryWeight: 0.8,
        );

        $result = $handler->handleExpandQuery('test query');

        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['data']['terms']);
        $this->assertSame(0.8, $result['data']['expand_primary_weight']);
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
        $enricher = new class implements PromptEnricherInterface {
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

    // ===================================================================
    // Sortable field descriptions
    // ===================================================================

    public function testSortableFieldsWithDescriptionsAppearsInPrompt(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(
            aiService: $ai,
            sortableFields: ['price', 'word_count'],
            sortableFieldDescriptions: ['price' => 'Product price in store currency', 'word_count' => 'Article length in words'],
        );

        $handler->handleExpandQuery('test');

        $this->assertStringContainsString('- price: Product price in store currency', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- word_count: Article length in words', $ai->lastSystemPrompt);
    }

    public function testSortableFieldsWithoutDescriptionsFallBackToBareNames(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(
            aiService: $ai,
            sortableFields: ['price', 'date'],
        );

        $handler->handleExpandQuery('test');

        // Bare names with no description suffix.
        $this->assertStringContainsString('- price', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- date', $ai->lastSystemPrompt);
        $this->assertStringNotContainsString('- price:', $ai->lastSystemPrompt);
    }

    public function testSortableFieldDescriptionsIgnoredWhenNoSortableFields(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(
            aiService: $ai,
            sortableFields: [],
            sortableFieldDescriptions: ['price' => 'Should not appear'],
        );

        $handler->handleExpandQuery('test');

        $this->assertStringNotContainsString('SORT INTENT', $ai->lastSystemPrompt);
        $this->assertStringNotContainsString('Should not appear', $ai->lastSystemPrompt);
    }

    // ===================================================================
    // Filter fields — prompt generation
    // ===================================================================

    public function testFilterFieldsInstructionAppearsWhenConfigured(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(
            aiService: $ai,
            filterFields: ['topic', 'era'],
            filterFieldDescriptions: ['topic' => 'Subject area (Science, History, etc.)', 'era' => 'Historical period'],
        );

        $handler->handleExpandQuery('test');

        $this->assertStringContainsString('FILTER INTENT', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- topic: Subject area (Science, History, etc.)', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- era: Historical period', $ai->lastSystemPrompt);
    }

    public function testFilterFieldsInstructionAbsentWhenNotConfigured(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(aiService: $ai, filterFields: []);

        $handler->handleExpandQuery('test');

        $this->assertStringNotContainsString('FILTER INTENT', $ai->lastSystemPrompt);
    }

    public function testFilterFieldsWithoutDescriptionsFallBackToBareNames(): void
    {
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(
            aiService: $ai,
            filterFields: ['topic', 'era'],
        );

        $handler->handleExpandQuery('test');

        $this->assertStringContainsString('- topic', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- era', $ai->lastSystemPrompt);
        $this->assertStringNotContainsString('- topic:', $ai->lastSystemPrompt);
    }

    // ===================================================================
    // Filter hint — parsing
    // ===================================================================

    public function testFilterHintParsedFromObjectFormat(): void
    {
        $ai = new MockAiService('{"terms": ["water", "hydrology"], "filters": {"topic": "Science"}}');
        $handler = $this->makeHandler(aiService: $ai, filterFields: ['topic', 'era']);

        $result = $handler->handleExpandQuery('Science articles about water');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['topic' => 'Science'], $result['data']['filter_hint']);
    }

    public function testFilterHintMultipleDimensions(): void
    {
        $ai = new MockAiService('{"terms": ["roman", "engineering"], "filters": {"topic": "History", "era": "Ancient"}}');
        $handler = $this->makeHandler(aiService: $ai, filterFields: ['topic', 'era']);

        $result = $handler->handleExpandQuery('Ancient Roman engineering');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['topic' => 'History', 'era' => 'Ancient'], $result['data']['filter_hint']);
    }

    public function testFilterHintAbsentWhenLlmOmitsIt(): void
    {
        $ai = new MockAiService('{"terms": ["water", "aqua"]}');
        $handler = $this->makeHandler(aiService: $ai, filterFields: ['topic']);

        $result = $handler->handleExpandQuery('water');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('filter_hint', $result['data']);
    }

    public function testFilterHintAbsentWhenNoFilterFieldsConfigured(): void
    {
        $ai = new MockAiService('{"terms": ["water", "aqua"], "filters": {"topic": "Science"}}');
        $handler = $this->makeHandler(aiService: $ai, filterFields: []);

        $result = $handler->handleExpandQuery('Science water');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('filter_hint', $result['data']);
    }

    public function testFilterHintInvalidDimensionRejected(): void
    {
        $ai = new MockAiService('{"terms": ["water", "aqua"], "filters": {"unknown_dim": "Science"}}');
        $handler = $this->makeHandler(aiService: $ai, filterFields: ['topic', 'era']);

        $result = $handler->handleExpandQuery('water');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('filter_hint', $result['data']);
    }

    public function testFilterHintMalformedIgnored(): void
    {
        $ai = new MockAiService('{"terms": ["water", "aqua"], "filters": "invalid"}');
        $handler = $this->makeHandler(aiService: $ai, filterFields: ['topic']);

        $result = $handler->handleExpandQuery('water');

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('filter_hint', $result['data']);
    }

    public function testFilterHintAndSortHintCoexist(): void
    {
        $ai = new MockAiService('{"terms": ["science", "articles"], "sort": {"field": "date", "direction": "desc"}, "filters": {"topic": "Science"}}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['date'], filterFields: ['topic']);

        $result = $handler->handleExpandQuery('newest Science articles');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['field' => 'date', 'direction' => 'desc'], $result['data']['sort_hint']);
        $this->assertEquals(['topic' => 'Science'], $result['data']['filter_hint']);
    }

    public function testBackwardCompatNoBothDescriptions(): void
    {
        // A handler with only sortableFields (no descriptions, no filterFields) must behave
        // identically to the old code — sort prompt appears, no filter prompt.
        $ai = new PromptCapturingAiService('{"terms": ["gem", "rock"]}');
        $handler = $this->makeHandler(aiService: $ai, sortableFields: ['price', 'date']);

        $handler->handleExpandQuery('most expensive stone');

        $this->assertStringContainsString('SORT INTENT', $ai->lastSystemPrompt);
        $this->assertStringNotContainsString('FILTER INTENT', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- price', $ai->lastSystemPrompt);
        $this->assertStringContainsString('- date', $ai->lastSystemPrompt);
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
        array $sortableFields = [],
        array $sortableFieldDescriptions = [],
        array $filterFields = [],
        array $filterFieldDescriptions = [],
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
            sortableFields: $sortableFields,
            sortableFieldDescriptions: $sortableFieldDescriptions,
            filterFields: $filterFields,
            filterFieldDescriptions: $filterFieldDescriptions,
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
        private readonly bool $throwApiKeyMissing = false,
        private readonly bool $throwApiKeyInvalid = false,
        private readonly bool $throwRateLimit = false,
        private readonly ?string $rateLimitRetryAfter = null,
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
        $this->throwIfConfigured();
        $this->callCount++;
        return $this->response;
    }

    public function messageForOperation(string $operation, string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        return $this->message($systemPrompt, $userMessage, $maxTokens);
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        if ($this->throwOnConversation) {
            throw new \RuntimeException('AI service unavailable');
        }
        $this->throwIfConfigured();
        $this->callCount++;
        return $this->response;
    }

    private function throwIfConfigured(): void
    {
        if ($this->throwApiKeyMissing) {
            throw new ApiKeyMissingException('Scolta AI API key not configured.');
        }
        if ($this->throwApiKeyInvalid) {
            throw new ApiKeyInvalidException('Scolta AI API key is invalid or expired.');
        }
        if ($this->throwRateLimit) {
            throw new RateLimitException('Scolta AI API rate limit reached.', $this->rateLimitRetryAfter);
        }
        if ($this->throwOnMessage) {
            throw new \RuntimeException('AI service unavailable');
        }
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
    ) {}

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

/**
 * PSR-3 logger spy that records error() calls.
 */
class SpyLogger extends \Psr\Log\AbstractLogger
{
    /** @var array<string> */
    public array $errors = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === \Psr\Log\LogLevel::ERROR) {
            $this->errors[] = (string) $message;
        }
    }
}
