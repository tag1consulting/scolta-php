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
