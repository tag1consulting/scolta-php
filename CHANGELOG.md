# Changelog

All notable changes to scolta-php will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased]

## [0.2.0] - 2026-04-13

### Fixed

- **Bug:** HTML tags (e.g. `<b>Title</b>`) and HTML entities (e.g. `&amp;`) in CMS-supplied titles were passed directly to the tokenizer, polluting the index with tag tokens. `InvertedIndexBuilder` now strips tags and decodes entities before tokenization and metadata storage.
- **Bug:** Fragment, index chunk, and filter file hashes were 7 hex chars (28 bits), risking collisions at scale. All hashes are now uniformly 10 chars (40 bits), matching the pre-existing metadata hash.
- **Bug:** `BuildState::initiateBuild()` had a TOCTOU race — two concurrent processes could both acquire the lock. Replaced with `flock(LOCK_EX | LOCK_NB)` for atomic OS-level mutual exclusion; the handle is held open until `releaseLock()`.
- **Bug:** `PhpIndexer::computeFingerprint()` had no indexer-type marker, so switching binary→PHP left the fingerprint unchanged and `shouldBuild()` returned `null`. Fingerprint now includes a `php-indexer-v1:` prefix.
- **Improvement:** `HealthChecker::check()` now includes `indexer_active`, `indexer_upgrade_available`, and `indexer_upgrade_message` fields to surface binary-upgrade guidance in platform admin UIs.

### Changed

- **BREAKING:** Scoring, merging, and expansion parsing now run in the browser via WASM — no server-side WASM at runtime
- **BREAKING:** Removed `ScoltaWasm`, `ExtismCheck`, and `DefaultScorer` — all server-side WASM/Extism/FFI dependencies eliminated
- **BREAKING:** `SetupCheck::run()` no longer accepts `$wasmPath` parameter; only checks PHP version, AI key, Browser WASM, and Pagefind binary
- HTML cleaning and Pagefind HTML generation ported to pure PHP (`HtmlCleaner`, `PagefindHtmlBuilder`)
- `ContentExporter` now uses `HtmlCleaner` and `PagefindHtmlBuilder` directly — no Extism/FFI required
- `ScoltaConfig::toJsScoringConfig()` is now pure PHP — no WASM call at runtime
- `DefaultPrompts` templates are now PHP constants — no WASM call for prompt resolution
- `SetupCheck` simplified: status values are 'pass', 'fail', 'warn' only (removed 'info'); no FFI/Extism/server-WASM checks

### Removed

- `src/Wasm/ScoltaWasm.php` — replaced by `HtmlCleaner` and `PagefindHtmlBuilder`
- `src/ExtismCheck.php` — Extism runtime no longer needed
- `src/Scorer/DefaultScorer.php` — scoring moved to browser WASM
- `wasm/scolta_core.wasm` — server-side WASM binary no longer shipped
- Extism/FFI requirements from `composer.json` and CI pipeline

### Added

- `HtmlCleaner` — pure PHP HTML cleaning (ported from Rust `scolta-core/src/html.rs`)
- `PagefindHtmlBuilder` — pure PHP Pagefind HTML document builder (ported from Rust)
- Browser WASM assets (`assets/wasm/scolta_core_bg.wasm`, `scolta_core.js`) for client-side scoring
- `ScoltaConfig::toBrowserConfig()` for rendering client-side configuration
- `composer update-browser-wasm` script
- `MarkdownRenderer` utility class (`Tag1\Scolta\Util\MarkdownRenderer`) for converting AI markdown responses to XSS-safe HTML (bold, links, bullet lists, paragraphs)
- `AiEndpointHandler::handleSummarize()` and `handleFollowUp()` now render AI markdown responses to HTML via `MarkdownRenderer` before returning results; all three platform adapters benefit automatically
- `aiLanguages` property on `ScoltaConfig` for multilingual AI response support (default: `['en']`)
- `AiEndpointHandler` accepts optional `aiLanguages` array; when multiple languages are configured, appends a language instruction to AI prompts so responses match the user's query language
- `toJsScoringConfig()` now includes `ai_languages` in the exported JS config
- `PromptEnricherInterface` and `NullEnricher` for site-specific prompt context injection between WASM resolution and LLM calls
- `AiEndpointHandler` now accepts an optional `PromptEnricherInterface` parameter (defaults to `NullEnricher`)
- `docs/ENRICHMENT.md` documenting the enrichment API with platform-specific examples

### Previously added

- `ScoltaWasm` bridge to all scolta-core WASM functions via Extism PHP SDK and FFI
- `ScoltaConfig` platform-agnostic configuration with `fromArray()`, `toJsScoringConfig()`, and `toAiClientConfig()` methods
- `AiClient` provider-agnostic HTTP client supporting Anthropic and OpenAI APIs with single-turn and multi-turn conversation modes
- `ContentExporter` for exporting content items to Pagefind-compatible HTML files
- `ContentSourceInterface` contract for platform adapters to implement content enumeration
- `DefaultPrompts` prompt template loading and variable resolution via WASM
- `PagefindBinary` cross-platform binary resolver with download support
- `SetupCheck` pre-flight dependency checker (PHP version, FFI, Extism, WASM, Pagefind, AI key)
- `ExtismCheck` runtime validation for the Extism PHP SDK and shared library
- `HealthChecker` health check aggregation for monitoring endpoints
- `AiEndpointHandler` shared request validation and response formatting for AI API endpoints
- `AiServiceAdapter` AI service wrapper used by platform adapters
- `CacheDriverInterface` contract for platform-specific cache implementations
- Shared frontend assets (`scolta.js`, `scolta.css`) used by all platform adapters
- Pre-built `scolta_core.wasm` binary shipped in the package
