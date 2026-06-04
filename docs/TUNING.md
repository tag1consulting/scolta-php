# Scolta Tuning Guide

## Start here: choose your site type

Most sites never need to touch an individual scoring number. Scolta ships **scoring presets** — one per common site type — and a preset sets all the relevant knobs to sensible values in one step.

- **Recipe sites, product or content catalogs** → `content_catalog` (label: *Recipe & Content Catalog*)
- **Documentation, knowledge bases, encyclopedias, medical/compliance references** → `reference`
- **E-commerce / product stores** → `ecommerce`
- **Blogs, editorial, narrative content** → `blog`
- **News sites** → no preset; use the defaults and tune recency explicitly

Set the preset in your platform's admin UI (WordPress and Drupal have a site-type picker) or in config (`preset: content_catalog`). For the full property list, every default, and the site-type → preset table, see [`CONFIG_REFERENCE.md`](CONFIG_REFERENCE.md) in this directory.

The one knob worth knowing by name is **search breadth** (`expandSubwordMaxFrequency`): higher values broaden multi-word searches so they return more results, at some risk of pulling in loosely-related matches. The `content_catalog` preset already raises it for catalog-style sites. Everything below is the evidence behind these preset choices — read on only if you want the data.

---

All findings below are from production WASM scoring (`scolta_core.score_results`) across 9 demo corpora (recipes, e-commerce, blog, encyclopedia, technical docs, legal reference, educational, social media, medical reference) — 120+ queries, 14 parameters, 3–7 values each. Title-match rate in top 10 and top-1 precision are used as quality proxies; neither is a perfect relevance metric, but they track whether results whose titles name the query topic rank above those that don't.

For the full list of all configuration properties (including non-scoring options) and the site-type → preset table, see `CONFIG_REFERENCE.md` in this directory. This guide is the *evidence* behind those preset choices; it does not repeat the property tables.

---

## Parameters That Matter

### Sub-Word Frequency Guard

**Config:** `expandSubwordMaxFrequency` / `EXPAND_SUBWORD_MAX_FREQ` — **Default: 0.05** (presets `content_catalog` and `none` raise to 0.10)

Controls whether multi-word expansion terms are decomposed into single words. At 0, no decomposition (v1.0.0 behavior). At 1.0, all sub-words injected (pre-v1.0.0 noise). The guard blocks sub-words appearing in more than X% of indexed documents.

**Sweep result:** This is the most impactful parameter — it controls recall (result count) by 2–10× on high-overlap corpora. The precision cliff is between 10% and 20%: sub-words under 10% are 88% relevant; above 20%, 71% are noise.

| Site type | Setting | Why |
|---|---|---|
| reference, ecommerce, blog | **0.05** (default) | Safe everywhere. Blocks generic vocabulary. |
| content_catalog, none | **0.10** | Useful domain terms live in the 5–10% band on catalog/wiki sites. |

### Title Match Boost

**Config:** `titleMatchBoost` / `TITLE_MATCH_BOOST` — **Default: 2.0** `[ADOPTED]`

Score boost when query terms appear in a result's title.

| Value | Avg top-10 title rate | Top-1 title match |
|---|---|---|
| 0 | 27.1% | 39.2% |
| 0.5 | 32.6% | 51.7% |
| 1.0 | 34.4% | 58.2% |
| **2.0** | **37.3%** | **67.0%** |
| 3.0 | 38.6% | 67.0% |

**Finding:** The most impactful ranking parameter. Disabling it (0) drops top-1 precision from 58% to 39%. The default was raised from 1.0 to **2.0** on this evidence — top-1 improves to 67% across all 9 demos with no observed downside. `[ADOPTED — current global default is 2.0.]` Sites with non-descriptive titles should sanity-check; 3.0 offers no meaningful further gain.

### Max Pagefind Results

**Config:** `maxPagefindResults` / `MAX_PAGEFIND_RESULTS` — **Default: 50**

Results loaded per search term.

| Value | Avg total results | Top-1 title match |
|---|---|---|
| 10 | 27 | 56.7% |
| 25 | 50 | 58.1% |
| **50** | **75** | **58.2%** |
| 100 | 112 | 58.2% |
| 200 | 155 | 58.2% |

**Finding:** 50 is the sweet spot. Below 25, ranking quality drops (fewer candidates = worse top-10 selection). Above 50, result count grows but ranking quality doesn't. Wikipedia (6,931 docs) benefits most from higher values (more results to show), but ranking quality is flat. No change needed.

### Recency Boost Max

**Config:** `recencyBoostMax` / `RECENCY_BOOST_MAX` — **Default: 0.25** global; **0** on `reference` and `content_catalog` presets `[ADOPTED]`

Maximum recency bonus for fresh content.

| Value | Avg top-10 title rate | Top-1 title match |
|---|---|---|
| **0** | **34.8%** | **59.4%** |
| 0.25 | 34.8% | 58.2% |
| 0.5 | 34.4% | 58.2% |
| 1.0 | 33.5% | 58.2% |
| 2.0 | 32.8% | 54.3% |

**Finding:** Disabling recency (0) produces the best ranking quality across all 9 demos — most demo content is not time-sensitive (recipes, docs, encyclopedias, legal). Recency adds noise by promoting recent-but-irrelevant results over older-but-relevant ones. On this evidence the global default was lowered to **0.25**, and the `reference` and `content_catalog` presets set it to **0**. `[ADOPTED via presets.]` The `blog` preset keeps a positive recency boost for sites where freshness matters.

---

## Parameters That Appear Inert (documented findings — NOT removed)

These show zero or near-zero effect across all 9 demos and all tested values. They remain in config today; the evidence below is recorded so a future maintainer can decide whether to hardcode them. **No removal has been made.**

### Exact Title Match Boost — `exactTitleMatchBoost` / `EXACT_TITLE_MATCH_BOOST`, default 5.0
Zero effect at any value (1.0 / 5.0 / 20.0 all → 34.4% top-10, 58.2% top-1). Fires only when a result title exactly equals the query string, which essentially never happens with natural-language queries. *Finding: candidate to hardcode at 5.0 and drop from user-facing config.*

### Title All-Terms Multiplier — `titleAllTermsMultiplier` / `TITLE_ALL_TERMS_MULTIPLIER`, default 1.5
Near-zero effect (1.0 / 1.5 / 3.0 all → ~34.4% top-10, 58.2% top-1). Results matching all terms in the title already rank highly from the per-term boost. *Finding: candidate to hardcode at 1.5.*

### Phrase Window — `phraseWindow` / `PHRASE_WINDOW`, default 15
Zero effect (5 / 10 / 15 / 30 all → 34.4% / 58.2%). The window's bonus is a multiplier on content_match_boost, which is small relative to title match. *Finding: candidate to hardcode at 15.*

### Phrase Near Window — `phraseNearWindow` / `PHRASE_NEAR_WINDOW`, default 5
Near-zero effect (3 / 5 / 10 / 20 → ~34.4%, 58–59% top-1). Same reason. *Finding: candidate to hardcode at 5.*

### Recency Half-Life Days — `recencyHalfLifeDays` / `RECENCY_HALF_LIFE_DAYS`, default 365
Negligible effect (90 / 365 / 730 / 1460 → ~34% top-10, 58.2% top-1). Only meaningful when `recencyBoostMax > 0`. *Finding: document as "only relevant when recencyBoostMax > 0."*

---

## Parameters Where the Default May Still Be Suboptimal (documented findings — NOT changed)

The defaults below are unchanged in current code. The sweep suggests they may be improvable; acting on any of these is a separate, sweep-validated `scolta-php` PR, not a docs change.

### Content Match Boost — `contentMatchBoost` / `CONTENT_MATCH_BOOST`, default 0.4
| Value | Top-10 | Top-1 |
|---|---|---|
| 0 | 35.8% | 63.1% |
| 0.2 | 35.0% | 65.8% |
| **0.4** (current) | **34.4%** | **58.2%** |
| 0.8 | 32.9% | 49.1% |
Setting 0.2 improved top-1 to 65.8% in the sweep; the content signal competes with the title signal. *Finding: 0.2 candidate. Not adopted.*

### Content All-Terms Multiplier — `content_all_terms_multiplier`, default 1.2 (WASM only, not in PHP config)
| Value | Top-10 | Top-1 |
|---|---|---|
| **1.0** | **34.5%** | **63.3%** |
| **1.2** (current) | **34.4%** | **58.2%** |
| 2.0 | 33.5% | 49.1% |
Disabling (1.0) improved top-1 by ~5 points. *Finding: 1.0 candidate. Not adopted.*

### Phrase Adjacent Multiplier — `phraseAdjacentMultiplier` / `PHRASE_ADJACENT_MULTIPLIER`, default 2.5
| Value | Top-10 | Top-1 |
|---|---|---|
| **1.0** | **35.3%** | **65.7%** |
| **2.5** (current) | **34.4%** | **58.2%** |
| 5.0 | 32.4% | 51.7% |
Disabling (1.0) improved top-1 by ~7.5 points. *Finding: 1.0–1.5 candidate. Not adopted.*

### Phrase Near Multiplier — `phraseNearMultiplier` / `PHRASE_NEAR_MULTIPLIER`, default 1.5
| Value | Top-10 | Top-1 |
|---|---|---|
| 1.0 | 34.2% | 59.5% |
| **1.5** (current) | **34.4%** | **58.2%** |
Slightly worse than 1.0 for top-1. *Finding: 1.0 candidate. Not adopted.*

### Expand Primary Weight — `expandPrimaryWeight` / `EXPAND_PRIMARY_WEIGHT`, default 0.5
| Value | Top-10 | Top-1 |
|---|---|---|
| 0.3 | 33.1% | 59.5% |
| **0.5** (current) | **34.4%** | **58.2%** |
| 0.9 | 34.7% | 53.2% |
Sweet spot 0.3–0.5; 0.5 is acceptable. No change indicated.

### Cross-List Bonus — `crossListBonus` / `CROSS_LIST_BONUS` — **Default: 0.05** `[ADOPTED]`
| Value | Top-10 | Top-1 |
|---|---|---|
| **0.05** (current) | 34.3% | **59.4%** |
| 0.15 | 34.4% | 58.2% |
| 0.3 | 33.7% | 56.9% |
The default was lowered from 0.15 to **0.05** on this evidence — small enough to break ties without overriding single-source precision. `[ADOPTED.]`

---

## Summary: Default Status

| Parameter | Current default | Status | Rationale |
|---|---|---|---|
| `expandSubwordMaxFrequency` | 0.05 (0.10 on content_catalog/none) | Shipped | 8-point sweep, 9 demos, relevance-assessed |
| `titleMatchBoost` | 2.0 | `[ADOPTED]` | Top-1 58→67% |
| `crossListBonus` | 0.05 | `[ADOPTED]` | 0.15 slightly worse for top-1 |
| `recencyBoostMax` | 0.25 global; 0 on ref/catalog presets | `[ADOPTED via presets]` | Recency hurts ranking on non-time-sensitive content |
| `contentMatchBoost` | 0.4 | Finding (0.2) | Competes with title signal |
| `content_all_terms_multiplier` | 1.2 | Finding (1.0) | Disabling improved top-1 ~5pt |
| `phraseAdjacentMultiplier` | 2.5 | Finding (1.0–1.5) | 2.5 hurt top-1 ~7.5pt |
| `phraseNearMultiplier` | 1.5 | Finding (1.0) | Marginal |

Findings are not yet acted on; each would require its own sweep-validated PR through the release gate.

### Inert parameters (documented, not removed)
`exactTitleMatchBoost`, `titleAllTermsMultiplier`, `phraseWindow`, `phraseNearWindow` — zero/near-zero effect across all demos; candidates to hardcode internally in a future change.

---

## Methodology and Limitations

**What was tested:** the production WASM scorer (`scolta_core v0.3.7`). Each demo's pagefind index was searched with up to 200 results per term. Expansion terms were collected from each demo's expand-query API. For each query × parameter × value, the WASM `score_results` function scored the results and the top-10 ranking was evaluated.

**Quality metric:** "title match in top 10" (fraction of top 10 with query terms in their title) and "top-1 title match" (whether the #1 result's title contains query terms). These are proxies — a result can be perfectly relevant without query terms in its title (a recipe titled "Vegetable Pad Thai" for "meatless"), and the metric ignores ordering below position 1. A proper evaluation needs human relevance judgments, which this sweep doesn't include.

**Interaction effects:** each parameter was swept independently with all others at default. Parameters interact; the open findings above are marginal effects and a joint sweep would be needed before changing several at once.

**Corpus bias:** 7 of 9 demos are non-time-sensitive (recipes, docs, encyclopedia, legal). The recency finding is strongly influenced by this — news, job-board, and event sites (not represented) likely need recency enabled.
