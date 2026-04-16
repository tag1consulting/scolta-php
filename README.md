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

### "WASM interface version mismatch"

If `ScoltaWasm` throws a `RuntimeException` about interface version, the pre-built WASM binary in `assets/wasm/` does not match the version this PHP package expects. This should not happen with a normal `composer install` — it indicates the WASM binary was replaced manually or a partial upgrade occurred. Run `composer install --no-cache` to restore the correct binary.

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
