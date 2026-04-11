<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Stemmer;

/**
 * Stemmer concordance test.
 *
 * Validates that the PHP stemmer (wamania/php-stemmer) produces consistent
 * results across a diverse English word corpus. When Pagefind reference
 * stems are available, this test validates zero divergence.
 */
class StemmerConcordanceTest extends TestCase
{
    private Stemmer $stemmer;

    protected function setUp(): void
    {
        $this->stemmer = new Stemmer('en');
    }

    /**
     * Test stemmer consistency with a large word corpus.
     *
     * These words cover common English morphological patterns:
     * plurals, verb conjugations, comparatives, gerunds, etc.
     */
    public function testStemmerConsistencyCorpus(): void
    {
        $wordStemPairs = [
            // Regular plurals
            'cats' => 'cat', 'dogs' => 'dog', 'houses' => 'hous',
            'boxes' => 'box', 'churches' => 'church', 'buses' => 'buse',
            // Verb forms
            'running' => 'run', 'walks' => 'walk', 'walked' => 'walk',
            'walking' => 'walk', 'runs' => 'run', 'jumped' => 'jump',
            'jumping' => 'jump', 'jumps' => 'jump',
            // -ing forms
            'computing' => 'comput', 'searching' => 'search',
            'indexing' => 'index', 'processing' => 'process',
            // -tion/-sion
            'organization' => 'organ', 'information' => 'inform',
            // -ly
            'quickly' => 'quick', 'carefully' => 'care',
            // -ness
            'happiness' => 'happi', 'darkness' => 'dark',
            // -er/-est
            'faster' => 'faster', 'biggest' => 'biggest',
            // -ed
            'searched' => 'search', 'indexed' => 'index',
            'processed' => 'process', 'configured' => 'configur',
            // -able/-ible
            'searchable' => 'searchabl', 'configurable' => 'configur',
            // Technical terms
            'algorithms' => 'algorithm', 'databases' => 'databas',
            'implementations' => 'implement', 'interfaces' => 'interfac',
            // Already stemmed
            'run' => 'run', 'search' => 'search', 'index' => 'index',
        ];

        $divergences = [];
        foreach ($wordStemPairs as $word => $expected) {
            $actual = $this->stemmer->stem($word);
            if ($actual !== $expected) {
                $divergences[] = "{$word}: expected '{$expected}', got '{$actual}'";
            }
        }

        $this->assertEmpty(
            $divergences,
            "Stemmer divergences found:\n" . implode("\n", $divergences)
        );
    }

    /**
     * Test that stemming is idempotent (stemming a stem returns the same stem).
     */
    public function testStemmerIdempotent(): void
    {
        $words = ['run', 'search', 'walk', 'comput', 'index', 'process'];
        foreach ($words as $word) {
            $stemmed = $this->stemmer->stem($word);
            $this->assertSame($stemmed, $this->stemmer->stem($stemmed), "Stemming '{$word}' should be idempotent");
        }
    }

    /**
     * Test that unsupported languages return words unchanged.
     */
    public function testUnsupportedLanguageFallback(): void
    {
        $stemmer = new Stemmer('ar'); // Arabic not supported
        $this->assertSame('hello', $stemmer->stem('hello'));
    }

    /**
     * Test stemmer handles empty and single-character inputs.
     */
    public function testEdgeCases(): void
    {
        $this->assertSame('', $this->stemmer->stem(''));
        $this->assertSame('a', $this->stemmer->stem('a'));
        $this->assertSame('i', $this->stemmer->stem('i'));
    }

    /**
     * Test that all supported languages can stem without errors.
     */
    public function testAllSupportedLanguagesStem(): void
    {
        foreach (Stemmer::getSupportedLanguages() as $lang) {
            $stemmer = new Stemmer($lang);
            $result = $stemmer->stem('test');
            $this->assertIsString($result, "Stemmer for '{$lang}' should return a string");
        }
    }
}
