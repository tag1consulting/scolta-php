# Changelog

All notable changes to scolta-php will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [0.2.4] - Unreleased

### Added
- **Phrase-proximity scoring** (requires updated WASM from scolta-core 0.2.4): `scoreResults()` now passes `data.locations` (Pagefind word positions) to the WASM scorer. Adjacent phrase matches (terms appearing consecutive in the document) receive a ×2.5 content-boost multiplier; near-phrase (within 5 words) receives ×1.5. Fixes exact-phrase results ranking below title-only hits.
- **Quoted-phrase forced mode**: Queries wrapped in double-quotes (e.g. `"hello world"`) activate `forced_phrase` in the Rust scorer and suppress OR fallback — the user explicitly asked for phrase results, not individual-term broadening.
- **WASM config key fix**: `scoreResults()` now converts SCREAMING_SNAKE_CASE config keys to `snake_case` before sending to WASM. Previously all scoring config from the admin UI was silently ignored by the scorer (it always used Rust defaults). Admin-configured values for `title_match_boost`, `content_match_boost`, recency strategy, etc. now apply correctly.
- **`ScoltaConfig` phrase fields**: `phraseAdjacentMultiplier` (2.5), `phraseNearMultiplier` (1.5), `phraseNearWindow` (5), `phraseWindow` (15) with corresponding `PHRASE_*` keys in `toJsScoringConfig()`.

### Fixed
- **Stale WASM (second rebuild):** the 0.2.4 WASM artifact was rebuilt a second time on 2026-04-21 after scolta-core commit `ae71e9f` added phrase-proximity scoring. The earlier 0.2.4-dev WASM (from 2026-04-19) predated that commit, so the `SearchResult.locations` field was absent and JS-passed location arrays were silently dropped into `SearchResult.extra`. Anyone running a checkout between 2026-04-19 and the rebuild would have seen no phrase-proximity ranking despite the JS layer sending `locations`. Binary size after rebuild: 1,194,999 bytes (up from 1,186,125 bytes pre-phrase).
- **Stale WASM binary**: `assets/wasm/scolta_core_bg.wasm` was built from scolta-core `0.2.2-dev` and had never been updated since. The scolta-core 0.2.3 release added `batch_extract_context`, `sanitize_query`, `match_priority_pages`, and the N-set `merge_results` format; all of those WASM calls were silently falling back to their JS implementations in every 0.2.3 install. Rebuilt from scolta-core 0.2.4-dev (binary size: 1.1 MB, up from 256 KB; increase reflects the new algorithms).
- **`merge_results` TypeScript declaration**: `scolta_core.d.ts` now documents the N-set input shape (`{ sets, deduplicate_by, normalize_urls }`) that the implementation has used since 0.2.3. The old `{ original, expanded, config }` shape comment was stale.
- **`mergeResults` behavioral tests**: Added five behavioral Jest tests in `behavioral.test.js` that actually invoke `mergeResults` at runtime (JS fallback path) and assert deduplication and score-wins semantics. The previous test was a string-match only.
- **`HealthChecker` `index_exists`**: Now checks `{outputDir}/pagefind/pagefind.js` first (the location both `PhpIndexer` and the Pagefind binary pipeline write to since 0.2.3), falling back to the legacy flat `{outputDir}/pagefind.js`. Previously `index_exists` always returned `false` for fresh PHP-indexer builds and `true` only for sites retaining a stale pre-0.2.3 flat file.

## [0.2.3] - 2026-04-17

### Fixed
- Filter sidebar hidden on single-site installs (was showing useless single checkbox)
- **PHP indexer**: `PagefindFormatWriter` now copies `pagefind-worker.js`, `wasm.en.pagefind`, and `wasm.unknown.pagefind` into the built index directory alongside `pagefind.js`. Previously the browser runtime assets were missing when using the PHP indexer, causing search to hang at "Searching…".
- **PHP indexer**: `InvertedIndexBuilder` now prepends the page title to the fragment `content` field, matching what `PagefindHtmlBuilder` produces for the binary path. Previously, title words were absent from the content excerpt, so `content_match_score` never fired for title-only matches.
- **PHP indexer**: `InvertedIndexBuilder` now indexes title tokens into body positions (`locs`) as well as title meta positions (`meta_locs`). Pagefind's WASM requires at least one body position to generate a highlighted excerpt for a match.
- **PHP indexer**: Title sanitization now strips `<script>` and `<style>` block content (not just tags) before the title is stored, preventing script inner-text from leaking into the search index.

### Added
- AI summary context via WASM `batch_extract_context()` with graceful fallback to naive truncation
- Query PII sanitization via `sanitizeQueryForLogging()` utility (uses WASM when available)
- Priority page boosting via WASM `match_priority_pages()` with configurable URL patterns and boost weights
- URL sync: search query synced to `?q=` parameter via `replaceState`; `popstate` and page-load restore; behavioral test coverage

### Changed
- `merge_results` call updated to N-set format (`sets` array with weights, `deduplicate_by`, `normalize_urls`)

## [0.2.2] - 2026-04-16

### Added

- **`ScoltaConfig::$language`** (default `'en'`): ISO 639-1 language code passed to WASM for stop word filtering.
- **`ScoltaConfig::$customStopWords`** (default `[]`): Additional stop words layered on top of the built-in language list.
- **`ScoltaConfig::$recencyStrategy`** (default `'exponential'`): Recency decay function — `exponential`, `linear`, `step`, `none`, or `custom`.
- **`ScoltaConfig::$recencyCurve`** (default `[]`): Control points `[[days, boost], …]` for the `custom` recency strategy.
- **`batchScoreResults(queries)`** in `scolta.js`: JS wrapper around the WASM `batch_score_results` export; exposed on `Scolta` global and instance API.

### Changed

- `ScoltaConfig::toJsScoringConfig()` now includes `LANGUAGE`, `CUSTOM_STOP_WORDS`, `RECENCY_STRATEGY`, and `RECENCY_CURVE` keys.
- `scolta.js` `getConfig()` / `getInstanceConfig()` read the four new scoring keys from `window.scolta.scoring` with safe `??` defaults.

## [0.2.1] - 2026-04-15

### Added

- Sequential page numbering fix: removes crc32 fallback that corrupted indexes with string/UUID keys
- Multilingual concordance test suite: 19 languages × 5 pages, compared to Pagefind 1.5.0 reference
- Snowball stemmer concordance corpus: 177k words across EN/DE/FR/ES/RU with thresholds
- Byte-level structural parity tests for CBOR index files
- Performance benchmark script (`scripts/benchmark.php`)
- Part 1: Posting list semantic correctness test — every indexed word verified present in its referenced fragment
- Part 2: Filter index structure test, delta encoding integration tests, compression coverage tests
- Part 3: Benchmark script now emits structured JSON with median-of-3 sampling; BENCHMARKS-LATEST.md auto-generated; CI benchmark job
- Part 4: Wikipedia corpus (19 languages × 5 pages) with WikipediaConcordanceTest and baseline concordance measurements
- Part 5: Extended Wikipedia corpus (different topics) with threshold revisit and updated LANGUAGE_PARITY.md
- CJK bigram tokenization: Chinese/Japanese/Korean runs now produce overlapping bigrams instead of single-character splits, improving recall for multi-character CJK terms
- Bundled `assets/pagefind/pagefind.js` (Pagefind 1.5.0 browser client) for E2E test self-sufficiency

### Fixed

- **Security:** `unserialize()` calls in `BuildState`, `IndexMerger`, and `PhpIndexer` now use `['allowed_classes' => false]` to prevent PHP object injection attacks on HMAC-validated data
- **Security:** `AiEndpointHandler` no longer includes PHP exception objects in API responses; adds optional PSR-3 logger constructor param (`NullLogger` default) for structured error logging
- **Correctness:** `PagefindFormatWriter::ensureDir()` race: replaced `!is_dir() && !mkdir()` with `@mkdir()` + post-check `is_dir()`, preventing spurious `RuntimeException` under concurrent builds
- **Correctness:** `HealthChecker` and doc claims corrected — PHP indexer supports 14 languages (Snowball) not "English-only" or "15 languages"
- **Tests:** E2E `debug.spec.js` and `search-compatibility.spec.js` now use separate output directories to prevent concurrent test race on shared `.e2e-output`
- **Tests:** E2E index rebuild always runs (removed stale-cache skip), preventing 404s from hash mismatches

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
