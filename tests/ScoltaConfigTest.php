<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;

class ScoltaConfigTest extends TestCase
{
    // -------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------

    public function testDefaultValues(): void
    {
        $config = new ScoltaConfig();

        $this->assertEquals('anthropic', $config->aiProvider);
        $this->assertEquals('', $config->aiApiKey);
        $this->assertEquals('claude-sonnet-4-5-20250929', $config->aiModel);
        $this->assertEquals('', $config->aiBaseUrl);
        $this->assertEquals('', $config->siteName);
        $this->assertEquals('website', $config->siteDescription);
        $this->assertEquals('/search', $config->searchPagePath);
        $this->assertEquals('/pagefind', $config->pagefindIndexPath);
        $this->assertEquals(2592000, $config->cacheTtl);
        $this->assertEquals(3, $config->maxFollowUps);
        $this->assertEquals(0.5, $config->recencyBoostMax);
        $this->assertEquals(365, $config->recencyHalfLifeDays);
        $this->assertEquals(1825, $config->recencyPenaltyAfterDays);
        $this->assertEquals(0.3, $config->recencyMaxPenalty);
        $this->assertEquals(1.0, $config->titleMatchBoost);
        $this->assertEquals(1.5, $config->titleAllTermsMultiplier);
        $this->assertEquals(0.4, $config->contentMatchBoost);
        $this->assertEquals(0.5, $config->expandPrimaryWeight);
        $this->assertEquals(300, $config->excerptLength);
        $this->assertEquals(10, $config->resultsPerPage);
        $this->assertEquals(50, $config->maxPagefindResults);
        $this->assertTrue($config->aiExpandQuery);
        $this->assertTrue($config->aiSummarize);
        $this->assertEquals(10, $config->aiSummaryTopN);
        $this->assertEquals(4000, $config->aiSummaryMaxChars);
        $this->assertEquals('', $config->promptExpandQuery);
        $this->assertEquals('', $config->promptSummarize);
        $this->assertEquals('', $config->promptFollowUp);
        $this->assertEquals(['en'], $config->aiLanguages);
    }

    // -------------------------------------------------------------------
    // fromArray — snake_case to camelCase mapping
    // -------------------------------------------------------------------

    public function testFromArrayMapsSnakeCaseToCamelCase(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_provider' => 'openai',
            'ai_api_key' => 'sk-test-key',
            'ai_model' => 'gpt-4',
            'site_name' => 'Test Site',
            'site_description' => 'test description',
            'cache_ttl' => 3600,
            'max_follow_ups' => 5,
        ]);

        $this->assertEquals('openai', $config->aiProvider);
        $this->assertEquals('sk-test-key', $config->aiApiKey);
        $this->assertEquals('gpt-4', $config->aiModel);
        $this->assertEquals('Test Site', $config->siteName);
        $this->assertEquals('test description', $config->siteDescription);
        $this->assertEquals(3600, $config->cacheTtl);
        $this->assertEquals(5, $config->maxFollowUps);
    }

    public function testFromArrayMapsScoringParams(): void
    {
        $config = ScoltaConfig::fromArray([
            'title_match_boost' => 2.5,
            'title_all_terms_multiplier' => 3.0,
            'content_match_boost' => 0.8,
            'recency_boost_max' => 1.0,
            'recency_half_life_days' => 180,
            'recency_penalty_after_days' => 730,
            'recency_max_penalty' => 0.5,
            'expand_primary_weight' => 0.6,
        ]);

        $this->assertEquals(2.5, $config->titleMatchBoost);
        $this->assertEquals(3.0, $config->titleAllTermsMultiplier);
        $this->assertEquals(0.8, $config->contentMatchBoost);
        $this->assertEquals(1.0, $config->recencyBoostMax);
        $this->assertEquals(180, $config->recencyHalfLifeDays);
        $this->assertEquals(730, $config->recencyPenaltyAfterDays);
        $this->assertEquals(0.5, $config->recencyMaxPenalty);
        $this->assertEquals(0.6, $config->expandPrimaryWeight);
    }

    public function testFromArrayMapsDisplayParams(): void
    {
        $config = ScoltaConfig::fromArray([
            'excerpt_length' => 500,
            'results_per_page' => 20,
            'max_pagefind_results' => 100,
            'ai_summary_top_n' => 3,
            'ai_summary_max_chars' => 1000,
        ]);

        $this->assertEquals(500, $config->excerptLength);
        $this->assertEquals(20, $config->resultsPerPage);
        $this->assertEquals(100, $config->maxPagefindResults);
        $this->assertEquals(3, $config->aiSummaryTopN);
        $this->assertEquals(1000, $config->aiSummaryMaxChars);
    }

    public function testFromArrayMapsFeatureToggles(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_expand_query' => false,
            'ai_summarize' => false,
        ]);

        $this->assertFalse($config->aiExpandQuery);
        $this->assertFalse($config->aiSummarize);
    }

    public function testFromArrayMapsPromptOverrides(): void
    {
        $config = ScoltaConfig::fromArray([
            'prompt_expand_query' => 'Custom expand for {SITE_NAME}',
            'prompt_summarize' => 'Custom summarize',
            'prompt_follow_up' => 'Custom follow-up',
        ]);

        $this->assertEquals('Custom expand for {SITE_NAME}', $config->promptExpandQuery);
        $this->assertEquals('Custom summarize', $config->promptSummarize);
        $this->assertEquals('Custom follow-up', $config->promptFollowUp);
    }

    public function testFromArrayMapsAiLanguages(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_languages' => ['en', 'es', 'fr'],
        ]);

        $this->assertEquals(['en', 'es', 'fr'], $config->aiLanguages);
    }

    public function testFromArrayIgnoresUnknownKeys(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Test',
            'unknown_key' => 'ignored',
            'another_unknown' => 42,
        ]);

        $this->assertEquals('Test', $config->siteName);
        // Should not throw, unknown keys are silently ignored.
    }

    public function testFromArrayEmptyArray(): void
    {
        $config = ScoltaConfig::fromArray([]);
        // All defaults should apply.
        $this->assertEquals('anthropic', $config->aiProvider);
        $this->assertEquals(365, $config->recencyHalfLifeDays);
    }

    public function testIndexerDefaultsToAuto(): void
    {
        $config = ScoltaConfig::fromArray([]);
        $this->assertSame('auto', $config->indexer);
    }

    public function testFromArrayMapsIndexer(): void
    {
        $config = ScoltaConfig::fromArray(['indexer' => 'binary']);
        $this->assertSame('binary', $config->indexer);
    }

    // -------------------------------------------------------------------
    // toAiClientConfig
    // -------------------------------------------------------------------

    public function testToAiClientConfigAnthropicMinimal(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_provider' => 'anthropic',
            'ai_api_key' => 'sk-ant-key',
            'ai_model' => 'claude-sonnet-4-5-20250929',
        ]);

        $clientConfig = $config->toAiClientConfig();

        $this->assertEquals('anthropic', $clientConfig['provider']);
        $this->assertEquals('sk-ant-key', $clientConfig['api_key']);
        $this->assertEquals('claude-sonnet-4-5-20250929', $clientConfig['model']);
        $this->assertArrayNotHasKey('base_url', $clientConfig);
    }

    public function testToAiClientConfigWithBaseUrl(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_provider' => 'openai',
            'ai_api_key' => 'sk-openai',
            'ai_model' => 'gpt-4',
            'ai_base_url' => 'https://proxy.example.com/v1',
        ]);

        $clientConfig = $config->toAiClientConfig();

        $this->assertEquals('openai', $clientConfig['provider']);
        $this->assertEquals('https://proxy.example.com/v1', $clientConfig['base_url']);
    }

    public function testToAiClientConfigEmptyBaseUrlOmitted(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_base_url' => '',
        ]);

        $this->assertArrayNotHasKey('base_url', $config->toAiClientConfig());
    }

    // -------------------------------------------------------------------
    // 0.2.2 new fields: language, customStopWords, recencyStrategy, recencyCurve
    // -------------------------------------------------------------------

    public function testNewFieldsHaveDefaults(): void
    {
        $config = new ScoltaConfig();

        $this->assertEquals('en', $config->language);
        $this->assertEquals([], $config->customStopWords);
        $this->assertEquals('exponential', $config->recencyStrategy);
        $this->assertEquals([], $config->recencyCurve);
    }

    public function testFromArrayConvertsNewFields(): void
    {
        $config = ScoltaConfig::fromArray([
            'language' => 'de',
            'custom_stop_words' => ['foo', 'bar'],
            'recency_strategy' => 'linear',
            'recency_curve' => [[0, 1.0], [365, 0.5]],
        ]);

        $this->assertEquals('de', $config->language);
        $this->assertEquals(['foo', 'bar'], $config->customStopWords);
        $this->assertEquals('linear', $config->recencyStrategy);
        $this->assertEquals([[0, 1.0], [365, 0.5]], $config->recencyCurve);
    }

    public function testToJsScoringConfigIncludesNewFields(): void
    {
        $config = ScoltaConfig::fromArray([
            'language' => 'fr',
            'custom_stop_words' => ['les'],
            'recency_strategy' => 'step',
            'recency_curve' => [[30, 1.0], [365, 0.0]],
        ]);

        $js = $config->toJsScoringConfig();

        $this->assertEquals('fr', $js['LANGUAGE']);
        $this->assertEquals(['les'], $js['CUSTOM_STOP_WORDS']);
        $this->assertEquals('step', $js['RECENCY_STRATEGY']);
        $this->assertEquals([[30, 1.0], [365, 0.0]], $js['RECENCY_CURVE']);
    }

    // -------------------------------------------------------------------
    // toJsScoringConfig — completeness and correctness
    // -------------------------------------------------------------------

    public function testToJsScoringConfigContainsAllExpectedKeys(): void
    {
        $js = (new ScoltaConfig())->toJsScoringConfig();

        $expected = [
            'RECENCY_BOOST_MAX', 'RECENCY_HALF_LIFE_DAYS', 'RECENCY_PENALTY_AFTER_DAYS',
            'RECENCY_MAX_PENALTY', 'TITLE_MATCH_BOOST', 'TITLE_ALL_TERMS_MULTIPLIER',
            'CONTENT_MATCH_BOOST', 'PHRASE_ADJACENT_MULTIPLIER', 'PHRASE_NEAR_MULTIPLIER',
            'PHRASE_NEAR_WINDOW', 'PHRASE_WINDOW', 'EXCERPT_LENGTH', 'RESULTS_PER_PAGE',
            'MAX_PAGEFIND_RESULTS', 'AI_EXPAND_QUERY', 'AI_SUMMARIZE', 'AI_SUMMARY_TOP_N',
            'AI_SUMMARY_MAX_CHARS', 'EXPAND_PRIMARY_WEIGHT', 'AI_MAX_FOLLOWUPS',
            'AI_LANGUAGES', 'LANGUAGE', 'CUSTOM_STOP_WORDS', 'RECENCY_STRATEGY', 'RECENCY_CURVE',
        ];

        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $js, "Missing key: {$key}");
        }

        $this->assertCount(25, $js, 'Expected exactly 25 keys in toJsScoringConfig()');
    }

    public function testToJsScoringConfigValuesMatchConfig(): void
    {
        $config = ScoltaConfig::fromArray([
            'title_match_boost' => 2.0,
            'content_match_boost' => 0.8,
            'excerpt_length' => 500,
            'results_per_page' => 20,
            'max_pagefind_results' => 100,
            'ai_expand_query' => false,
            'ai_summarize' => false,
            'ai_summary_top_n' => 3,
            'ai_summary_max_chars' => 1500,
            'max_follow_ups' => 5,
            'expand_primary_weight' => 0.6,
        ]);

        $js = $config->toJsScoringConfig();

        $this->assertEquals(2.0, $js['TITLE_MATCH_BOOST']);
        $this->assertEquals(0.8, $js['CONTENT_MATCH_BOOST']);
        $this->assertEquals(500, $js['EXCERPT_LENGTH']);
        $this->assertEquals(20, $js['RESULTS_PER_PAGE']);
        $this->assertEquals(100, $js['MAX_PAGEFIND_RESULTS']);
        $this->assertFalse($js['AI_EXPAND_QUERY']);
        $this->assertFalse($js['AI_SUMMARIZE']);
        $this->assertEquals(3, $js['AI_SUMMARY_TOP_N']);
        $this->assertEquals(1500, $js['AI_SUMMARY_MAX_CHARS']);
        $this->assertEquals(5, $js['AI_MAX_FOLLOWUPS']);
        $this->assertEquals(0.6, $js['EXPAND_PRIMARY_WEIGHT']);
    }

    public function testToJsScoringConfigPhraseProximityFields(): void
    {
        $config = ScoltaConfig::fromArray([
            'phrase_adjacent_multiplier' => 3.0,
            'phrase_near_multiplier' => 2.0,
            'phrase_near_window' => 8,
            'phrase_window' => 20,
        ]);

        $js = $config->toJsScoringConfig();

        $this->assertEquals(3.0, $js['PHRASE_ADJACENT_MULTIPLIER']);
        $this->assertEquals(2.0, $js['PHRASE_NEAR_MULTIPLIER']);
        $this->assertEquals(8, $js['PHRASE_NEAR_WINDOW']);
        $this->assertEquals(20, $js['PHRASE_WINDOW']);
    }

    // -------------------------------------------------------------------
    // Preset system
    // -------------------------------------------------------------------

    public function testDefaultPresetIsEmpty(): void
    {
        $config = new ScoltaConfig();
        $this->assertSame('', $config->preset);
    }

    public function testGetPresetsReturnsAllPresets(): void
    {
        $presets = ScoltaConfig::getPresets();
        $this->assertIsArray($presets);
        $this->assertArrayHasKey('content_catalog', $presets);
    }

    public function testContentCatalogPresetSetsExpectedDefaults(): void
    {
        $config = ScoltaConfig::fromArray(['preset' => 'content_catalog']);

        $this->assertSame('content_catalog', $config->preset);
        $this->assertSame('none', $config->recencyStrategy);
        $this->assertEquals(2.0, $config->titleMatchBoost);
        $this->assertEquals(2.5, $config->titleAllTermsMultiplier);
        $this->assertEquals(0.5, $config->contentMatchBoost);
        $this->assertEquals(15, $config->aiSummaryTopN);
        $this->assertEquals(75, $config->maxPagefindResults);
        $this->assertEquals(12, $config->resultsPerPage);
    }

    public function testExplicitValuesOverridePreset(): void
    {
        $config = ScoltaConfig::fromArray([
            'preset' => 'content_catalog',
            'title_match_boost' => 3.0,
            'results_per_page' => 20,
        ]);

        // Explicit values win over preset.
        $this->assertEquals(3.0, $config->titleMatchBoost);
        $this->assertEquals(20, $config->resultsPerPage);
        // Preset values not explicitly overridden remain.
        $this->assertSame('none', $config->recencyStrategy);
        $this->assertEquals(2.5, $config->titleAllTermsMultiplier);
    }

    public function testUnknownPresetIsIgnoredGracefully(): void
    {
        $config = ScoltaConfig::fromArray(['preset' => 'nonexistent_preset']);
        // Unknown preset — property stays empty, defaults apply.
        $this->assertSame('', $config->preset);
        $this->assertEquals(1.0, $config->titleMatchBoost);
    }

    public function testFromArrayWithoutPresetPreservesOldBehavior(): void
    {
        $config = ScoltaConfig::fromArray(['title_match_boost' => 1.8]);
        $this->assertSame('', $config->preset);
        $this->assertEquals(1.8, $config->titleMatchBoost);
        // Other defaults unchanged.
        $this->assertSame('exponential', $config->recencyStrategy);
    }

    public function testToJsScoringConfigSensitiveKeysAbsent(): void
    {
        $js = (new ScoltaConfig())->toJsScoringConfig();

        $serverSideOnly = [
            'cacheTtl', 'cache_ttl',
            'aiApiKey', 'ai_api_key', 'API_KEY',
            'aiBaseUrl', 'ai_base_url', 'BASE_URL',
            'aiProvider', 'ai_provider',
            'aiModel', 'ai_model',
            'siteName', 'site_name',
            'siteDescription', 'site_description',
            'promptExpandQuery', 'prompt_expand_query',
            'promptSummarize', 'prompt_summarize',
            'promptFollowUp', 'prompt_follow_up',
        ];

        foreach ($serverSideOnly as $key) {
            $this->assertArrayNotHasKey($key, $js, "Server-side key should not be in JS output: {$key}");
        }
    }

    // -------------------------------------------------------------------
    // AI configuration — flags and languages in JS output
    // -------------------------------------------------------------------

    public function testAiLanguagesFlagsPropagateToJsOutput(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_languages' => ['en', 'fr', 'de'],
            'ai_expand_query' => false,
            'ai_summarize' => false,
            'max_follow_ups' => 0,
        ]);

        $js = $config->toJsScoringConfig();

        $this->assertEquals(['en', 'fr', 'de'], $js['AI_LANGUAGES']);
        $this->assertFalse($js['AI_EXPAND_QUERY']);
        $this->assertFalse($js['AI_SUMMARIZE']);
        $this->assertEquals(0, $js['AI_MAX_FOLLOWUPS']);
    }
}
