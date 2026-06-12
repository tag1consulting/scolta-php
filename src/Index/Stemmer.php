<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Tag1\Scolta\Index\Snowball\SnowballStemmer;

/**
 * Word stemmer using Snowball algorithms.
 *
 * Wraps the vendored pure-PHP Snowball stemmers (src/Index/Snowball/ —
 * see PROVENANCE.md there for the pinned snowball revision) for consistent
 * stemming across 14 languages:
 * Catalan (ca), Danish (da), Dutch (nl), English (en), Finnish (fi),
 * French (fr), German (de), Italian (it), Norwegian (no), Portuguese (pt),
 * Romanian (ro), Russian (ru), Spanish (es), Swedish (sv).
 *
 * These stemmers are byte-compatible with pagefind_stem 1.0.0, the crate
 * Pagefind 1.5.0 compiles into its query-time WASM — index-time stems must
 * match query-time stems exactly or queries silently miss documents.
 *
 * For unsupported languages (Arabic, Greek, Hindi, Hungarian, Turkish, etc.),
 * words are returned unchanged — search still works, just without
 * morphological matching ("running" won't match "run").
 *
 * CJK languages (Chinese, Japanese, Korean) use character-level tokenization
 * and don't require stemming.
 */
class Stemmer
{
    /** @var array<string, class-string<SnowballStemmer>> Language code => stemmer class. */
    private const LANGUAGE_MAP = [
        'ca' => Snowball\CatalanStemmer::class,
        'da' => Snowball\DanishStemmer::class,
        'de' => Snowball\GermanStemmer::class,
        'en' => Snowball\EnglishStemmer::class,
        'es' => Snowball\SpanishStemmer::class,
        'fi' => Snowball\FinnishStemmer::class,
        'fr' => Snowball\FrenchStemmer::class,
        'it' => Snowball\ItalianStemmer::class,
        'nl' => Snowball\DutchStemmer::class,
        'no' => Snowball\NorwegianStemmer::class,
        'pt' => Snowball\PortugueseStemmer::class,
        'ro' => Snowball\RomanianStemmer::class,
        'ru' => Snowball\RussianStemmer::class,
        'sv' => Snowball\SwedishStemmer::class,
    ];

    private ?SnowballStemmer $stemmer = null;

    /**
     * Memoized stem results keyed by input word.
     *
     * Capped at CACHE_MAX_ENTRIES to bound memory on large corpora. Once the
     * cap is reached, new words are still stemmed correctly but not cached.
     * Common words are encountered first and will always be in the cache; rare
     * words from long-tail vocabulary are re-stemmed on each occurrence.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    private const CACHE_MAX_ENTRIES = 100_000;

    /**
     * @param string $language Snowball language code ('en', 'fr', 'de', 'es', etc.).
     */
    public function __construct(string $language = 'en')
    {
        $class = self::LANGUAGE_MAP[$language] ?? null;
        if ($class === null) {
            // Unsupported language — fallback to no stemming is intentional.
            return;
        }
        if (!class_exists($class)) {
            throw new \RuntimeException(
                "Stemmer class {$class} not found. "
                . 'The Snowball stemmers are vendored in tag1/scolta-php under src/Index/Snowball; '
                . 'reinstall the package or regenerate them with scripts/generate-stemmers.sh',
            );
        }
        $this->stemmer = new $class();
    }

    /**
     * Stem a word to its root form.
     *
     * Returns the word unchanged for unsupported languages.
     * Results are memoized per instance — the Snowball algorithm is pure and
     * deterministic, and the same words recur heavily across pages in a chunk.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function stem(string $word): string
    {
        if (isset($this->cache[$word])) {
            return $this->cache[$word];
        }

        $result = $this->stemmer === null ? $word : $this->stemmer->stemWord($word);

        if (count($this->cache) < self::CACHE_MAX_ENTRIES) {
            $this->cache[$word] = $result;
        }

        return $result;
    }

    /**
     * Get the list of supported language codes.
     *
     * @return string[] ISO 639-1 language codes with stemming support.
     * @since 1.0.0
     * @stability stable
     */
    public static function getSupportedLanguages(): array
    {
        return array_keys(self::LANGUAGE_MAP);
    }
}
