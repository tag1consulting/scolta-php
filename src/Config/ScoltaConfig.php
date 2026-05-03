<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

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
    public string $aiExpansionModel = '';
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

    // -- Scoring: Phrase proximity --
    public float $phraseAdjacentMultiplier = 2.5;
    public float $phraseNearMultiplier = 1.5;
    public int $phraseNearWindow = 5;
    public int $phraseWindow = 15;

    // -- Scoring: Expanded terms --
    public float $expandPrimaryWeight = 0.5;

    // -- Scoring: Language and stop words --
    public string $language = 'en';
    /** @var string[] */
    public array $customStopWords = [];

    // -- Scoring: Recency strategy --
    /** @var string 'exponential' | 'linear' | 'step' | 'none' | 'custom' */
    public string $recencyStrategy = 'exponential';
    /**
     * Control points for 'custom' recency strategy: [[days, boost], ...].
     * @var array<array{0: float, 1: float}>
     */
    public array $recencyCurve = [];

    // -- Display --
    public int $excerptLength = 300;
    public int $resultsPerPage = 10;
    public int $maxPagefindResults = 50;

    // -- AI feature toggles --
    public bool $aiExpandQuery = true;
    public bool $aiSummarize = true;
    public int $aiSummaryTopN = 10;
    public int $aiSummaryMaxChars = 4000;

    // -- Multilingual --
    public array $aiLanguages = ['en'];

    // -- Prompt overrides (empty = use DefaultPrompts) --
    public string $promptExpandQuery = '';
    public string $promptSummarize = '';
    public string $promptFollowUp = '';

    // -- Indexer --
    /** @var string 'auto' | 'php' | 'binary' */
    public string $indexer = 'auto';

    // -- Scoring preset --
    /** @var string Named preset to apply before explicit overrides (empty = no preset). */
    public string $preset = '';

    /**
     * Named scoring presets with labels and descriptions for adapter UIs.
     *
     * Each entry has:
     *   'label'       — human-readable name for dropdowns
     *   'description' — one-paragraph explanation shown to site admins
     *   'values'      — snake_case scoring parameters passed to fromArray()
     *
     * Applied by fromArray() before explicit values so site-level overrides
     * always win.
     *
     * @var array<string, array{label: string, description: string, values: array<string, mixed>}>
     */
    public const PRESETS = [
        'none' => [
            'label' => 'Start from Scratch',
            'description' => 'No preset applied. All scoring parameters use Scolta defaults. This is your starting point for fully custom configuration — select this as your starting point — or leave it as-is. You can optionally adjust any individual setting below.',
            'values' => [],
        ],
        'content_catalog' => [
            'label' => 'Recipe & Content Catalog',
            'description' => 'Best for recipe sites, wikis, and content collections with structured titles. Strongly prioritizes title matches — a recipe called "Chocolate Brownies" ranks high for that search — and shows more results per page for browsing. Newer and older content rank equally since catalog items stay relevant over time. Select this as your starting point — or leave it as-is. You can optionally adjust any individual setting below.',
            'values' => [
                'recency_strategy' => 'none',
                'title_match_boost' => 2.0,
                'title_all_terms_multiplier' => 2.5,
                'content_match_boost' => 0.5,
                'expand_primary_weight' => 0.9,
                'ai_summary_top_n' => 15,
                'max_pagefind_results' => 75,
                'results_per_page' => 12,
            ],
        ],
        'reference' => [
            'label' => 'Documentation & Reference',
            'description' => 'Best for knowledge bases, documentation, encyclopedias, and compliance references. Strongly favors exact title matches and understands domain synonyms (e.g., searching "GDPR" also finds "data protection regulation"). Newer and older content rank equally since reference material stays relevant over time. Select this as your starting point — or leave it as-is. You can optionally adjust any individual setting below.',
            'values' => [
                'recency_strategy' => 'none',
                'title_match_boost' => 2.0,
                'title_all_terms_multiplier' => 2.5,
                'content_match_boost' => 0.5,
                'expand_primary_weight' => 0.6,
                'ai_summary_top_n' => 15,
                'max_pagefind_results' => 75,
                'results_per_page' => 12,
                'excerpt_length' => 350,
            ],
        ],
        'ecommerce' => [
            'label' => 'E-commerce & Product Store',
            'description' => 'Best for online stores and product catalogs. People shop in their own words, not yours — so this preset reads product descriptions closely and interprets searches broadly. A search for "sparkly blue gift" finds lapis lazuli, not just items with those exact words. Newer and older products rank equally. Select this as your starting point — or leave it as-is. You can optionally adjust any individual setting below.',
            'values' => [
                'recency_strategy' => 'none',
                'title_match_boost' => 1.5,
                'title_all_terms_multiplier' => 2.0,
                'content_match_boost' => 0.6,
                'expand_primary_weight' => 0.8,
                'ai_summary_top_n' => 12,
                'max_pagefind_results' => 75,
                'results_per_page' => 12,
                'excerpt_length' => 300,
            ],
        ],
        'blog' => [
            'label' => 'Blog & Editorial',
            'description' => 'Best for blogs, news sites, and editorial content. Gives a gentle boost to newer posts while keeping older content findable, and interprets searches broadly so readers searching by topic or feeling ("scary moment", "funny story") get good results. Select this as your starting point — or leave it as-is. You can optionally adjust any individual setting below.',
            'values' => [
                'recency_strategy' => 'exponential',
                'recency_boost_max' => 0.1,
                'recency_half_life_days' => 365,
                'title_match_boost' => 1.5,
                'title_all_terms_multiplier' => 2.0,
                'content_match_boost' => 0.5,
                'expand_primary_weight' => 0.7,
                'ai_summary_top_n' => 12,
                'max_pagefind_results' => 60,
                'results_per_page' => 10,
                'excerpt_length' => 350,
            ],
        ],
    ];

    /**
     * Create from an associative array (e.g., from Drupal config, wp_options, or Laravel config).
     *
     * If a `preset` key is present, the named preset's values are applied first.
     * Any other keys in `$values` override the preset.
     */
    public static function fromArray(array $values): self
    {
        $config = new self();

        // Apply preset defaults before explicit values so overrides always win.
        if (!empty($values['preset']) && isset(self::PRESETS[$values['preset']])) {
            $config->preset = $values['preset'];
            // Support both new nested structure (values key) and legacy flat array.
            $presetData = self::PRESETS[$values['preset']];
            $presetValues = $presetData['values'] ?? $presetData;
            foreach ($presetValues as $key => $value) {
                $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
                if (property_exists($config, $property)) {
                    $config->$property = $value;
                }
            }
        }

        foreach ($values as $key => $value) {
            if ($key === 'preset') {
                continue;
            }
            // Convert snake_case keys to camelCase property names.
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($config, $property)) {
                $config->$property = $value;
            }
        }

        return $config;
    }

    /**
     * Return all available presets with their labels, descriptions, and values.
     *
     * Adapter UIs read from this to build dropdowns and help text.
     * The 'none' entry represents "no preset — use defaults."
     *
     * @return array<string, array{label: string, description: string, values: array<string, mixed>}>
     */
    public static function getPresets(): array
    {
        return self::PRESETS;
    }

    /**
     * Return only the scoring values for the named preset.
     *
     * Returns an empty array for 'none' or unknown preset names.
     *
     * @param string $name Preset key (e.g., 'content_catalog').
     * @return array<string, mixed> Snake_case scoring parameters.
     */
    public static function getPresetValues(string $name): array
    {
        return self::PRESETS[$name]['values'] ?? [];
    }

    /**
     * Export scoring parameters as an array matching the JS CONFIG object.
     *
     * Used to populate window.scolta.scoring in the search page.
     * Pure PHP — no WASM dependency at runtime.
     */
    public function toJsScoringConfig(): array
    {
        return [
            'RECENCY_BOOST_MAX' => $this->recencyBoostMax,
            'RECENCY_HALF_LIFE_DAYS' => $this->recencyHalfLifeDays,
            'RECENCY_PENALTY_AFTER_DAYS' => $this->recencyPenaltyAfterDays,
            'RECENCY_MAX_PENALTY' => $this->recencyMaxPenalty,
            'TITLE_MATCH_BOOST' => $this->titleMatchBoost,
            'TITLE_ALL_TERMS_MULTIPLIER' => $this->titleAllTermsMultiplier,
            'CONTENT_MATCH_BOOST' => $this->contentMatchBoost,
            'PHRASE_ADJACENT_MULTIPLIER' => $this->phraseAdjacentMultiplier,
            'PHRASE_NEAR_MULTIPLIER' => $this->phraseNearMultiplier,
            'PHRASE_NEAR_WINDOW' => $this->phraseNearWindow,
            'PHRASE_WINDOW' => $this->phraseWindow,
            'EXCERPT_LENGTH' => $this->excerptLength,
            'RESULTS_PER_PAGE' => $this->resultsPerPage,
            'MAX_PAGEFIND_RESULTS' => $this->maxPagefindResults,
            'AI_EXPAND_QUERY' => $this->aiExpandQuery,
            'AI_SUMMARIZE' => $this->aiSummarize,
            'AI_SUMMARY_TOP_N' => $this->aiSummaryTopN,
            'AI_SUMMARY_MAX_CHARS' => $this->aiSummaryMaxChars,
            'EXPAND_PRIMARY_WEIGHT' => $this->expandPrimaryWeight,
            'AI_MAX_FOLLOWUPS' => $this->maxFollowUps,
            'AI_LANGUAGES' => $this->aiLanguages,
            'LANGUAGE' => $this->language,
            'CUSTOM_STOP_WORDS' => $this->customStopWords,
            'RECENCY_STRATEGY' => $this->recencyStrategy,
            'RECENCY_CURVE' => $this->recencyCurve,
        ];
    }

    /**
     * Export browser-side configuration for rendering window.scolta.
     *
     * Platform adapters use this to generate the client-side config.
     * They fill in the platform-specific paths (wasmPath, pagefindPath, endpoints).
     */
    public function toBrowserConfig(): array
    {
        return [
            'scoring' => $this->toJsScoringConfig(),
            'endpoints' => [
                'expand' => '/api/scolta/v1/expand-query',
                'summarize' => '/api/scolta/v1/summarize',
                'followup' => '/api/scolta/v1/followup',
            ],
            'wasmPath' => '',
            'siteName' => $this->siteName,
            'pagefindPath' => $this->pagefindIndexPath . '/pagefind.js',
        ];
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
