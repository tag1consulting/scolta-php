# Scolta Configuration Reference

All Scolta configuration flows through `Tag1\Scolta\Config\ScoltaConfig`. Platform adapters map their native config systems into this object. The `fromArray()` factory accepts snake_case keys and converts them to camelCase properties automatically.

## Configuration Properties

### AI Provider

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `aiProvider` | string | `'anthropic'` | AI provider identifier (`anthropic`, `openai`) |
| `aiApiKey` | string | `''` | API key for the AI provider |
| `aiModel` | string | `'claude-sonnet-4-5-20250929'` | Model identifier for summarize and follow-up |
| `aiExpansionModel` | string | `''` | Optional model for query expansion (empty = use `aiModel`) |
| `aiBaseUrl` | string | `''` | Custom API base URL (empty = provider default) |

### Site Identity

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `siteName` | string | `''` | Site name used in AI prompts |
| `siteDescription` | string | `'website'` | Site description used in AI prompts |
| `searchPagePath` | string | `'/search'` | Path to the search page |
| `pagefindIndexPath` | string | `'/pagefind'` | Path to the Pagefind index directory |

### Caching

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `cacheTtl` | int | `2592000` | Cache TTL in seconds (default: 30 days) |

### Rate Limiting

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `maxFollowUps` | int | `3` | Maximum follow-up questions per session |

### Scoring: Recency

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `recencyBoostMax` | float | `0.5` | Maximum positive boost for recent content |
| `recencyHalfLifeDays` | int | `365` | Half-life for recency decay (days) |
| `recencyPenaltyAfterDays` | int | `1825` | Age threshold before penalty applies (days, ~5 years) |
| `recencyMaxPenalty` | float | `0.3` | Maximum penalty for old content |

### Scoring: Title/Content Match

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `titleMatchBoost` | float | `1.0` | Boost for title keyword matches |
| `titleAllTermsMultiplier` | float | `1.5` | Multiplier when all search terms appear in title |
| `contentMatchBoost` | float | `0.4` | Boost for content/excerpt keyword matches |

### Scoring: Phrase Proximity

Applied when a query contains two or more terms and Pagefind word positions
(`locations`) are available. The content boost is multiplied by the phrase
factor before being added to the final score; the title boost is unaffected.

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `phraseAdjacentMultiplier` | float | `2.5` | Content-boost multiplier when all query terms appear adjacent (span ≤ terms−1 positions). Ensures "hello world" adjacent in body ranks above title hits. |
| `phraseNearMultiplier` | float | `1.5` | Content-boost multiplier when all terms are within `phraseNearWindow` word positions. |
| `phraseNearWindow` | int | `5` | Maximum word-position span for the near-phrase bonus. |
| `phraseWindow` | int | `15` | Maximum span considered "phrase-related" (no bonus beyond this). Reserved for future use. |

### Scoring: Expanded Terms

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `expandPrimaryWeight` | float | `0.5` | Weight applied to original query results during N-set merge. Lower values give AI-expanded terms more relative influence (better intent matching); higher values make literal keyword matches dominate. Set to 0.7+ if you want exact terms to dominate. |

### Scoring: Priority Pages

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `priorityPages` | array | `[]` | Pages that receive a score boost when their URL pattern or keywords match the query. Each entry: `{ url_pattern, keywords, boost }`. Matched pages are sorted to the top regardless of Pagefind rank. Processed by WASM `match_priority_pages()` when available. |

### Scoring: Language and Stop Words

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `language` | string | `'en'` | ISO 639-1 language code for stop word filtering (`en`, `de`, `fr`, …). 30 languages supported; CJK and unknown codes apply no stop word filtering. |
| `customStopWords` | array | `[]` | Additional stop words to filter beyond the language's built-in list. |

### Scoring: Recency Strategy

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `recencyStrategy` | string | `'exponential'` | Recency decay function: `exponential` (default), `linear`, `step`, `none`, or `custom` (piecewise-linear). |
| `recencyCurve` | array | `[]` | Control points for the `custom` strategy: `[[days, boost], …]` sorted by days ascending. |

### Display

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `excerptLength` | int | `300` | Maximum excerpt length in characters |
| `resultsPerPage` | int | `10` | Results shown per page |
| `maxPagefindResults` | int | `50` | Maximum results fetched from Pagefind |

### AI Features

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `aiExpandQuery` | bool | `true` | Enable AI query expansion |
| `aiSummarize` | bool | `true` | Enable AI result summarization |
| `aiSummaryTopN` | int | `10` | Number of top results sent to AI for summarization |
| `aiSummaryMaxChars` | int | `4000` | Maximum characters of content sent to AI for summarization |

### Multilingual

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `aiLanguages` | array | `['en']` | Supported languages for AI responses. When multiple languages are configured, AI prompts include an instruction to respond in the same language as the user's query if it matches a supported language, otherwise fall back to the primary (first) language. Single-language configs do not add any instruction. |

### Prompts

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `promptExpandQuery` | string | `''` | Custom prompt for query expansion (empty = use DefaultPrompts) |
| `promptSummarize` | string | `''` | Custom prompt for summarization (empty = use DefaultPrompts) |
| `promptFollowUp` | string | `''` | Custom prompt for follow-up conversations (empty = use DefaultPrompts) |

### Scoring Presets

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `preset` | string | `''` | Named scoring preset to apply before explicit values (empty = no preset). Preset values are overridden by any keys also present in the same `fromArray()` call. |

Each preset entry has three keys: `label` (human-readable name for UI dropdowns), `description` (one-paragraph explanation for admins), and `values` (scoring parameters). Adapter UIs read from `getPresets()` to build pickers without hardcoding any of these strings.

Available presets:

| Preset | Label | Purpose | Key `values` |
|--------|-------|---------|-------------|
| `none` | Start from Scratch | No preset; use defaults | _(empty)_ |
| `content_catalog` | Recipe & Content Catalog | Recipe/catalog sites where content quality matters more than freshness | `recencyStrategy: none`, `titleMatchBoost: 2.0`, `titleAllTermsMultiplier: 2.5`, `contentMatchBoost: 0.5`, `expandPrimaryWeight: 0.9`, `aiSummaryTopN: 15`, `maxPagefindResults: 75`, `resultsPerPage: 12` |
| `reference` | Documentation & Reference | Knowledge bases, documentation, encyclopedias, medical/compliance references | `recencyStrategy: none`, `titleMatchBoost: 2.0`, `titleAllTermsMultiplier: 2.5`, `contentMatchBoost: 0.5`, `expandPrimaryWeight: 0.6`, `aiSummaryTopN: 15`, `maxPagefindResults: 75`, `resultsPerPage: 12`, `excerptLength: 350` |
| `ecommerce` | E-commerce & Product Store | Product catalogs and stores with natural-language queries | `recencyStrategy: none`, `titleMatchBoost: 1.5`, `titleAllTermsMultiplier: 2.0`, `contentMatchBoost: 0.6`, `expandPrimaryWeight: 0.8`, `aiSummaryTopN: 12`, `maxPagefindResults: 75`, `resultsPerPage: 12`, `excerptLength: 300` |
| `blog` | Blog & Editorial | Narrative/editorial content with gentle temporal relevance | `recencyStrategy: exponential`, `recencyBoostMax: 0.1`, `recencyHalfLifeDays: 365`, `titleMatchBoost: 1.5`, `titleAllTermsMultiplier: 2.0`, `contentMatchBoost: 0.5`, `expandPrimaryWeight: 0.7`, `aiSummaryTopN: 12`, `maxPagefindResults: 60`, `resultsPerPage: 10`, `excerptLength: 350` |

### Choosing a Preset

| Site type | Preset |
|-----------|--------|
| Recipe sites, product/content catalogs | `content_catalog` |
| Documentation, knowledge bases, encyclopedias, medical/compliance references | `reference` |
| E-commerce / product stores | `ecommerce` |
| Blogs, editorial, narrative content | `blog` |
| News sites | No preset — use defaults with explicit recency tuning (`recencyHalfLifeDays`, `recencyPenaltyAfterDays`) |

## Platform Config Mapping

Each platform adapter maps its native config format to `ScoltaConfig::fromArray()`. The table below shows the snake_case key used in each platform's config system.

### AI Provider Keys

| ScoltaConfig Property | Drupal (`scolta.settings.yml`) | Laravel (`config/scolta.php`) | WordPress (`scolta_settings` option) |
|----------------------|-------------------------------|-------------------------------|--------------------------------------|
| `aiProvider` | `ai_provider` | `ai_provider` / `SCOLTA_AI_PROVIDER` | `ai_provider` |
| `aiApiKey` | `ai_api_key` | `ai_api_key` / `SCOLTA_API_KEY` | (env/constant only) |
| `aiModel` | `ai_model` | `ai_model` / `SCOLTA_AI_MODEL` | `ai_model` |
| `aiBaseUrl` | `ai_base_url` | `ai_base_url` / `SCOLTA_AI_BASE_URL` | `ai_base_url` |

### Site Identity Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `siteName` | `site_name` | `site_name` / `SCOLTA_SITE_NAME` | `site_name` |
| `siteDescription` | `site_description` | `site_description` | `site_description` |
| `searchPagePath` | `search_page_path` | `search_page_path` | `search_page_path` |
| `pagefindIndexPath` | `pagefind_index_path` | `pagefind.index_path` | `pagefind_index_path` |

### Caching & Rate Limiting Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `cacheTtl` | `cache_ttl` | `cache_ttl` / `SCOLTA_CACHE_TTL` | `cache_ttl` |
| `maxFollowUps` | `max_follow_ups` | `max_follow_ups` / `SCOLTA_MAX_FOLLOWUPS` | `max_follow_ups` |

### Scoring Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `recencyBoostMax` | `scoring.recency_boost_max` | `scoring.recency_boost_max` | `recency_boost_max` |
| `recencyHalfLifeDays` | `scoring.recency_half_life_days` | `scoring.recency_half_life_days` | `recency_half_life_days` |
| `recencyPenaltyAfterDays` | `scoring.recency_penalty_after_days` | `scoring.recency_penalty_after_days` | `recency_penalty_after_days` |
| `recencyMaxPenalty` | `scoring.recency_max_penalty` | `scoring.recency_max_penalty` | `recency_max_penalty` |
| `titleMatchBoost` | `scoring.title_match_boost` | `scoring.title_match_boost` | `title_match_boost` |
| `titleAllTermsMultiplier` | `scoring.title_all_terms_multiplier` | `scoring.title_all_terms_multiplier` | `title_all_terms_multiplier` |
| `contentMatchBoost` | `scoring.content_match_boost` | `scoring.content_match_boost` | `content_match_boost` |
| `phraseAdjacentMultiplier` | `scoring.phrase_adjacent_multiplier` | `scoring.phrase_adjacent_multiplier` | `phrase_adjacent_multiplier` |
| `phraseNearMultiplier` | `scoring.phrase_near_multiplier` | `scoring.phrase_near_multiplier` | `phrase_near_multiplier` |
| `phraseNearWindow` | `scoring.phrase_near_window` | `scoring.phrase_near_window` | `phrase_near_window` |
| `phraseWindow` | `scoring.phrase_window` | `scoring.phrase_window` | `phrase_window` |
| `expandPrimaryWeight` | `scoring.expand_primary_weight` | `scoring.expand_primary_weight` | `expand_primary_weight` |
| `language` | `scoring.language` | `scoring.language` / `SCOLTA_LANGUAGE` | `language` |
| `customStopWords` | `scoring.custom_stop_words` | `scoring.custom_stop_words` | `custom_stop_words` |
| `recencyStrategy` | `scoring.recency_strategy` | `scoring.recency_strategy` / `SCOLTA_RECENCY_STRATEGY` | `recency_strategy` |
| `recencyCurve` | `scoring.recency_curve` | `scoring.recency_curve` | `recency_curve` |

### Display Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `excerptLength` | `excerpt_length` | `display.excerpt_length` | `excerpt_length` |
| `resultsPerPage` | `results_per_page` | `display.results_per_page` | `results_per_page` |
| `maxPagefindResults` | `max_pagefind_results` | `display.max_pagefind_results` | `max_pagefind_results` |

### AI Feature Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `aiExpandQuery` | `ai_expand_query` | `ai_expand_query` / `SCOLTA_AI_EXPAND` | `ai_expand_query` |
| `aiSummarize` | `ai_summarize` | `ai_summarize` / `SCOLTA_AI_SUMMARIZE` | `ai_summarize` |
| `aiSummaryTopN` | `ai_summary_top_n` | `ai_summary_top_n` | `ai_summary_top_n` |
| `aiSummaryMaxChars` | `ai_summary_max_chars` | `ai_summary_max_chars` | `ai_summary_max_chars` |

### Multilingual Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `aiLanguages` | `ai_languages` | `ai_languages` / `SCOLTA_AI_LANGUAGES` | `ai_languages` |

### Prompt Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `promptExpandQuery` | `prompt_expand_query` | `prompts.expand_query` | `prompt_expand_query` |
| `promptSummarize` | `prompt_summarize` | `prompts.summarize` | `prompt_summarize` |
| `promptFollowUp` | `prompt_follow_up` | `prompts.follow_up` | `prompt_follow_up` |

## Methods

### `ScoltaConfig::fromArray(array $values): self`

Creates a config instance from an associative array. Keys are expected in snake_case and are automatically converted to camelCase property names. Unknown keys are silently ignored.

If a `preset` key is present, the named preset's values are applied first so that any other keys in the same call override them.

```php
// Use a preset with a site-specific override.
$config = ScoltaConfig::fromArray([
    'preset' => 'content_catalog',
    'results_per_page' => 20,  // overrides the preset's default of 12
]);

// Without a preset.
$config = ScoltaConfig::fromArray([
    'ai_provider' => 'anthropic',
    'title_match_boost' => 1.2,
    'results_per_page' => 20,
]);
```

### `ScoltaConfig::getPresets(): array`

Returns all available presets with their full metadata (label, description, values).

```php
$presets = ScoltaConfig::getPresets();
// [
//   'none' => ['label' => 'Start from Scratch', 'description' => '...', 'values' => []],
//   'content_catalog' => ['label' => 'Recipe & Content Catalog', 'description' => '...', 'values' => [...]],
//   ...
// ]
```

### `ScoltaConfig::getPresetValues(string $name): array`

Returns only the scoring `values` for a named preset. Returns `[]` for `'none'` or unknown names.

```php
$values = ScoltaConfig::getPresetValues('content_catalog');
// ['recency_strategy' => 'none', 'title_match_boost' => 2.0, ...]

ScoltaConfig::getPresetValues('none');  // []
```

### `ScoltaConfig::toJsScoringConfig(): array`

Exports scoring parameters as an array for the JavaScript frontend. Delegates to the WASM module for canonical transformation. The returned keys use SCREAMING_SNAKE_CASE matching the `window.scolta.scoring` object.

### `ScoltaConfig::toAiClientConfig(): array`

Returns an array suitable for constructing an `AiClient` instance, containing `provider`, `api_key`, `model`, and optionally `base_url`.

## SetupCheck

`Tag1\Scolta\SetupCheck::run()` performs pre-flight dependency checks. Platform adapters call this from their CLI check commands (e.g., `drush scolta:check-setup`, `php artisan scolta:check-setup`, `wp scolta check-setup`).

### Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `$configuredBinaryPath` | `?string` | Pagefind binary path from platform config |
| `$projectDir` | `?string` | Project root for binary resolution |
| `$aiApiKey` | `?string` | AI API key value (not source) |
| `$wasmPath` | `?string` | Custom WASM binary path, or null for default |

### Return Structure

Returns `array<array{name: string, status: string, message: string}>` where `status` is one of `pass`, `fail`, or `warn`.

### Checks Performed

| # | Check | Status on Failure | Description |
|---|-------|-------------------|-------------|
| 1 | PHP version | `fail` | Requires PHP 8.1+ |
| 2 | AI API key | `warn` | Checks if an API key is provided |
| 3 | Browser WASM | `warn` | Verifies `scolta_core_bg.wasm` and `scolta_core.js` exist in the assets directory |
| 4 | Pagefind binary | `warn` | Resolves Pagefind binary via `PagefindBinary`; falls back to PHP indexer if absent |

### Exit Code

`SetupCheck::exitCode(array $results): int` returns `0` if all checks pass or only have warnings, `1` if any check has `fail` status. Warnings (Pagefind, AI key) do not cause failure.
