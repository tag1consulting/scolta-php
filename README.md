# Scolta PHP

[![CI](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml)

PHP library that indexes content into Pagefind-compatible search indexes, plus the shared orchestration, memory-budget management, and AI client used by Scolta's CMS adapters.

## Status

Beta. Scolta is installable and in active use. The API surface documented here will not break within the 0.x minor series without a deprecation notice and a replacement in place. Expect breaking changes before 1.0. Test in staging before deploying to production. File bugs at the repo issue tracker.

## What Is Scolta?

Scolta is a scoring, ranking, and AI layer built on [Pagefind](https://pagefind.app/). Pagefind is the search engine: it builds a static inverted index at publish time, runs a browser-side WASM search engine, produces word-position data, and generates highlighted excerpts. Scolta takes Pagefind's result set and re-ranks it with configurable boosts — title match weight, content match weight, recency decay curves, and phrase-proximity multipliers. No search server required. Queries resolve in the visitor's browser against the pre-built static index.

This package is the PHP foundation for all three CMS adapters. It handles the parts that are the same regardless of platform: indexing content to Pagefind-compatible HTML files, AI provider communication, configuration management, memory budgeting, and the shared browser assets (`scolta.js`, `scolta.css`, and the pre-built WASM module). The CMS adapters (scolta-drupal, scolta-laravel, scolta-wp) depend on this package and add only their platform-specific concerns.

The LLM tier — query expansion, result summarization, follow-up questions — is optional. When enabled, it sends the query text and selected result excerpts to a configured LLM provider. The base search tier shares nothing with any third party; it runs entirely in the visitor's browser.

## Running Example

The examples in this README and the other Scolta repos use a recipe catalog as the concrete data set. Recipes are a good showcase because recipe vocabulary has cross-dialect mismatches that basic keyword search handles poorly:

- A search for `aubergine parmesan` should surface *Eggplant Parmigiana*.
- A search for `chinese noodle soup` should surface *Lanzhou Beef Noodles*, *Wonton Soup*, and *Dan Dan Noodles*.
- A search for `gluten free pasta` should surface *Zucchini Spaghetti with Pesto* and *Rice Noodle Stir-Fry*.
- A search for `quick dinner under 30 min` should surface *Pad Kra Pao*, *Dan Dan Noodles*, *Steak Frites*, and others.

The recipe fixture lives at `tests/fixtures/recipes/` — 20 HTML files in Pagefind-compatible format, one per recipe.

Here is how to index the recipe catalog outside any CMS, using the `IndexBuildOrchestrator` directly:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Config\MemoryBudget;

// Load the 20 recipe HTML files from the fixture directory
$fixtures = glob(__DIR__ . '/tests/fixtures/recipes/*.html');
$items = [];
foreach ($fixtures as $file) {
    $dom = new DOMDocument();
    @$dom->loadHTMLFile($file);
    $body = $dom->getElementById(basename($file, '.html'));
    $id   = pathinfo($file, PATHINFO_FILENAME);
    $title = $dom->getElementsByTagName('title')[0]->textContent;

    $items[] = new ContentItem(
        id:       $id,
        title:    $title,
        bodyHtml: $dom->saveHTML($body),
        url:      '/recipes/' . $id,
        date:     '2024-03-01',
        siteName: 'Recipe Catalog',
    );
}

// Run the build using the conservative memory profile (128 MB-safe)
$orchestrator = new IndexBuildOrchestrator(
    stateDir:  '/tmp/scolta-state',
    outputDir: '/var/www/html/pagefind',
    language:  'en',
);

$result = $orchestrator->build(
    intent: BuildIntent::fresh(count($items), MemoryBudget::conservative()),
    pages:  $items,
);

printf("Indexed %d recipes in %.1fs\n", $result->pageCount, $result->elapsedSeconds);
// Indexed 20 recipes in 0.3s
```

After indexing, the `/var/www/html/pagefind/` directory contains a Pagefind-compatible static index. Point a browser at it and load `scolta.js` to get a working search UI with vocabulary-mismatch handling.

## Installation

```bash
composer require tag1/scolta-php
```

**Requirements:** PHP 8.1+, `ext-intl` (Unicode tokenization).

Platform adapters install this package automatically. Install it directly only when building a custom adapter or a non-CMS integration.

## Configuration and Quickstart

All Scolta configuration flows through `Tag1\Scolta\Config\ScoltaConfig`. Construct it with `ScoltaConfig::fromArray()`:

```php
use Tag1\Scolta\Config\ScoltaConfig;

$config = ScoltaConfig::fromArray([
    // AI provider (optional — omit for base search only)
    'ai_provider'         => 'anthropic',
    'ai_api_key'          => getenv('SCOLTA_API_KEY'),
    'ai_model'            => 'claude-sonnet-4-5-20250929',
    'ai_expand_query'     => true,
    'ai_summarize'        => true,

    // Scoring — tuned for a recipe catalog (no recency, title precision)
    'scoring' => [
        'title_match_boost'          => 1.5,
        'title_all_terms_multiplier' => 2.0,
        'content_match_boost'        => 0.4,
        'recency_strategy'           => 'none',
        'language'                   => 'en',
    ],

    // Site identity (used in AI prompts)
    'site_name'        => 'Recipe Catalog',
    'site_description' => 'a collection of 20 international recipes',
]);
```

For the full list of config keys and their defaults, see [docs/CONFIG_REFERENCE.md](docs/CONFIG_REFERENCE.md).

## What Scolta Replaces (and What It Doesn't)

Scolta is a practical replacement for hosted search SaaS (Algolia, Coveo, SearchStax) and for small-to-medium self-hosted search installations used as a content search backend — Solr or Elasticsearch where your use case is page and document search, not log analytics.

Scolta is not a replacement for:

- Full-text database search with row-level access control (per-document permissions enforced at query time).
- Log analytics or observability search built on Elasticsearch or OpenSearch.
- Vector databases used as a general retrieval layer for RAG pipelines.
- Enterprise search with audit logging, retention policies, or SSO-gated document visibility.

If you need any of those, Scolta is the wrong tool.

## Memory and Scale

The default memory profile is `conservative`, which targets a peak RSS under 96 MB and works on shared hosting with a 128 MB `memory_limit`. Scolta never silently upgrades to a larger profile. To opt in to a larger profile:

```php
use Tag1\Scolta\Config\MemoryBudget;
use Tag1\Scolta\Config\MemoryBudgetSuggestion;

// Auto-detect and suggest a profile based on the current PHP memory_limit
$suggestion = MemoryBudgetSuggestion::suggest();
// $suggestion->profile is 'conservative', 'balanced', or 'aggressive'
// $suggestion->warning is non-empty if the limit is tight

// Or specify directly
$budget = MemoryBudget::balanced();   // targets ~200 MB peak RSS
$budget = MemoryBudget::aggressive(); // targets ~384 MB peak RSS

// Or pass a budget in bytes
$budget = MemoryBudget::fromBytes(256 * 1024 * 1024);
```

The trade-off: a larger budget means fewer, larger index chunks and faster builds. The `conservative` profile is always the default and always safe to use.

Tested ceiling at the `conservative` profile: 50,000 pages. Higher counts likely work; not certified yet.

You can also pass the profile string at the CLI via `--memory-budget=balanced` if the CMS adapter supports the flag.

## AI Features and Privacy

Scolta's AI tier is optional. When enabled:

- The LLM receives: the query text, and the titles and excerpts of the top N results (default: 5, configurable via `ai_summary_top_n`).
- The LLM does not receive: the full index contents, full page text, user session data, or visitor identity.
- Which provider receives the query data depends on your `ai_provider` setting: `anthropic`, `openai`, or a self-hosted endpoint via `ai_base_url`.

The base search tier — Pagefind index lookup and Scolta WASM scoring — runs entirely in the visitor's browser with no server-side involvement beyond serving the static index files.

## Optional Upgrades

### Indexer options

Both indexers produce the same Pagefind-compatible index. The search experience is identical either way. Choose based on your hosting constraints.

**PHP indexer** (the default): runs everywhere, no binary required. Around 3–4 seconds per 1,000 pages. Supports 14 languages via Snowball stemming (Catalan, Danish, Dutch, English, Finnish, French, German, Italian, Norwegian, Portuguese, Romanian, Russian, Spanish, Swedish).

**Pagefind binary indexer**: 5–10× faster. Requires Node.js ≥ 18 or a direct binary download. Supports 33+ languages. Better for large sites or environments where the binary is installable.

On managed hosting (WP Engine, Kinsta, Flywheel, Pantheon), `exec()` is disabled. The PHP indexer runs there automatically with no configuration change.

To install the binary:

```bash
# Download via the CLI command (no Node.js required):
wp scolta download-pagefind          # WordPress
drush scolta:download-pagefind       # Drupal
php artisan scolta:download-pagefind # Laravel

# Or install via npm (Node.js ≥ 18 required):
npm install -g pagefind
```

`indexer: auto` (the default) uses the binary when available and falls back to PHP automatically.

### Language support for the PHP indexer

For languages outside the 14 supported by Snowball, search works but inflected forms ("running", "ran") will not match a stemmed base ("run"). CJK languages (Chinese, Japanese, Korean) use character-level tokenization and do not require stemming. For full 33+ language stemming coverage, use the Pagefind binary indexer.

## Debugging

### "ext-intl not found"

```bash
# Debian/Ubuntu
sudo apt-get install php8.1-intl

# macOS (Homebrew)
brew install php
```

Verify: `php -m | grep intl`

### "PhpIndexer produces empty output"

Verify `ext-intl` is loaded and that the `ContentItem` objects passed to the indexer have non-empty `bodyHtml`. The indexer skips items where the cleaned text is shorter than 50 characters.

### "AI calls failing"

1. Confirm the API key: check `SCOLTA_API_KEY` env var or the platform-specific constant.
2. Check the model identifier — model names change with provider releases. Default: `claude-sonnet-4-5-20250929`.
3. Enable request logging: set `SCOLTA_DEBUG=1` to log raw request/response bodies via Guzzle.

### "Scoring results look wrong"

The browser-side WASM scorer (`scolta-core`) runs via wasm-bindgen. If results appear unscored or identically ranked, confirm both `pagefind.js` and `scolta_core_bg.wasm` are loading without 404 errors in the browser console.

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
  CLI build command ────────> IndexBuildOrchestrator       PagefindHtmlBuilder
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
- `IndexBuildOrchestrator` — single authoritative chunk-loop entry point for all adapters
- `MemoryBudget` / `MemoryBudgetSuggestion` — memory profile management
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

## Credits

Scolta is built on [Pagefind](https://pagefind.app/) by [CloudCannon](https://cloudcannon.com/). Without Pagefind, Scolta has no search to score — the index format, WASM search engine, word-position data, and excerpt generation are all Pagefind's. Scolta's contribution is the layer that sits on top: configurable scoring, multi-adapter ranking parity, AI features, and platform glue.

## License

MIT

## Related Packages

- [scolta-core](https://github.com/tag1consulting/scolta-core) — Rust/WASM scoring, ranking, and AI layer that runs in the browser.
- [scolta-drupal](https://github.com/tag1consulting/scolta-drupal) — Drupal 10/11 Search API backend with Drush commands, admin settings form, and a search block.
- [scolta-laravel](https://github.com/tag1consulting/scolta-laravel) — Laravel 11/12 package with Artisan commands, a `Searchable` trait for Eloquent models, and a Blade search component.
- [scolta-wp](https://github.com/tag1consulting/scolta-wp) — WordPress 6.x plugin with WP-CLI commands, Settings API page, and a `[scolta_search]` shortcode.
