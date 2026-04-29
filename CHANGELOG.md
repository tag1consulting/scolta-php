# Changelog

All notable changes to scolta-php will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased]

_No changes yet._

## [0.3.6] - 2026-04-29

### Fixed
- **Phrase proximity scoring now works for multi-word queries** — `computeContentWordLocations` replaces Pagefind's `data.locations` (which are not word positions) with real 0-indexed word positions derived from `data.content`. Pages containing adjacent query terms now correctly receive the 2.5× `phrase_adjacent_multiplier` boost from scolta-core.

### Added
- **`ScoltaConfig::$aiExpansionModel`** (default `''`): Optional model identifier for query expansion. When set, the expand-query operation uses this model instead of `aiModel`. Leave empty to use `aiModel` for all operations. Useful for routing expansion through a smaller, cheaper model (e.g., `claude-haiku-4-5-20251001`) while keeping summarize and follow-up on a more capable model. Zero-config — existing installs are unaffected.
- **`AiServiceAdapter::messageForOperation(string $operation, ...): string`**: New method that selects the correct model for the given operation before delegating to the built-in `AiClient`. Platform framework integrations (`tryFrameworkAi`) take precedence as before.

### Changed
- **Default summarize prompt: stronger constraint filtering** — FILTER rule now explicitly instructs the AI not to report what it filtered out or that most results contained X. New DIG rule instructs the AI to look harder at remaining excerpts when a filter removes most results (partial matches, substitution notes, vegan/allergy variations all count). VARIETY rule now prevents deep-diving into a single result's details when the user asked a broad question.

## [0.3.5] - 2026-04-28

### Changed
- **`expand_primary_weight` default lowered to 0.5** (was 0.7) — gives AI-expanded terms more influence for intent-based queries. Literal keyword matches no longer dominate over semantically correct expansions. Users who prefer the previous behavior can set `expand_primary_weight: 0.7` in their config.
- **`ai_summary_top_n` default raised to 10** (was 5) — the AI sees more results and has more material to curate from, improving curation quality for constraint queries and diverse result sets.
- **`ai_summary_max_chars` default raised to 4000** (was 2000) — supports the increased `ai_summary_top_n` with enough excerpt content for the AI to make good decisions.
- **Default summarize prompt rewritten** — new prompt instructs the AI to act as a knowledgeable curator, not a search results narrator: filters results that contradict the query (e.g. egg-containing results for an egg-free query), presents 4-6 items instead of deep-diving into one, eliminates hedging language. Grounding constraint (use only provided excerpts) preserved.
- **Default expand_query prompt adds rule 12** — constraint queries ("without X," "X-free," "gluten-free," etc.) now preserve the constraint in expansions rather than dropping it.
- **Default follow_up prompt adds constraint preservation** — conversational context now explicitly maintains query constraints (dietary, allergies, preferences) across follow-up turns.

### Fixed
- **JS search layer now passes `primary_query` to WASM scoring for expanded-query results** — enables the cross-query title boost added in scolta-core 0.3.4. Previously, expanded-query results could not receive title boost from the original query terms, causing ranking bias against semantically correct results whose titles matched the user's original query but not the AI-expanded terms.
- **PHP indexer positions use word indices instead of character offsets** — phrase proximity scoring now works correctly for multi-word queries.
- **Title tokens no longer duplicated into body positions** — matches Pagefind binary behavior.
- **Word count excludes URL tokens** — fragment `word_count` now matches content word count.

## [0.3.4] - 2026-04-27

### Changed
- Improve: `expand_query` prompt now instructs the LLM to avoid standalone audience/demographic terms (children, family, professional, etc.), reducing false matches from contextual noise. Adds explicit rule 11 for AUDIENCE QUALIFIERS directing expansion to focus on the topic, not the audience.

### Added
- **`indexer` property test coverage.** Added `testIndexerDefaultsToAuto` and `testFromArrayMapsIndexer` to `ScoltaConfigTest` — the `indexer` property (default `'auto'`, accepts `'php'`/`'binary'`/`'auto'`) had zero test coverage.
- **AI feature toggle enforcement in `AiEndpointHandler`.** When `aiExpandQuery=false` or `aiSummarize=false`, the handler now returns `['ok'=>false, 'status'=>404, 'error'=>'Feature disabled']` immediately without calling the AI service. `AiControllerTrait::createHandler()` passes these flags from `ScoltaConfig`. Added five tests: disabled returns 404, disabled does not call AI service (expand and summarize), follow-up unaffected by either toggle.
- **AI configuration tests (Phase 5).** Added `testAiLanguagesFlagsPropagateToJsOutput`: sets `ai_languages`, `ai_expand_query=false`, `ai_summarize=false`, `max_follow_ups=0` and confirms all appear correctly in `toJsScoringConfig()` output.
- **Display behavior tests (Phase 2).** Added `excerpt_length` to `testToJsScoringConfigValuesMatchConfig` to confirm `EXCERPT_LENGTH` in JS output reflects config.
- **Scoring behavior tests (Phase 1).** `ScoltaConfigTest`: completeness check for all 25 `toJsScoringConfig()` keys, value-mapping assertions, phrase-proximity field assertions, and a negative test confirming server-side keys (`cacheTtl`, `aiApiKey`, etc.) are absent from the JS output. `AiEndpointHandlerTest`: `testCacheTtlZeroNeverReadsCache`, `testCacheTtlZeroNeverWritesCache`, `testMaxFollowUpsZeroBlocksImmediately` (with `TrackingCacheDriver`). New `tests/Service/AiServiceAdapterTest.php`: custom prompt overrides returned raw without `{SITE_NAME}` substitution; default prompts resolve site name and description; empty overrides fall back to default.

### Fixed
- **Hygiene:** Replaced `uniqid('', true)` with `bin2hex(random_bytes(8))` in `IndexMerger` — avoids period-containing directory names that can confuse cleanup scripts.
- **Hygiene:** Removed `@` error suppression from `mkdir` calls in `IndexMerger`, `PagefindFormatWriter`, and `StreamingFormatWriter`; replaced with explicit `is_dir()` fallback + `RuntimeException`.
- **Hygiene:** Removed `@unserialize` in `PhpIndexer`; replaced with `try/catch \Throwable` so corrupt cache entries surface in logs instead of silently recomputing.
- **Hygiene:** Added `=== false` error checks to all bare `file_put_contents` calls in `ContentExporter`, `PagefindFormatWriter`, and `StreamingFormatWriter`.
- **Hygiene:** Added TOCTOU-safe comments to intentional `@unlink` calls in `BuildState`.
- **Hygiene:** Added source-parse tests preventing reintroduction of `@mkdir`, `@unlink` outside `BuildState`, `uniqid(..., true)`, unchecked `file_put_contents`, and `unserialize` without `allowed_classes`.
- **Summarize and follow_up prompts now include a GROUNDING CHECK section.** The new section
  instructs the LLM to verify each fact against the provided excerpts before citing it, extract
  whatever IS relevant from partially-matching excerpts, note any gaps, and suggest specific search
  terms — replacing the old binary "no results" fallback that could cause the LLM to hallucinate
  or discard partially-relevant content entirely.
- **Summarize prompt now requires per-excerpt scanning and a minimum bullet count.** The FORMAT
  RULES bullet was rewritten to instruct the LLM to scan each excerpt individually and produce
  at least 3-5 detail bullets when content is present, rather than conditionally adding "a
  bulleted list" only when details happen to be obvious. Reduces sparse or single-bullet responses
  for queries with multiple relevant excerpts.

## [0.3.3] - 2026-04-26

### Added
- **`BuildIntentFactory::fromFlags(bool $resume, bool $restart, int $totalCount, MemoryBudget $budget): BuildIntent`**: Centralises the `match(true)` resume/restart/fresh dispatch pattern duplicated across all three adapter CLIs. Resume takes precedence over restart; both ignore $totalCount for resume.
- **`MemoryBudgetConfig::fromCliAndConfig(?string $cliBudgetOption, ?string $cliChunkOption, callable $configReader): MemoryBudget`**: Single call to resolve CLI flags over saved config with correct precedence and zero-chunk normalisation. Platform adapters pass a `$configReader` callable instead of a config array so loading is lazy.
- **`AiControllerTrait`**: PHP trait providing `createHandler(object $aiService, ScoltaConfig $config): AiEndpointHandler` for platform AI controllers. Requires three abstract methods: `resolveCache(int $cacheTtl)`, `getCacheGeneration()`, `resolveEnricher()`. Used in place of an abstract base class so Drupal controllers can still extend `ControllerBase` and Laravel controllers can still extend `Illuminate\Routing\Controller`.
- **Atomic manifest writes.** `BuildState::commitManifest()` writes `manifest.json` via temp file + atomic rename (`LOCK_EX` + `rename()`). Process crash during write leaves at most a `.tmp` file; `readManifest()` reads it as a fallback, recovering from the last complete write instead of requiring manual directory deletion.
- **Stale lock detection at acquisition time.** `BuildState::initiateBuild()` now checks for stale lock files before attempting `flock()`. Uses the PID + timestamp written into the lock file (with `filemtime()` fallback for malformed content). Staleness threshold: `STALE_LOCK_SECONDS = 3600`. Previously, the stale check only ran in `shouldResume()` and `isRunning()`.
- **CRC32 chunk validation.** `ChunkWriter` always appends a CRC32 checksum (`crc32b`) to the chunk footer. `ChunkReader::verifyCrc32()` validates on read. `BuildState::readChunk()` calls `verifyCrc32()` and throws on mismatch — corrupted or partially written chunks are rejected before they can propagate bad data into the merged index. Pre-0.3.3 chunks without a `crc32` footer field are processed without validation (backward-compatible).
- **Version sync check script.** `scripts/check-version-sync.sh` validates that all five Scolta packages share the same version string across `composer.json`, `Cargo.toml`, plugin headers, and constants. Run from scolta-php root with sibling repos at `../scolta-*`.
- **Anti-pattern CI check.** New `antipatterns` CI job catches bare `|| "#"` without `|| data.url` fallback in JS assets.

### Performance
- **`Stemmer::stem()` memoization**: Results are now cached per Stemmer instance. Within a single chunk, the same words recur hundreds of times across pages; the cache eliminates ~97%+ of Snowball calls in typical content. Measured 166× reduction in stemmer CPU time (1506 ms → 9 ms for a 200-page chunk), lifting overall `InvertedIndexBuilder::build()` throughput from ~110 pages/s to ~1,470 pages/s on the benchmark corpus.
- **`Tokenizer::tokenize()` O(n) char-offset tracking**: The previous implementation computed character offsets with `mb_strlen(substr($text, 0, $byteOffset))` per token, allocating an increasingly long prefix string on every match. Replaced with incremental tracking — each call now measures only the delta from the previous match boundary. Tokenizer scaling is now exactly O(n) (×2.0 time per 2× words); the previous implementation scaled at O(n^1.7) and would degrade further for multibyte content.
- **`Tokenizer` ASCII fast-path**: `tokenize()` now detects pure-ASCII text once per call (`strlen === mb_strlen`) and propagates a `$textIsAscii` flag to `normalize()` and `splitCompound()`. For ASCII input: the ICU Transliterator call is skipped entirely (~0.45 µs/token saved), `strtolower`/`strlen` replace their `mb_` counterparts, gap tracking uses byte offsets directly, and the CJK `preg_match` in `splitCompound` is bypassed. Multibyte content is unaffected. Measured 3.3× reduction in per-call tokenizer time for ASCII bodies (0.43 ms → 0.13 ms per 500-word call, 0.86 → 0.26 µs/token). Cumulative throughput after rounds 1 and 2: ~110 pages/s → ~1,595 pages/s (~14.5× from baseline).

## [0.3.2] - 2026-04-24

Coordinated release with scolta-core, scolta-wp, scolta-drupal, scolta-laravel. Fixes a search-result rendering bug that affected every page since the streaming writer landed, and adds a streaming export path that enables the framework packages to drop their pre-load regression.

### Fixed
- **Search result URLs rendering as `#`**: `scolta.js` line 1273 fell through to `"#"` because `data.meta.url` is never populated — `StreamingFormatWriter` writes `url` at the fragment top level, not inside the `meta` sub-object, and explicitly filters `url` out of `meta`. Every other URL read-site in `scolta.js` carried the `|| data.url` fallback; line 1273 was missing it. Fixed: `data.meta?.url || data.url || "#"`. Present since the streaming writer landed in 0.3.0. (#7)

### Added
- **`MemoryBudget::fromOptions(string $memoryBudget, ?int $chunkSize): self`**: Single factory method for all framework adapters. Accepts a profile name or byte string and an optional chunk size override. Eliminates the inline `fromString()` + conditional `withChunkSize()` pattern that was duplicated across scolta-wp, scolta-drupal, and scolta-laravel. (#9)
- **`MemoryBudget::withChunkSize(int $chunkSize): self`**: Returns a new `MemoryBudget` instance with the chunk size overridden independently of the memory profile. The merge open-file-handle cap is adjusted upward to match when the new chunk size exceeds the profile default. Enables sites to tune chunk size and memory budget as two independent knobs rather than being forced into one of the three named profiles. Available to all framework adapters via `--chunk-size` CLI flags and admin settings. (#9)
- **`MemoryBudgetConfig` now carries chunk size and accepts arbitrary byte strings**: `MemoryBudgetConfig::load()` accepts `chunk_size` (integer, optional) and now validates `profile` as either a named profile or a byte string like `"256M"` rather than requiring one of the three named profiles. `toMemoryBudget()` delegates to `MemoryBudget::fromOptions()`. `toArray()` includes `chunk_size`. All platform adapters that persist `MemoryBudgetConfig` gain these fields automatically. (#9)
- **`MemoryTelemetry` now logs elapsed wall-clock time**: Every `emit()` call now includes `elapsed_s` in its PSR-3 context and appends `+{elapsed_s}s` to the log message. Framework adapters wired to a real logger (e.g. `Scolta_WP_CLI_Logger` with `--debug`) will now show per-phase wall-clock time, making it trivial to distinguish a slow gather step from a slow indexer without a profiler. (#8)
- **`ContentExporter::filterItems(iterable $items): \Generator`**: Generator counterpart to `exportToItems()`. Yields `ContentItem` objects one at a time without materializing the full result set in RAM. Framework adapters that stream content via a generator MUST use this instead of `exportToItems()`. Existing `exportToItems()` is unchanged. (#7)
- **Recipe catalog fixture** (`tests/fixtures/recipes/`): 20 Pagefind-compatible HTML files representing a multilingual recipe corpus used in README examples. Covers cross-dialect vocabulary pairs (aubergine/eggplant, courgette/zucchini, rocket/arugula, capsicum/bell pepper, coriander/cilantro, scallion/spring onion) and diet/cuisine filter attributes. (#6)

### Changed
- README rewritten with status, running example, and recipe fixtures. (#6)

## [0.3.1] - 2026-04-23

### Fixed
- **Release workflow**: Trigger now accepts both `v0.x.x` and bare `0.x.x` tag formats. The 0.3.0 tag lacked the `v` prefix, preventing the workflow from firing.

### Added
- **`MemoryBudgetSuggestion::checkProfileFit()`**: New static method that checks whether a named profile fits within the given PHP `memory_limit` (or auto-detects it). Returns a status (`safe`/`warn`) and a human-readable warning string when the profile's target RAM exceeds 70% of the limit. Accepts an optional `?int $limitBytes` parameter for testability.
- **`MemoryBudgetSuggestion::getMemoryLimitText()`**: Returns the PHP `memory_limit` as a human-readable string ("256 MB", "unlimited", "unknown"). Used by admin UIs to display the current limit inline.
- **`MemoryBudgetConfig`** and **`MemoryBudgetRepository`** in `src/Config/`: Shared value object and interface for platform adapters to persist and resolve the memory budget profile without duplicating logic.

## [0.3.0] - 2026-04-23

### Added
- **`MemoryBudget`**: Three named profiles (`conservative` / `balanced` / `aggressive`) controlling chunk size, flush threshold, merge handle count, and total RAM budget. Conservative targets ≤ 96 MB peak RSS and is the runtime default.
- **`BuildIntent`**: Immutable value object encoding the caller's intent — `fresh`, `resume`, or `restart` — so the orchestrator can make a single consistent decision without re-reading flags at every call site.
- **`BuildCoordinator`**: State machine over `BuildState`; exposes `shouldResume()`, `startFresh()`, `commitChunk()`, and `releaseLockOnly()`. Separates "what to do next" logic from both the loop and the raw file I/O in `BuildState`.
- **`IndexBuildOrchestrator`**: Single authoritative chunk-loop entry point. Accepts a `ContentSourceInterface`, `MemoryBudget`, `BuildIntent`, and optional `ProgressReporterInterface`; drives the full build pipeline from content gather → chunk write → pre-merge → final merge.
- **`ProgressReporterInterface`** / **`NullProgressReporter`**: Adapter contract for surfacing build progress to framework-specific UIs (Artisan progress bar, WP-CLI progress bar, Drush output).
- **`StatusReport`**: Immutable result object returned by `IndexBuildOrchestrator::build()`; carries page count, elapsed time, peak RAM, and success flag.
- **`MemoryBudgetSuggestion`**: Advisory helper that inspects `memory_limit` and corpus size to suggest a budget profile.
- **`MemoryTelemetry`**: PSR-3 log helper that emits phase-boundary RAM readings for diagnosing memory usage in production.
- **`BuildState::releaseLockOnly()`**: Drops the build lock without resetting manifest status to `idle`, leaving a paused build resumable.

### Changed
- **`PhpIndexer`**: Delegates chunk-loop and merge orchestration to `IndexBuildOrchestrator`; retains only content-gather and fingerprint-check responsibilities.
- **`IndexMerger::streamMergeTermsToFile()`**: Rewrote to stream one term at a time directly to the output file handle (raw v2 binary format, no `ChunkWriter` allocation). Eliminates the last source of OOM: previously the pre-merge step accumulated the entire merged vocabulary in RAM before writing.

### Fixed
- **PHP indexer OOM on large corpora**: `PhpIndexer::finalize()` previously loaded all chunk data into RAM simultaneously (one deserialized array per chunk + full merged index + a second copy during page-number remapping), causing fatal out-of-memory errors on sites with thousands of pages. The merge pipeline is now fully streaming: chunks are written in a new v2 format (length-prefixed `serialize()` records with sorted terms), merged via an N-way `SplMinHeap` pass that keeps only one term in memory at a time, and handed directly to `StreamingFormatWriter` which writes Pagefind fragment files incrementally. Peak RAM is now ~5-10 MB regardless of corpus size.

### Added (streaming infrastructure)
- **`ChunkWriter`**: writes v2 streaming chunk files (JSON header, alphabetically-sorted length-prefixed records, HMAC footer).
- **`ChunkReader`**: reads v2 chunk files lazily via `openPages()` and `openIndex()` generators; throws `RuntimeException` for pre-0.3.0 serialized files.
- **`StreamingFormatWriter`**: Pagefind-compatible index writer that accepts pages and terms one at a time, keeping peak RAM independent of corpus size.
- **`IndexMerger::mergeStreaming()`**: N-way streaming merge using `SplMinHeap`; replaces the removed `mergeFromFiles()` method.

## [0.2.4] - 2026-04-21

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
