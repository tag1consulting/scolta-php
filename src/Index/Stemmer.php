<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Word stemmer using Snowball algorithms.
 *
 * Wraps wamania/php-stemmer to provide consistent stemming that
 * matches the Pagefind binary's Rust pagefind_stem crate.
 */
class Stemmer
{
    private ?\Wamania\Snowball\Stemmer\Stemmer $stemmer = null;

    /**
     * @param string $language Snowball language code ('en', 'fr', 'de', 'es', etc.).
     */
    public function __construct(string $language = 'en')
    {
        $class = $this->resolveClass($language);
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
     * Map language code to wamania/php-stemmer class.
     */
    private function resolveClass(string $language): ?string
    {
        $map = [
            'en' => \Wamania\Snowball\Stemmer\English::class,
            'fr' => \Wamania\Snowball\Stemmer\French::class,
            'de' => \Wamania\Snowball\Stemmer\German::class,
            'es' => \Wamania\Snowball\Stemmer\Spanish::class,
            'it' => \Wamania\Snowball\Stemmer\Italian::class,
            'pt' => \Wamania\Snowball\Stemmer\Portuguese::class,
            'nl' => \Wamania\Snowball\Stemmer\Dutch::class,
            'sv' => \Wamania\Snowball\Stemmer\Swedish::class,
            'no' => \Wamania\Snowball\Stemmer\Norwegian::class,
            'da' => \Wamania\Snowball\Stemmer\Danish::class,
            'fi' => \Wamania\Snowball\Stemmer\Finnish::class,
            'ro' => \Wamania\Snowball\Stemmer\Romanian::class,
            'ru' => \Wamania\Snowball\Stemmer\Russian::class,
        ];

        return $map[$language] ?? null;
    }
}
