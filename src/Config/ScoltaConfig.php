<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

use Tag1\Scolta\Wasm\ScoltaWasm;

/**
 * Platform-agnostic configuration for Scolta.
 *
 * Platform adapters (Drupal, WordPress, Laravel) map their native
 * config systems into this object. The JS frontend reads scoring
 * parameters from the same structure via window.scolta.scoring.
 *
 * Scoring defaults preserve the original algorithm exactly — recency
 * decay, title/content boosting, expanded-term weight decay, Jaccard
 * deduplication, and priority page boosting.
 */
class ScoltaConfig
{
    // -- AI provider --
    public string $aiProvider = 'anthropic';
    public string $aiApiKey = '';
    public string $aiModel = 'claude-sonnet-4-5-20250929';
    public string $aiBaseUrl = '';

    // -- Site identity --
    public string $siteName = '';
    public string $siteDescription = 'website';
    public string $searchPagePath = '/search';
    public string $pagefindIndexPath = '/pagefind';

    // -- Caching --
    public int $cacheTtl = 2592000; // 30 days in seconds

    // -- Rate limiting --
    public int $maxFollowUps = 3;

    // -- Scoring: Recency --
    public float $recencyBoostMax = 0.5;
    public int $recencyHalfLifeDays = 365;
    public int $recencyPenaltyAfterDays = 1825;
    public float $recencyMaxPenalty = 0.3;

    // -- Scoring: Title/Content match --
    public float $titleMatchBoost = 1.0;
    public float $titleAllTermsMultiplier = 1.5;
    public float $contentMatchBoost = 0.4;

    // -- Scoring: Expanded terms --
    public float $expandPrimaryWeight = 0.7;

    // -- Display --
    public int $excerptLength = 300;
    public int $resultsPerPage = 10;
    public int $maxPagefindResults = 50;

    // -- AI feature toggles --
    public bool $aiExpandQuery = true;
    public bool $aiSummarize = true;
    public int $aiSummaryTopN = 5;
    public int $aiSummaryMaxChars = 2000;

    // -- Multilingual --
    public array $aiLanguages = ['en'];

    // -- Prompt overrides (empty = use DefaultPrompts) --
    public string $promptExpandQuery = '';
    public string $promptSummarize = '';
    public string $promptFollowUp = '';

    /**
     * Create from an associative array (e.g., from Drupal config, wp_options, or Laravel config).
     */
    public static function fromArray(array $values): self
    {
        $config = new self();

        foreach ($values as $key => $value) {
            // Convert snake_case keys to camelCase property names.
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($config, $property)) {
                $config->$property = $value;
            }
        }

        return $config;
    }

    /**
     * Export scoring parameters as an array matching the JS CONFIG object.
     *
     * Used to populate window.scolta.scoring in the search page.
     * Delegates to WASM module for canonical transformation.
     */
    public function toJsScoringConfig(): array
    {
        return ScoltaWasm::toJsScoringConfig([
            'recency_boost_max' => $this->recencyBoostMax,
            'recency_half_life_days' => $this->recencyHalfLifeDays,
            'recency_penalty_after_days' => $this->recencyPenaltyAfterDays,
            'recency_max_penalty' => $this->recencyMaxPenalty,
            'title_match_boost' => $this->titleMatchBoost,
            'title_all_terms_multiplier' => $this->titleAllTermsMultiplier,
            'content_match_boost' => $this->contentMatchBoost,
            'excerpt_length' => $this->excerptLength,
            'results_per_page' => $this->resultsPerPage,
            'max_pagefind_results' => $this->maxPagefindResults,
            'ai_expand_query' => $this->aiExpandQuery,
            'ai_summarize' => $this->aiSummarize,
            'ai_summary_top_n' => $this->aiSummaryTopN,
            'ai_summary_max_chars' => $this->aiSummaryMaxChars,
            'expand_primary_weight' => $this->expandPrimaryWeight,
            'ai_max_followups' => $this->maxFollowUps,
            'ai_languages' => $this->aiLanguages,
        ]);
    }

    /**
     * Get the AI client config array for constructing an AiClient.
     */
    public function toAiClientConfig(): array
    {
        $config = [
            'provider' => $this->aiProvider,
            'api_key' => $this->aiApiKey,
            'model' => $this->aiModel,
        ];

        if (!empty($this->aiBaseUrl)) {
            $config['base_url'] = $this->aiBaseUrl;
        }

        return $config;
    }
}
