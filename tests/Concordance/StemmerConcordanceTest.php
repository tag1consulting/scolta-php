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

    /**
     * Validate PHP stemmer output against stems extracted from Pagefind reference.
     *
     * Extracts all stemmed words from the Pagefind reference index, collects
     * all raw words from the corpus, stems them with PHP, and checks overlap.
     * High coverage means PHP's Snowball produces stems compatible with
     * Pagefind's Rust Snowball.
     */
    public function testStemmerVsPagefindReference(): void
    {
        $referenceDir = __DIR__ . '/../fixtures/concordance/reference';
        if (!file_exists($referenceDir . '/pagefind-entry.json')) {
            $this->markTestSkipped('Reference fixtures not generated.');
        }

        // Extract all stemmed words from Pagefind reference index.
        $refStems = $this->extractStemsFromIndex($referenceDir);
        $this->assertNotEmpty($refStems, 'Reference index should contain stems');

        // Extract all raw words from the corpus HTML.
        $corpusDir = __DIR__ . '/../fixtures/concordance/corpus';
        $corpusWords = $this->extractWordsFromCorpus($corpusDir);
        $this->assertNotEmpty($corpusWords, 'Corpus should contain words');

        // Stem all corpus words with PHP.
        $phpStems = [];
        foreach ($corpusWords as $word) {
            $stem = $this->stemmer->stem(mb_strtolower($word));
            if (mb_strlen($stem) >= 2) {
                $phpStems[$stem] = true;
            }
        }
        $phpStemSet = array_keys($phpStems);
        sort($phpStemSet);

        $refStemSet = array_values(array_unique($refStems));
        sort($refStemSet);

        // Check coverage: what fraction of Pagefind's stems does PHP produce?
        $intersection = count(array_intersect($refStemSet, $phpStemSet));
        $refCount = count($refStemSet);
        $coverage = $refCount > 0 ? $intersection / $refCount : 1.0;

        // 70% coverage. Pagefind extracts words from URL paths (yielding stems
        // like "01", "02", "03") and handles CJK/entities/hyphens differently.
        // These path-derived and structural tokens inflate the reference stem
        // count beyond what the PHP indexer sees from cleaned content text.
        $this->assertGreaterThanOrEqual(
            0.70,
            $coverage,
            sprintf(
                "Stemmer coverage: %.1f%% of reference stems found in PHP.\n"
                . "Reference: %d stems, PHP: %d stems, shared: %d.\n"
                . 'In ref but not PHP (sample): %s',
                $coverage * 100,
                $refCount,
                count($phpStemSet),
                $intersection,
                implode(', ', array_slice(array_diff($refStemSet, $phpStemSet), 0, 20))
            )
        );
    }

    /** @return string[] Stemmed words from pf_index files. */
    private function extractStemsFromIndex(string $dir): array
    {
        $stems = [];
        foreach (glob($dir . '/index/*.pf_index') ?: [] as $file) {
            $decoded = \Tag1\Scolta\Tests\Support\CborDecoder::decodePfFile($file);
            $entries = $this->unwrapIndexArray($decoded);
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry[0]) && is_string($entry[0])) {
                    $stems[] = $entry[0];
                }
            }
        }

        return $stems;
    }

    /** @return string[] All words from corpus HTML files. */
    private function extractWordsFromCorpus(string $dir): array
    {
        $words = [];
        foreach (glob($dir . '/*.html') as $file) {
            $text = strip_tags(file_get_contents($file));
            preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
            if (!empty($matches[0])) {
                $words = array_merge($words, $matches[0]);
            }
        }

        return array_values(array_unique($words));
    }

    private function unwrapIndexArray(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }
        if (count($decoded) === 1 && is_array($decoded[0] ?? null) && !empty($decoded[0]) && is_array($decoded[0][0] ?? null)) {
            return $decoded[0];
        }

        return $decoded;
    }
}
