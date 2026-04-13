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

## Indexer

Scolta supports two indexers that produce identical Pagefind-compatible output:

| Feature | PHP Indexer | Pagefind Binary |
| ------- | ----------- | --------------- |
| Languages with stemming | 15 (Snowball) | 33+ |
| Speed (1 000 pages) | ~3–4 seconds | ~0.3–0.5 seconds |
| Dependencies | None (pure PHP) | Node.js or direct binary |
| Shared / managed hosting | Yes | Only if binary installable |
| Heading / anchor search | Not yet | Yes |
| Custom sort fields | Not yet | Yes |

Set `indexer: auto` (default) to auto-detect. With `auto`, Scolta uses the binary when available and silently falls back to PHP.

### When to use the PHP indexer

- WP Engine, Kinsta, Flywheel, Pantheon and other managed hosts that disable `exec()`
- Any environment where installing a Node.js binary is not possible
- Smaller sites (under ~5 000 pages) where the speed difference is negligible

### Upgrading to the binary indexer

Install Pagefind globally (Node.js ≥ 18 required):

```bash
npm install -g pagefind
```

Or install the binary directly (no Node.js required):

```bash
# Scolta ships a download command on every platform:
wp scolta download-pagefind          # WordPress
drush scolta:download-pagefind       # Drupal
php artisan scolta:download-pagefind # Laravel
```

Verify the upgrade:

```bash
wp scolta check-setup          # WordPress
drush scolta:check-setup       # Drupal
php artisan scolta:check-setup # Laravel
```

The health endpoint also reports `indexer_active` (`"binary"` or `"php"`) and `indexer_upgrade_available`.

### Language support

The PHP indexer supports word stemming for 15 languages via Snowball algorithms:
Catalan, Danish, Dutch, English, Finnish, French, German, Italian, Norwegian,
Portuguese, Romanian, Russian, Spanish, Swedish.

For other languages (Arabic, Greek, Hindi, Hungarian, Turkish, etc.), the PHP
indexer indexes without stemming — search works, but "running" won't match "run."
CJK languages (Chinese, Japanese, Korean) use character-level tokenization and
don't require stemming.

For full language parity with Pagefind's 33+ languages, use the binary indexer.

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
