# Scolta PHP

[![CI](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml)

Scolta is a browser-side search engine: the index lives in static files, scoring runs in the browser via WebAssembly, and an optional AI layer handles query expansion and summarization. No search server required. "Scolta" is archaic Italian for sentinel — someone watching for what matters.

This package is the shared PHP library. Platform adapters (WordPress, Drupal, Laravel) depend on it for content export, AI client, indexing, configuration, and shared frontend assets.

## Quick Install

```bash
composer require tag1/scolta-php
```

**Requirements:** PHP 8.1+, `ext-intl` (Unicode tokenization).

Platform adapters install this package automatically — you only need to install it directly if you are building a custom adapter.

## Verify It Works

```bash
composer install
./vendor/bin/phpunit
```

All tests pass without any native runtime. The WASM module ships as a pre-built binary in `assets/wasm/`.

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

The PHP indexer works everywhere but is slower than the Pagefind binary. To upgrade:

```bash
# Install via npm (Node.js ≥ 18 required):
npm install -g pagefind

# Or download the binary directly (no Node.js required):
wp scolta download-pagefind          # WordPress
drush scolta:download-pagefind       # Drupal
php artisan scolta:download-pagefind # Laravel
```

Then verify:

```bash
wp scolta check-setup          # WordPress
drush scolta:check-setup       # Drupal
php artisan scolta:check-setup # Laravel
```

The health endpoint reports `indexer_active` (`"binary"` or `"php"`) and `indexer_upgrade_available`.

### Indexer comparison

| Feature | PHP Indexer | Pagefind Binary |
| ------- | ----------- | --------------- |
| Languages with stemming | 14 (Snowball) | 33+ |
| Speed (1 000 pages) | ~3–4 seconds | ~0.3–0.5 seconds |
| Dependencies | None (pure PHP) | Node.js or direct binary |
| Shared / managed hosting | Yes | Only if binary installable |
| Heading / anchor search | Not yet | Yes |
| Custom sort fields | Not yet | Yes |

`indexer: auto` (default) uses the binary when available and falls back to PHP automatically.

**When to stay on PHP indexer:**

- WP Engine, Kinsta, Flywheel, Pantheon, and other managed hosts that disable `exec()`
- Any environment where installing a Node.js binary is not possible
- Sites under ~5 000 pages where the speed difference is negligible

### Language support

The PHP indexer supports word stemming for 14 languages via Snowball algorithms: Catalan, Danish, Dutch, English, Finnish, French, German, Italian, Norwegian, Portuguese, Romanian, Russian, Spanish, Swedish.

For other languages (Arabic, Greek, Hindi, Hungarian, Turkish, etc.), search works but "running" will not match "run." CJK languages (Chinese, Japanese, Korean) use character-level tokenization and do not require stemming. For full language parity with Pagefind's 33+ languages, use the binary indexer.

## Debugging

### "ext-intl not found"

The PHP `intl` extension is required for Unicode tokenization. Install it:

```bash
# Debian/Ubuntu
sudo apt-get install php8.1-intl

# macOS (Homebrew)
brew install php && brew install --build-from-source php-intl
```

Verify: `php -m | grep intl`

### "PhpIndexer produces empty output"

Check that `ext-intl` is loaded and that the content items passed to the indexer have non-empty `bodyHtml`. The indexer skips items with no extractable text after HTML cleaning.

### "AI calls failing"

1. Confirm the API key is set: check `SCOLTA_API_KEY` env var or the platform-specific constant.
2. Check the model identifier — model names change with provider updates. Default: `claude-sonnet-4-5-20250929`.
3. Enable Guzzle request logging: set `SCOLTA_DEBUG=1` to log raw request/response bodies.

### Scoring results look wrong

The browser-side WASM scorer (`scolta-core`) runs client-side via wasm-bindgen. If results appear unscored or identically ranked, confirm the `pagefind.js` and `scolta.wasm` assets are both loading without 404 errors. The WASM binary is a static file served from your platform's public directory — check your web server's static file headers.

## Configuration Reference

All Scolta configuration flows through `Tag1\Scolta\Config\ScoltaConfig`. Platform adapters map their native config systems into this object via `ScoltaConfig::fromArray()`, which accepts snake_case keys.

### AI Provider

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `aiProvider` | `ai_provider` | string | `anthropic` | AI provider (`anthropic` or `openai`) |
| `aiApiKey` | `ai_api_key` | string | `''` | API key for the AI provider |
| `aiModel` | `ai_model` | string | `claude-sonnet-4-5-20250929` | Model identifier |
| `aiBaseUrl` | `ai_base_url` | string | `''` | Custom API base URL (empty = provider default) |
| `aiExpandQuery` | `ai_expand_query` | bool | `true` | Enable AI query expansion |
| `aiSummarize` | `ai_summarize` | bool | `true` | Enable AI result summarization |
| `aiSummaryTopN` | `ai_summary_top_n` | int | `5` | Number of top results sent to AI for summarization |
| `aiSummaryMaxChars` | `ai_summary_max_chars` | int | `2000` | Maximum characters of content sent to AI for summarization |
| `aiLanguages` | `ai_languages` | array | `['en']` | Supported languages for AI responses. With multiple languages, the AI responds in the user's query language if it matches; otherwise falls back to the primary (first) language. |

### Scoring: Recency

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `recencyStrategy` | `recency_strategy` | string | `exponential` | Decay function: `exponential`, `linear`, `step`, `none`, or `custom` (piecewise-linear) |
| `recencyCurve` | `recency_curve` | array | `[]` | Control points for `custom` strategy: `[[days, boost], …]` sorted ascending |
| `recencyBoostMax` | `recency_boost_max` | float | `0.5` | Maximum positive boost for recent content |
| `recencyHalfLifeDays` | `recency_half_life_days` | int | `365` | Half-life for recency decay (days) |
| `recencyPenaltyAfterDays` | `recency_penalty_after_days` | int | `1825` | Age threshold before penalty applies (~5 years) |
| `recencyMaxPenalty` | `recency_max_penalty` | float | `0.3` | Maximum penalty for old content |

### Scoring: Title/Content Match

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `titleMatchBoost` | `title_match_boost` | float | `1.0` | Boost for title keyword matches |
| `titleAllTermsMultiplier` | `title_all_terms_multiplier` | float | `1.5` | Multiplier when all search terms appear in title |
| `contentMatchBoost` | `content_match_boost` | float | `0.4` | Boost for content/excerpt keyword matches |
| `expandPrimaryWeight` | `expand_primary_weight` | float | `0.7` | Weight given to original query results vs expanded results during merge |

### Scoring: Language

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `language` | `language` | string | `en` | ISO 639-1 language code for stop word filtering. 30 languages supported; unknown codes apply no stop word filtering. |
| `customStopWords` | `custom_stop_words` | array | `[]` | Additional stop words beyond the language's built-in list |

### Display

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `excerptLength` | `excerpt_length` | int | `300` | Maximum excerpt length in characters |
| `resultsPerPage` | `results_per_page` | int | `10` | Results shown per page |
| `maxPagefindResults` | `max_pagefind_results` | int | `50` | Maximum results fetched from Pagefind |

### Site Identity

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `siteName` | `site_name` | string | `''` | Site name used in AI prompts |
| `siteDescription` | `site_description` | string | `website` | Site description used in AI prompts |
| `searchPagePath` | `search_page_path` | string | `/search` | Path to the search page |
| `pagefindIndexPath` | `pagefind_index_path` | string | `/pagefind` | URL path to the Pagefind index directory |

### Caching

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `cacheTtl` | `cache_ttl` | int | `2592000` | Cache TTL in seconds (default: 30 days) |
| `maxFollowUps` | `max_follow_ups` | int | `3` | Maximum follow-up questions per session |

### Prompts

| Property | snake_case key | Type | Default | Description |
| -------- | -------------- | ---- | ------- | ----------- |
| `promptExpandQuery` | `prompt_expand_query` | string | `''` | Custom prompt for query expansion (empty = use DefaultPrompts) |
| `promptSummarize` | `prompt_summarize` | string | `''` | Custom prompt for summarization (empty = use DefaultPrompts) |
| `promptFollowUp` | `prompt_follow_up` | string | `''` | Custom prompt for follow-up conversations (empty = use DefaultPrompts) |

For per-platform key mapping (e.g., Drupal `scoring.recency_boost_max` vs. WordPress `recency_boost_max` vs. Laravel `scoring.recency_boost_max`), see [docs/CONFIG_REFERENCE.md](docs/CONFIG_REFERENCE.md).

## Architecture

```text
Platform Adapters             scolta-php (this package)    scolta-core (browser WASM)
(Drupal / WP / Laravel)

  ContentGatherer ─────────> ContentExporter ──────────> HtmlCleaner
  CLI build command ────────> PhpIndexer                  PagefindHtmlBuilder
  AiService ───────────────> AiClient
  SettingsForm ────────────> ScoltaConfig
  SearchPage ──────────────> DefaultPrompts               Scoring runs in browser
  CacheDriver ─────────────> CacheDriverInterface         via scolta.js + WASM
```

**What lives here:**

- `ScoltaConfig` — platform-agnostic configuration with scoring defaults
- `AiClient` — provider-agnostic HTTP client for Anthropic and OpenAI APIs
- `AiEndpointHandler` — shared expand / summarize / follow-up logic
- `ContentExporter` — exports content items to Pagefind-compatible HTML files
- `PhpIndexer` — pure PHP indexer producing Pagefind-compatible index files
- `HtmlCleaner` — HTML cleaning for content extraction
- `DefaultPrompts` — prompt templates with variable resolution (pure PHP, no WASM)
- `PagefindBinary` — binary resolver and downloader
- Shared assets — `scolta.js`, `scolta.css`, browser WASM

Scoring runs entirely in the browser via the WASM module loaded by `scolta.js`. The PHP server handles content indexing, AI API proxying, and configuration only.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

## License

GPL-2.0-or-later
