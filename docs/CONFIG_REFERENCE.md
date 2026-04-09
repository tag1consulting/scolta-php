# Scolta Configuration Reference

All Scolta configuration flows through `Tag1\Scolta\Config\ScoltaConfig`. Platform adapters map their native config systems into this object. The `fromArray()` factory accepts snake_case keys and converts them to camelCase properties automatically.

## Configuration Properties

### AI Provider

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `aiProvider` | string | `'anthropic'` | AI provider identifier (`anthropic`, `openai`) |
| `aiApiKey` | string | `''` | API key for the AI provider |
| `aiModel` | string | `'claude-sonnet-4-5-20250929'` | Model identifier |
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

### Scoring: Expanded Terms

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `expandPrimaryWeight` | float | `0.7` | Weight given to original query results vs expanded results during merge |

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
| `aiSummaryTopN` | int | `5` | Number of top results sent to AI for summarization |
| `aiSummaryMaxChars` | int | `2000` | Maximum characters of content sent to AI for summarization |

### Prompts

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `promptExpandQuery` | string | `''` | Custom prompt for query expansion (empty = use DefaultPrompts) |
| `promptSummarize` | string | `''` | Custom prompt for summarization (empty = use DefaultPrompts) |
| `promptFollowUp` | string | `''` | Custom prompt for follow-up conversations (empty = use DefaultPrompts) |

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
| `expandPrimaryWeight` | `scoring.expand_primary_weight` | `scoring.expand_primary_weight` | `expand_primary_weight` |

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

### Prompt Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `promptExpandQuery` | `prompt_expand_query` | `prompts.expand_query` | `prompt_expand_query` |
| `promptSummarize` | `prompt_summarize` | `prompts.summarize` | `prompt_summarize` |
| `promptFollowUp` | `prompt_follow_up` | `prompts.follow_up` | `prompt_follow_up` |

## Methods

### `ScoltaConfig::fromArray(array $values): self`

Creates a config instance from an associative array. Keys are expected in snake_case and are automatically converted to camelCase property names. Unknown keys are silently ignored.

```php
$config = ScoltaConfig::fromArray([
    'ai_provider' => 'anthropic',
    'title_match_boost' => 1.2,
    'results_per_page' => 20,
]);
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
| 2 | FFI extension | `fail` | Requires `ext-ffi` loaded and `ffi.enable` set to `true` or `preload` |
| 3 | Extism shared library | `fail` | Looks for `libextism.so` / `libextism.dylib` in standard paths |
| 4 | Extism PHP SDK | `fail` | Checks that `\Extism\Plugin` class exists |
| 5 | WASM binary | `fail` | Verifies `scolta_core.wasm` exists at expected path |
| 5b | WASM load test | `fail` | Loads the WASM module and calls `version()` (only runs if checks 2, 4, 5 pass) |
| 6 | Pagefind binary | `warn` | Resolves Pagefind binary via `PagefindBinary` |
| 7 | AI API key | `warn` | Checks if an API key is provided |

### Exit Code

`SetupCheck::exitCode(array $results): int` returns `0` if all checks pass or only have warnings, `1` if any check has `fail` status. Warnings (Pagefind, AI key) do not cause failure.
