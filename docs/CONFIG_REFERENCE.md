# Scolta Configuration Reference

All Scolta configuration flows through `Tag1\Scolta\Config\ScoltaConfig`. Platform adapters map their native config systems into this object. The `fromArray()` factory accepts snake_case keys and converts them to camelCase properties automatically.

`fromArray()` coerces incoming values to the declared PHP type of each property before assignment. This means CMS config layers that store all values as strings — for example, Drupal's `drush config:set` or WP-CLI's `wp option update` — are handled safely: `"1"` is cast to `true` for `bool` properties, `"42"` to `42` for `int`, and `"3.14"` to `3.14` for `float`. String and array properties pass through unchanged.

Passing `null` for any preset-overridable field means **"use the Site Type preset's value"**: `fromArray()` treats a `null` value as "not set" and skips it, so the named preset's value (or, with no preset, the base default) stays in place. An explicit non-null value still overrides the preset. This is the contract that lets an adapter whose config layer always emits a key for every field — such as Laravel's `config/scolta.php` — genuinely fall through to a preset by leaving that field at `null`, rather than having its concrete config default silently override every preset choice.

## Configuration Properties

### AI Provider

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `aiProvider` | string | `''` | AI provider identifier (`anthropic`, `openai`). No default: `''` means no provider has been selected and AI features are off. Selecting one is always explicit. |
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
| `recencyBoostMax` | float | `0.25` | Maximum positive boost for recent content. Preset overrides: `reference` and `content_catalog` set this to `0` (recency adds noise on non-time-sensitive content); `blog` sets `0.25`. |
| `recencyHalfLifeDays` | int | `365` | Half-life for recency decay (days) |
| `recencyPenaltyAfterDays` | int | `1825` | Age threshold before penalty applies (days, ~5 years) |
| `recencyMaxPenalty` | float | `0.3` | Maximum penalty for old content |

### Scoring: Title/Content Match

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `titleMatchBoost` | float | `2.0` | Boost for title keyword matches. Raised from `1.0` based on a full-matrix scoring sweep (improves top-1 precision across all demos). |
| `titleAllTermsMultiplier` | float | `1.5` | Multiplier when all search terms appear in title |
| `exactTitleMatchBoost` | float | `5.0` | Multiplicative boost when the result's title exactly matches the query (case-insensitive). Applied after all other scoring so an article titled "DNA" always ranks #1 for the search "DNA" regardless of BM25 scores. Set to 1.0 to disable. |
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
| `crossListBonus` | float | `0.05` | Additive score bonus when a result appears in both the primary and expanded result sets. Cross-list agreement is a relevance signal — a result matching both the literal query and semantic expansions receives this bonus on top of its highest score. Lowered from `0.15` based on a full-matrix scoring sweep (a smaller tie-breaker preserves single-source precision). Set to 0 to disable. |
| `expandSubwordMaxFrequency` | float | `0.05` | Maximum corpus frequency (fraction of indexed documents) for a multi-word expansion term's constituent word to be added as a standalone search term. Restores broad-query recall while blocking high-frequency noise words. Frequency is measured against the active search filters (including the language partition when `autoLanguageFilter` is on). Presets `content_catalog` and `none` raise this to `0.10`. Set to `0` to disable sub-word expansion (v1.0.0 behavior); `>= 1.0` admits every sub-word. |
| `expandSubwordDenyList` | array | `[]` | Guard-only veto list for the sub-word query-term exemption. A sub-word that the user literally typed normally bypasses the `expandSubwordMaxFrequency` check (a typed subject word is wanted regardless of how common it is); words listed here do NOT get that exemption, so a site can stop a typed-but-generic word (e.g. `hot` on a recipe corpus) from re-flooding results. Distinct from `customStopWords`: it does **not** affect relevance scoring or query tokenization — listed words stay searchable and scorable. Browser-side only (the guard is JS-side). |
| `specificityWeighting` | bool | `true` | Specificity-weighted ranking of partial matches. When on, each partial-match sub-query (OR fallback, expansion terms, expansion sub-words) is weighted by how rare its term is in the corpus, so a match on a rare intent-bearing term (`papilledema`, `vegetarian`) outranks a match on a ubiquitous one (`lunar`, `apollo`, `dinner`) instead of counting the same. This is what stops a common word — typed or leaked from an expansion phrase — from flooding the head of the list. Corpora whose query already matches on rare terms are unaffected (a rare term's weight is ~unchanged). Set `false` to restore flat/positional sub-query weighting. Browser-side only. |
| `specificityFloor` | float | `0.15` | Floor for the specificity weight of a ubiquitous term. A term appearing in (nearly) every document is damped to this multiplier rather than to zero, so it still contributes to recall and ranks far below rare terms without being dropped. Range `0.0`–`1.0`; lower is more aggressive damping. Browser-side only. |
| `specificityStrongMatch` | float | `0.55` | Specificity threshold at or above which a matched term counts as a strong, on-intent hit. Drives the retrieval-vs-content-gap distinction: when a term this specific matched, the partial-match banner and the AI-summary hedge stop framing the result set as a failure (`no exact matches`) and attribute any gap to the search rather than the collection. Range `0.0`–`1.0`. Browser-side only. |
| `specificityCooccurrence` | float | `0.9` | Multiplier on the bonus a document earns for agreeing with several query and expansion terms at once, rather than matching a single term strongly. A document that is on-topic across the whole query is usually a better answer than one that spikes on one rare word, and this controls how much that breadth is worth. Set to `0` to reproduce the prior maximum-only merge exactly, where each document is scored purely by its single best-matching sub-query with no co-occurrence credit. Browser-side only. |
| `specificityAgreementGate` | float | `0.45` | Specificity threshold a matched term must clear before it counts toward the co-occurrence agreement bonus. Terms below the gate are too ubiquitous for their presence to be evidence of topical agreement, so they are excluded from the count rather than inflating it. Range `0.0`–`1.0`. Browser-side only. |
| `specificityAgreementDecay` | float | `1.0` | Geometric factor applied to each successive agreeing axis, so the second agreeing term is worth this fraction of the first, the third this fraction of the second, and so on. Values below `1.0` make the bonus saturate, which keeps a document matching many mid-specificity terms from overtaking one matching a genuinely rare term. `1.0` weights every agreeing axis equally. Browser-side only. |
| `filterHintMinResults` | int | `5` | Recall guard for LLM filter hints. An expand response's `filter_hint` is auto-applied only when the filtered result union keeps at least this many results (clamped to the unfiltered count for tiny corpora). A hint that fails the guard but still matches something is rendered as an offered (clickable, not applied) chip; a hint matching nothing is dropped. Set this and `filterHintMinRatio` to `0` to restore the pre-guard always-apply behavior. Browser-side only (the guard is JS-side). |
| `filterHintMinRatio` | float | `0.1` | Companion ratio for the filter-hint recall guard: the filtered union must also keep at least this fraction of the unfiltered union, catching collapses on large corpora where the absolute floor alone would pass (161 results narrowing to 5 is a collapse, not a refinement). Browser-side only. |
| `expansionCombineMode` | string | `relevance_union` | How a multi-term expansion's per-sub-query result sets are combined into the AI summary candidate set. `relevance_union` (default) merges everything into one relevance-sorted, deduplicated pool and takes the top-N — historical behavior. `round_robin` instead groups results by the expansion sub-query that produced them and deals the top-K (3, internal) from each in turn, so the summarizer sees breadth across distinct sub-topics rather than only the single largest sub-query. **Preset default** (resolved explicit-value > preset > base, like the other preset-overridable scoring fields): `content_catalog`, `blog`, and `ecommerce` default to `round_robin`; `reference`, `none`, and the base default stay `relevance_union`. A site may override the mode explicitly. Only affects what the summarizer is shown; the visible ranked result list is always relevance-sorted. Browser-side only. |
| `expansionPerTermTopK` | int | `3` | **Internal constant, not user-configurable** — locked at `3` (evaluation found no benefit above it and over-reach below it). Number of results taken from each expansion sub-query per round when `expansionCombineMode` is `round_robin`; ignored under `relevance_union`. Reallocates within the `aiSummaryTopN` / `aiSummaryMaxChars` budgets — it never raises them. `fromArray()` ignores any `expansion_per_term_top_k` input and it is always emitted to the JS config as `3`. Browser-side only. |

### Scoring: Priority Pages

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `priorityPages` | array | `[]` | Pages that receive a score boost when their URL pattern or keywords match the query. Each entry: `{ url_pattern, keywords, boost }`. Matched pages are sorted to the top regardless of Pagefind rank. Processed by WASM `match_priority_pages()` when available. |

### Scoring: Language and Stop Words

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `language` | string | `'en'` | ISO 639-1 language code for stop word filtering (`en`, `de`, `fr`, …). 30 languages supported; CJK and unknown codes apply no stop word filtering. |
| `customStopWords` | array | `[]` | Additional stop words to filter beyond the language's built-in list. Applied in both the WASM scorer and JS query tokenization (`scolta.js` `extractSearchTerms`) — no longer scoring-only. |

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
| `showAttribution` | bool | `false` | Show "Powered by Scolta" attribution on the search page. Disabled by default to comply with WordPress.org Guideline 10 (no unsolicited attribution on user-facing pages). Enable only when the site administrator explicitly opts in. |

### AI Features

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `aiExpandQuery` | bool | `true` | Enable AI query expansion |
| `expansionToggle` | bool | `true` | Offer visitors a switch in the results header that turns query expansion off for themselves. Separate from `aiExpandQuery`, which decides whether expansion runs at all: a site can want expansion on with no visitor-facing control over it. The choice is held in browser storage and narrows only — it cannot enable expansion where `aiExpandQuery` is false or a platform access rule refused it, and no switch is rendered in either case. Nothing server-side reads it, so it needs no session and is invisible to HTTP caches. `@since 1.4.0`, `@stability experimental`. |
| `aiSummarize` | bool | `true` | Enable AI result summarization |
| `aiSummaryTopN` | int | `10` | Number of top results sent to AI for summarization |
| `aiSummaryMaxChars` | int | `4000` | Maximum characters of content sent to AI for summarization |
| `aiSummaryMaxTokens` | int | `1024` | Hard ceiling on tokens the AI may use for a summary response. Sits comfortably above the prompt's natural output length so summaries are never cut off mid-sentence |

### Multilingual

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `aiLanguages` | array | `['en']` | Supported languages for AI responses. When multiple languages are configured, AI prompts include an instruction to respond in the same language as the user's query if it matches a supported language, otherwise fall back to the primary (first) language. Single-language configs do not add any instruction. |
| `autoLanguageFilter` | bool | `false` | When true, AND searches on multi-language sites automatically apply a language filter matching the user's detected language, narrowing results to that language. Leave false (the default) unless you need strict per-language separation. |

### Prompts

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `promptExpandQuery` | string | `''` | Custom prompt for query expansion (empty = use DefaultPrompts) |
| `promptSummarize` | string | `''` | Custom prompt for summarization (empty = use DefaultPrompts) |
| `promptFollowUp` | string | `''` | Custom prompt for follow-up conversations (empty = use DefaultPrompts) |

### Build

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `indexer` | string | `'auto'` | Indexing backend used by CLI build commands. `auto` and `php` both use the pure-PHP indexer (no binary or Node.js required). `binary` explicitly uses the Pagefind CLI binary and fails if it is not found. |

### Content

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `sortableFields` | array | `[]` | Field names CMS adapters should extract as sortable attributes (`data-pagefind-sort`). CMS adapters read this list to determine which `ContentItem::$sortable` entries to populate. Empty by default — no sort attributes are emitted. When non-empty, these field names are also passed to the AI expansion prompt so the LLM can return a `sort_hint` alongside expanded terms when a query implies a sort intent (e.g., "most expensive stone" → `sort_hint: {field: "price", direction: "desc"}`). |
| `sortableFieldDescriptions` | array | `[]` | Human-readable descriptions keyed by field name (e.g., `['price' => 'Product price in store currency', 'word_count' => 'Article length in words']`). When populated, descriptions are included in the sort-intent prompt alongside each field name so the LLM can map natural language queries to the correct field. Backward compatible — omitting this leaves existing behavior unchanged. |
| `filterFields` | array | `[]` | Filter dimension names for filter-intent detection in the expansion prompt. Must match the filter names emitted as `data-pagefind-filter` attributes by the content gatherer (e.g., `['topic', 'era', 'region']`). When non-empty, the expansion prompt gains a FILTER INTENT section; the LLM can return a `filter_hint` that the browser applies as a Pagefind native filter before displaying results. |
| `filterFieldDescriptions` | array | `[]` | Human-readable descriptions keyed by filter name (e.g., `['topic' => 'Subject area or domain. Values: Science (physics, chemistry, biology), History (ancient, medieval)']`). Descriptions serve two purposes: (1) they help the LLM match user language to the correct filter value in the expansion prompt, and (2) they are passed to the JS frontend via `toBrowserConfig()` where `matchSubjectToFilters()` parses parenthetical subcategory hints to map terms like "physics" → "Science" even when "physics" isn't a direct filter value. |
| `hideEmptyFacets` | bool | `true` | Hide facet values whose result count is zero for the current query, and drop a dimension's whole group when all its values are zero — the mainstream faceted-search default. An active (checked) value stays visible even at zero so it can be unchecked. Set to `false` to render every value and show a zero-count one as a disabled `(0)` row, keeping the value list positionally fixed. Counts describe the typed query **plus whatever AI expansion added to the result list**, computed once when the query is submitted and folded once more when expansion lands; a facet click, a sort and a load-more all reuse them, so no count moves on click and the visible value set stays stable. Emitted top-level into `window.scolta` by `toBrowserConfig()` and read by `scolta.js` `renderFilters()`. `@stability experimental`. |
| `facetMode` | string | `'eager'` | When the browser loads the facet index, or whether it loads it at all. `'eager'` (default) loads it during init, so the filter panel is populated before the first search paints and every facet click is answerable immediately — the behaviour Scolta has always had. `'deferred'` skips the init load and takes it on the first search carrying a facet selection, for a site that renders its own facets and does not want the artifact (a megabyte or more on a large corpus) on the initial page load; Scolta's own panel stays empty until that first selection, since it is built from the artifact. `'disabled'` never loads it: no filter panel, no facet filtering, and the per-query facet count pass is skipped. Deferring is **not** "filter without the index" — the browser completes the load before applying a selection, so a deferred first click still takes the artifact path instead of falling back to Pagefind's own filter chunks, which would cost every subsequent search on the page. An unrecognized value clamps to `'eager'` (in PHP via `normalizedFacetMode()`, and again in the browser, since a direct `createInstance()` caller bypasses this class). Emitted top-level into `window.scolta` by `toBrowserConfig()` and read by `scolta.js` `facetMode()`. `@since 1.3.0`, `@stability experimental`. |
| `labels` | array | `[]` | Per-site overrides for user-facing UI strings in the browser widget, as one `key => string` map — each string made overridable later joins this map instead of minting another top-level config key. Keys the browser currently reads: `expandedTerms` (default `'Also try:'`), the prefix before the AI-expanded query-term chips; `aiOverview` (default `'AI Overview'`), the heading on the AI summary box. Values are rendered as text (HTML-escaped). A missing, empty, or non-string value falls back to the browser default — filtered in PHP by `normalizedLabels()` and clamped again in the browser, since a direct `createInstance()` caller bypasses this class; unknown keys are ignored by the browser and deliberately not filtered in PHP, which would couple this class to the bundle's release cadence. Emitted top-level into `window.scolta` by `toBrowserConfig()` and read by `scolta.js` `getInstanceLabels()`. `@since 1.5.0`, `@stability experimental`. |

### Search As You Type (SAYT)

Ten top-level browser settings that govern the suggestions dropdown. They are **not** scoring keys: `toJsScoringConfig()` stays at exactly 40 keys, and each of these is emitted top-level by `toBrowserConfig()` and read by `scolta.js` as `instanceConfig.<camelCase>` (the `hideEmptyFacets` pattern). Every default below is byte-equal to the fallback the browser bundle uses when the key is absent. Full behaviour, including the events and the theming custom properties: [`SAYT.md`](SAYT.md). All ten are `@since 1.1.0`, `@stability experimental`.

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `saytEnabled` | bool | `true` | Master switch. When true, typing populates a suggestions dropdown under the search box; the full pipeline (AI expansion, merge, summarize, follow-up) still runs only on Enter, on the search button, or on selecting a suggestion. When `false` the widget is byte-identical to the pre-1.1.0 one: no dropdown node in the scaffold, no combobox ARIA roles on the input, no storage access, no suggest searches. |
| `saytMinChars` | int | `2` | Minimum characters typed before suggestions are requested, counted in **graphemes** (`Intl.Segmenter` where available, spread otherwise) so an emoji or a Devanagari cluster counts as the one character the person typing it sees. CJK sites commonly want `1`: a single han character is already a meaningful query. |
| `saytDebounceMs` | int | `150` | Trailing debounce, in milliseconds, before a suggest cycle fires. Independent of the index-chunk preload debounce, which continues to run on the same keystrokes. |
| `saytMaxSuggestions` | int | `6` | Maximum suggestions shown, and the hard cap on fragment loads per pass. |
| `saytRecentSearches` | bool | `true` | Offer the visitor's own recent searches, stored in `localStorage` under a single `scolta`-prefixed key. When `false`, nothing is read from or written to storage. |
| `saytMaxRecent` | int | `3` | Maximum recent searches *shown*. How many are stored is internal to the browser bundle and deliberately larger, so the prefix filter still has something to match. |
| `saytExpand` | bool | `true` | Enrich the dropdown with AI query-expansion term matches. Inert when the platform configures no AI endpoints or when `ai_expand_query` is off. |
| `saytExpandPerMinute` | int | `6` | Client-side sliding-window cap on SAYT expansion calls. SAYT expansions share the platform's AI flood budget with committed searches (expansion, summarize and follow-up all count against the same per-IP limit — 60/minute by default on Drupal), so an unbudgeted suggest path would spend a visitor's whole allowance on prefixes and starve the search they actually ran. Over the cap the dropdown silently degrades to keyword-only suggestions until the window rolls. |
| `saytExpansionDelayMs` | int | `500` | Idle delay, in milliseconds, before the AI enrichment call. Separate from and longer than the suggestion debounce: keyword suggestions should appear while typing, an AI call should not. |
| `saytSuggestionAction` | string | `'navigate'` | What selecting a **title** suggestion does. `navigate` goes straight to that result (the option renders as a real anchor carrying the same sanitized URL the result card uses). `search` puts the suggestion's title in the box and runs the full search. A recent-search suggestion always runs the search regardless. An unrecognized value clamps to `navigate`, with a logged warning. |

### Scoring Presets

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `preset` | string | `''` | Named scoring preset to apply before explicit values (empty = no preset). Preset values are overridden by any keys also present in the same `fromArray()` call. |

Each preset entry has three keys: `label` (human-readable name for UI dropdowns), `description` (one-paragraph explanation for admins), and `values` (scoring parameters). Adapter UIs read from `getPresets()` to build pickers without hardcoding any of these strings.

For the evidence behind these presets — the scoring sweep, the precision-cliff data, and which defaults are still open findings — see [`TUNING.md`](TUNING.md) in this directory.

Available presets:

| Preset | Label | Purpose | Key `values` |
|--------|-------|---------|-------------|
| `none` | Start from Scratch | No preset; use defaults (with sub-word recall slightly broadened) | `expandSubwordMaxFrequency: 0.10`, `expansionCombineMode: relevance_union` |
| `content_catalog` | Recipe & Content Catalog | Recipe/catalog sites where content quality matters more than freshness | `recencyStrategy: none`, `recencyBoostMax: 0`, `titleMatchBoost: 2.0`, `titleAllTermsMultiplier: 2.5`, `contentMatchBoost: 0.5`, `expandPrimaryWeight: 0.9`, `expandSubwordMaxFrequency: 0.10`, `expansionCombineMode: round_robin`, `aiSummaryTopN: 15`, `maxPagefindResults: 75`, `resultsPerPage: 12` |
| `reference` | Documentation & Reference | Knowledge bases, documentation, encyclopedias, medical/compliance references | `recencyStrategy: none`, `recencyBoostMax: 0`, `titleMatchBoost: 2.0`, `titleAllTermsMultiplier: 2.5`, `contentMatchBoost: 0.5`, `expandPrimaryWeight: 0.6`, `expansionCombineMode: relevance_union`, `aiSummaryTopN: 15`, `maxPagefindResults: 75`, `resultsPerPage: 12`, `excerptLength: 350` |
| `ecommerce` | E-commerce & Product Store | Product catalogs and stores with natural-language queries | `recencyStrategy: none`, `titleMatchBoost: 1.5`, `titleAllTermsMultiplier: 2.0`, `contentMatchBoost: 0.6`, `expandPrimaryWeight: 0.8`, `expansionCombineMode: round_robin`, `aiSummaryTopN: 12`, `maxPagefindResults: 75`, `resultsPerPage: 12`, `excerptLength: 300` |
| `blog` | Blog & Editorial | Narrative/editorial content with gentle temporal relevance | `recencyStrategy: exponential`, `recencyBoostMax: 0.25`, `recencyHalfLifeDays: 365`, `titleMatchBoost: 1.5`, `titleAllTermsMultiplier: 2.0`, `contentMatchBoost: 0.5`, `expandPrimaryWeight: 0.7`, `expansionCombineMode: round_robin`, `aiSummaryTopN: 12`, `maxPagefindResults: 60`, `resultsPerPage: 10`, `excerptLength: 350` |

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

`aiApiKey` is the one property an adapter does not simply copy across. Several
places can supply it, and which one wins is decided by
`Tag1\Scolta\Config\ApiKeyResolver`, not by the adapter: explicit keys in the
platform's own order, then stored Amazee.ai credentials, then nothing. The
resolver returns a `ResolvedApiKey` carrying the key, its `ApiKeySource` and
the effective provider together, so a settings form, a health payload and a
`status` command cannot describe the key differently from the client that
sends it. Pass that object to `HealthChecker` and `SetupCheck::run()` and both
report the source rather than merely whether a key exists.

The full order, the source vocabulary and the rules adapters follow are in
[API_KEY_PRECEDENCE.md](API_KEY_PRECEDENCE.md).

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
| `crossListBonus` | `scoring.cross_list_bonus` | `scoring.cross_list_bonus` | `cross_list_bonus` |
| `expandSubwordMaxFrequency` | `scoring.expand_subword_max_frequency` | `scoring.expand_subword_max_frequency` | `expand_subword_max_frequency` |
| `expandSubwordDenyList` | `scoring.expand_subword_deny_list` | `scoring.expand_subword_deny_list` | `expand_subword_deny_list` |
| `specificityWeighting` | `scoring.specificity_weighting` | `scoring.specificity_weighting` | `specificity_weighting` |
| `specificityFloor` | `scoring.specificity_floor` | `scoring.specificity_floor` | `specificity_floor` |
| `specificityStrongMatch` | `scoring.specificity_strong_match` | `scoring.specificity_strong_match` | `specificity_strong_match` |
| `specificityCooccurrence` | `scoring.specificity_cooccurrence` | `scoring.specificity_cooccurrence` | `specificity_cooccurrence` |
| `specificityAgreementGate` | `scoring.specificity_agreement_gate` | `scoring.specificity_agreement_gate` | `specificity_agreement_gate` |
| `specificityAgreementDecay` | `scoring.specificity_agreement_decay` | `scoring.specificity_agreement_decay` | `specificity_agreement_decay` |
| `filterHintMinResults` | `scoring.filter_hint_min_results` | `scoring.filter_hint_min_results` | `filter_hint_min_results` |
| `filterHintMinRatio` | `scoring.filter_hint_min_ratio` | `scoring.filter_hint_min_ratio` | `filter_hint_min_ratio` |
| `expansionCombineMode` | `scoring.expansion_combine_mode` | `scoring.expansion_combine_mode` | `expansion_combine_mode` |
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
| `showAttribution` | `show_attribution` | `show_attribution` / `SCOLTA_SHOW_ATTRIBUTION` | `show_attribution` |

### AI Feature Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `aiExpandQuery` | `ai_expand_query` | `ai_expand_query` / `SCOLTA_AI_EXPAND` | `ai_expand_query` |
| `aiSummarize` | `ai_summarize` | `ai_summarize` / `SCOLTA_AI_SUMMARIZE` | `ai_summarize` |
| `aiSummaryTopN` | `ai_summary_top_n` | `ai_summary_top_n` | `ai_summary_top_n` |
| `aiSummaryMaxChars` | `ai_summary_max_chars` | `ai_summary_max_chars` | `ai_summary_max_chars` |
| `aiSummaryMaxTokens` | `ai_summary_max_tokens` | `ai_summary_max_tokens` | `ai_summary_max_tokens` |

### Multilingual Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `aiLanguages` | `ai_languages` | `ai_languages` / `SCOLTA_AI_LANGUAGES` | `ai_languages` |
| `autoLanguageFilter` | `auto_language_filter` | `auto_language_filter` / `SCOLTA_AUTO_LANGUAGE_FILTER` | `auto_language_filter` |

### Prompt Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `promptExpandQuery` | `prompt_expand_query` | `prompts.expand_query` | `prompt_expand_query` |
| `promptSummarize` | `prompt_summarize` | `prompts.summarize` | `prompt_summarize` |
| `promptFollowUp` | `prompt_follow_up` | `prompts.follow_up` | `prompt_follow_up` |

### Build Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `indexer` | `indexer` | `indexer` / `SCOLTA_INDEXER` | `indexer` |

### Content Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `sortableFields` | `sortable_fields` | `sortable_fields` | `sortable_fields` |
| `sortableFieldDescriptions` | `sortable_field_descriptions` | `sortable_field_descriptions` | `sortable_field_descriptions` |
| `filterFields` | `filter_fields` | `filter_fields` | `filter_fields` |
| `filterFieldDescriptions` | `filter_field_descriptions` | `filter_field_descriptions` | `filter_field_descriptions` |
| `hideEmptyFacets` | `hide_empty_facets` | `hide_empty_facets` | `hide_empty_facets` |
| `facetMode` | `facet_mode` | `facet_mode` | `facet_mode` |
| `labels` | `labels` | `labels` | `labels` |

### Search-As-You-Type Keys

| ScoltaConfig Property | Drupal | Laravel | WordPress |
|----------------------|--------|---------|-----------|
| `saytEnabled` | `sayt_enabled` | `sayt_enabled` | `sayt_enabled` |
| `saytMinChars` | `sayt_min_chars` | `sayt_min_chars` | `sayt_min_chars` |
| `saytDebounceMs` | `sayt_debounce_ms` | `sayt_debounce_ms` | `sayt_debounce_ms` |
| `saytMaxSuggestions` | `sayt_max_suggestions` | `sayt_max_suggestions` | `sayt_max_suggestions` |
| `saytRecentSearches` | `sayt_recent_searches` | `sayt_recent_searches` | `sayt_recent_searches` |
| `saytMaxRecent` | `sayt_max_recent` | `sayt_max_recent` | `sayt_max_recent` |
| `saytExpand` | `sayt_expand` | `sayt_expand` | `sayt_expand` |
| `saytExpandPerMinute` | `sayt_expand_per_minute` | `sayt_expand_per_minute` | `sayt_expand_per_minute` |
| `saytExpansionDelayMs` | `sayt_expansion_delay_ms` | `sayt_expansion_delay_ms` | `sayt_expansion_delay_ms` |
| `saytSuggestionAction` | `sayt_suggestion_action` | `sayt_suggestion_action` | `sayt_suggestion_action` |

## Methods

Every public `ScoltaConfig` method carries `@since` and `@stability` PHPDoc annotations (the semantic-versioning contract described in `UPGRADE.md`); the methods below are `@stability stable`, so their signatures will not change within a major version. This holds for the whole `src/` public API and is enforced in CI by `tests/Documentation/StabilityAnnotationTest.php`.

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
| `$browserWasmDir` | `?string` | Custom browser WASM directory path, or null for default |

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
