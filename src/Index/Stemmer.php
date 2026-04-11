<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Word stemmer using Snowball algorithms.
 *
 * Wraps wamania/php-stemmer for consistent stemming across 14 languages:
 * Catalan (ca), Danish (da), Dutch (nl), English (en), Finnish (fi),
 * French (fr), German (de), Italian (it), Norwegian (no), Portuguese (pt),
 * Romanian (ro), Russian (ru), Spanish (es), Swedish (sv).
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
    /** @var array<string, string> Language code => stemmer class. */
    private const LANGUAGE_MAP = [
        'ca' => \Wamania\Snowball\Stemmer\Catalan::class,
        'da' => \Wamania\Snowball\Stemmer\Danish::class,
        'de' => \Wamania\Snowball\Stemmer\German::class,
        'en' => \Wamania\Snowball\Stemmer\English::class,
        'es' => \Wamania\Snowball\Stemmer\Spanish::class,
        'fi' => \Wamania\Snowball\Stemmer\Finnish::class,
        'fr' => \Wamania\Snowball\Stemmer\French::class,
        'it' => \Wamania\Snowball\Stemmer\Italian::class,
        'nl' => \Wamania\Snowball\Stemmer\Dutch::class,
        'no' => \Wamania\Snowball\Stemmer\Norwegian::class,
        'pt' => \Wamania\Snowball\Stemmer\Portuguese::class,
        'ro' => \Wamania\Snowball\Stemmer\Romanian::class,
        'ru' => \Wamania\Snowball\Stemmer\Russian::class,
        'sv' => \Wamania\Snowball\Stemmer\Swedish::class,
    ];

    private ?\Wamania\Snowball\Stemmer\Stemmer $stemmer = null;

    /**
     * @param string $language Snowball language code ('en', 'fr', 'de', 'es', etc.).
     */
    public function __construct(string $language = 'en')
    {
        $class = self::LANGUAGE_MAP[$language] ?? null;
        if ($class !== null && class_exists($class)) {
            $this->stemmer = new $class();
        }
    }

    /**
     * Stem a word to its root form.
     *
     * Returns the word unchanged for unsupported languages.
     */
    public function stem(string $word): string
    {
        if ($this->stemmer === null) {
            return $word;
        }

        return $this->stemmer->stem($word);
    }

    /**
     * Get the list of supported language codes.
     *
     * @return string[] ISO 639-1 language codes with stemming support.
     */
    public static function getSupportedLanguages(): array
    {
        return array_keys(self::LANGUAGE_MAP);
    }
}
