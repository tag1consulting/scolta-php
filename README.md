# Scolta PHP

[![CI](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml)

Shared PHP library for the Scolta search engine. Platform adapters (Drupal, WordPress, Laravel) depend on this package for configuration, AI client, content export, search indexing, and shared frontend assets.

## What This Package Provides

- **ScoltaConfig** — Platform-agnostic configuration with scoring defaults
- **AiClient** — Provider-agnostic HTTP client for Anthropic and OpenAI APIs
- **AiEndpointHandler** — Shared AI endpoint logic (expand, summarize, follow-up)
- **ContentExporter** — Exports content items to Pagefind-compatible HTML files
- **PhpIndexer** — Pure PHP search indexer producing Pagefind-compatible index files
- **HtmlCleaner** — HTML cleaning for content extraction
- **DefaultPrompts** — Prompt templates with variable resolution
- **Shared assets** — `scolta.js` (search UI), `scolta.css` (styles), browser WASM (scoring)

## Installation

```bash
composer require tag1/scolta-php
```

### Requirements

- PHP 8.1+
- `ext-intl` (for Unicode tokenization)

## Architecture

```
Platform Adapters          scolta-php              scolta-core (browser WASM)
(Drupal/WP/Laravel)        (this package)
                                                   
  ContentGatherer ──────> ContentExporter ──────> HtmlCleaner
  CLI build cmd ────────> PhpIndexer             PagefindHtmlBuilder
  AiService ────────────> AiClient               
  SettingsForm ─────────> ScoltaConfig           Scoring runs in browser
  SearchPage ───────────> DefaultPrompts         via scolta.js + WASM
```

Search scoring runs entirely in the browser via the WASM module loaded by `scolta.js`. The PHP server handles content indexing, AI API proxying, and configuration.

## Two-Tier Indexing

Scolta supports two indexers that produce identical Pagefind-compatible output:

| Tier | Indexer | When to use |
|------|---------|-------------|
| 1 | **PHP** (`PhpIndexer`) | Managed hosting where `exec()` is disabled (WP Engine, Kinsta, Flywheel) |
| 2 | **Binary** (`PagefindBinary`) | Any host with shell access — faster for large sites |

Set `indexer: auto` (default) to auto-detect. The PHP indexer is chunk-aware: it processes pages in batches, persists state to disk, and finalizes when all batches complete.

## Language Support

The PHP indexer supports word stemming for 15 languages via Snowball algorithms:
Catalan, Danish, Dutch, English, Finnish, French, German, Italian, Norwegian,
Portuguese, Romanian, Russian, Spanish, Swedish.

For other languages (Arabic, Greek, Hindi, Hungarian, Turkish, etc.), the PHP
indexer indexes content without stemming — search works, but "running" won't
match "run." CJK languages (Chinese, Japanese, Korean) use character-level
tokenization and don't require stemming.

For full language parity with Pagefind's 33+ languages, use the binary indexer (Tier 2).

## Testing

```bash
composer install
./vendor/bin/phpunit
```

## Dependencies

- **guzzlehttp/guzzle** ^7.0 — HTTP client for AI API calls
- **wamania/php-stemmer** ^3.0 — Snowball stemming algorithms

## License

GPL-2.0-or-later
