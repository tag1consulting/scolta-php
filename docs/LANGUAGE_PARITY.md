# Language Parity — PHP Indexer vs Pagefind 1.5.0

This document describes the multilingual concordance test suite and the measured
parity between the PHP indexer and the Pagefind 1.5.0 reference binary.

## Tested Languages (19)

| Code | Language            | Script      | Snowball Stemmer |
|------|---------------------|-------------|-----------------|
| ar   | Arabic              | Arabic      | No              |
| zh   | Chinese Simplified  | CJK         | No              |
| da   | Danish              | Latin       | Yes             |
| nl   | Dutch               | Latin       | Yes             |
| en   | English             | Latin       | Yes             |
| fi   | Finnish             | Latin       | Yes             |
| fr   | French              | Latin       | Yes             |
| de   | German              | Latin       | Yes             |
| hu   | Hungarian           | Latin       | Yes             |
| it   | Italian             | Latin       | Yes             |
| ja   | Japanese            | CJK         | No              |
| ko   | Korean              | Hangul      | No              |
| no   | Norwegian           | Latin       | Yes             |
| pt   | Portuguese          | Latin       | Yes             |
| ro   | Romanian            | Latin       | Yes             |
| ru   | Russian             | Cyrillic    | Yes             |
| es   | Spanish             | Latin       | Yes             |
| sv   | Swedish             | Latin       | Yes             |
| tr   | Turkish             | Latin       | Yes             |

## Concordance Thresholds

The multilingual test suite (`MultilingualReferenceComparisonTest`) uses Jaccard
similarity on word sets to measure content overlap between PHP and Pagefind output.

| Script group                     | Languages                                        | Threshold |
|----------------------------------|--------------------------------------------------|-----------|
| Latin-script                     | da, nl, en, fi, fr, de, hu, it, no, pt, ro, es, sv, tr | ≥ 0.70 |
| CJK (Chinese, Japanese, Korean)  | zh, ja, ko                                       | ≥ 0.50    |
| Arabic                           | ar                                               | ≥ 0.50    |

Lower thresholds for CJK and Arabic reflect fundamental tokenization differences:
Pagefind uses language-aware segmentation (e.g., MeCab for Japanese) while the PHP
indexer uses character-boundary splitting. The component words are present; they are
split differently.

## Measured Concordance Rates (Snowball Corpus)

The Snowball stemmer corpus tests use EN/DE/FR/ES/RU content (177k words across the
five languages) with a higher-precision comparison.

| Language | Measured Rate | Notes                                           |
|----------|---------------|-------------------------------------------------|
| EN       | 99.87%        | Near-identical; tiny gap from contraction handling |
| DE       | 96.44%        | Compound words split differently (Zusammensetzungen) |
| FR       | 95.75%        | Elision handling (l', d', j') causes minor divergence |
| ES       | 99.97%        | Excellent; Spanish stemmer is highly compatible |
| RU       | 99.78%        | Cyrillic normalization well-matched              |

## Measured concordance — Wikipedia corpora

Two Wikipedia corpora were used to measure content overlap between PHP indexer and Pagefind 1.5.0:
- **Baseline** (`corpus-wiki`): science/geography topics — Physics, Mathematics, Chemistry, Geography, History
- **Extended** (`corpus-wiki-extended`): arts/culture topics — Literature, Philosophy, Music, Sport, Science

Thresholds were tightened using the rule: if both corpora exceed 0.80 (Latin) or 0.55 (CJK+Arabic),
tighten to `max(floor, min(both_values) − 0.03)`. Languages with variance > 0.05 between corpora
were left unchanged. All languages pass both corpora.

| Language | Baseline Jaccard | Extended Jaccard | Decision |
|----------|-----------------|-----------------|---------|
| ar | 0.980 | 0.988 | Tightened: 0.45 → **0.95** |
| zh | 0.931 | 0.931 | Tightened: 0.45 → **0.90** |
| da | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| nl | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| en | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| fi | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| fr | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| de | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| hu | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| it | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| ja | 1.000 | 1.000 | Tightened: 0.45 → **0.97** |
| ko | 1.000 | 1.000 | Tightened: 0.45 → **0.97** |
| no | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| pt | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| ro | 0.980 | 0.981 | Tightened: 0.65 → **0.95** (variance 0.001) |
| ru | 0.983 | 0.952 | Tightened: 0.65 → **0.92** (variance 0.031) |
| es | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| sv | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |
| tr | 1.000 | 1.000 | Tightened: 0.65 → **0.97** |

No findings filed: all languages pass both corpora at the tightened thresholds.

## Known Differences from Pagefind Reference

### 1. CJK tokenization

Pagefind uses the `lindera` tokenizer (Japanese MeCab-based) and `jieba` for Chinese,
which produce compound tokens. The PHP indexer uses bigram tokenization for CJK text
(see "CJK tokenization strategy" below). This produces overlapping character pairs
rather than semantic word units, improving overlap compared to single-character splitting.
Search for a single CJK character is not supported; search for two-character compounds works.

### 2. Arabic script

Pagefind applies Arabic-aware stemming. The PHP indexer treats Arabic as a Latin-like
script with whitespace tokenization. Most words are correctly indexed; root-based
stemming differences reduce the Jaccard score below Latin-script levels.

### 3. Compound words (German, Dutch, Swedish)

Pagefind joins hyphenated compounds into a single token in some cases. The PHP indexer
always splits on hyphens. The component words are indexed correctly; compound forms
are missing. This is a known architectural difference, not a bug.

### 4. Contractions (English, French, Italian)

Pagefind treats `don't` as one token; the PHP indexer splits to `don` + `t`. French
elisions like `l'homme` split to `l` + `homme`. This is consistent and intentional.

### 5. URL-derived tokens

Pagefind indexes text found in URL paths (e.g., `/search-results` contributes
`search` and `results`). The PHP indexer indexes only body content and explicit
metadata. This accounts for ~5% of vocabulary gap in the English test.

## CJK tokenization strategy

Scolta uses **bigram tokenization** for CJK text (Chinese, Japanese, Korean).

### Why bigrams, not dictionary-based tokenization

Pagefind uses jieba (Chinese) and lindera (Japanese, MeCab-based) for semantic
word boundary detection. A direct port is not feasible for PHP without 100-300 MB
dictionary dependencies, and Korean has no viable PHP solution at all.

Bigram tokenization emits overlapping character pairs from CJK runs:

- Input:  人工智能 (4 characters)
- Before: [人] [工] [智] [能] (4 single-char tokens, no semantic units)
- After:  [人工] [工智] [智能] (3 bigrams — both real compounds included)

This approach:

- Requires zero dependencies
- Works uniformly across Chinese, Japanese, and Korean
- Recovers compound words (at the cost of one noise token per pair)
- Raises estimated Jaccard overlap with Pagefind from ~50% to ~65-70%

### Measured concordance after bigram tokenization

| Language | Before bigrams | After bigrams                     | Change   |
|----------|----------------|-----------------------------------|----------|
| zh       | ~0.50          | 0.926 (ml corpus) / 0.931 (wiki)  | improved |
| ja       | ~0.50          | 0.928 (ml corpus) / 1.000 (wiki)  | improved |
| ko       | ~0.50          | 0.979 (ml corpus) / 1.000 (wiki)  | improved |

Note: The Wikipedia corpora use Pagefind in non-extended mode (whitespace-based CJK),
which means character splitting matches well. The ml corpus uses the standard Pagefind
binary, giving a truer measure of the bigram vs. jieba/lindera gap.

### Future enhancement path

Dictionary-based tokenization (jieba for Chinese, MeCab/lindera for Japanese)
can be added as an optional enhancement if higher precision is needed for a
specific deployment. It would replace bigrams for that language only. Korean
remains bigram-only until a suitable PHP library is available.
