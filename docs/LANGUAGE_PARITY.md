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

## Known Differences from Pagefind Reference

### 1. CJK tokenization

Pagefind uses the `lindera` tokenizer (Japanese MeCab-based) and `jieba` for Chinese,
which produce compound tokens. The PHP indexer splits on Unicode character boundaries.
Result: PHP produces more tokens per sentence; individual characters are indexed rather
than semantic words. Search for a single character will return more results.

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
