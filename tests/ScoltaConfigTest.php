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
        $this->assertEquals(0.7, $config->expandPrimaryWeight);
        $this->assertEquals(300, $config->excerptLength);
        $this->assertEquals(10, $config->resultsPerPage);
        $this->assertEquals(50, $config->maxPagefindResults);
        $this->assertTrue($config->aiExpandQuery);
        $this->assertTrue($config->aiSummarize);
        $this->assertEquals(5, $config->aiSummaryTopN);
        $this->assertEquals(2000, $config->aiSummaryMaxChars);
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
}
