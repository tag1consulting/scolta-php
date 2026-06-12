<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Stemmer;

/**
 * Stemmer concordance test.
 *
 * Validates that the PHP stemmer (the vendored Snowball backend in
 * src/Index/Snowball/) reproduces Pagefind's query-time stemmer
 * (pagefind_stem 1.0.0) byte-for-byte. Index-time stems must match
 * query-time stems exactly or queries silently miss documents, so the
 * corpus tests tolerate zero divergence.
 */
class StemmerConcordanceTest extends TestCase
{
    private Stemmer $stemmer;

    protected function setUp(): void
    {
        $this->stemmer = new Stemmer('en');
    }

    /**
     * Test stemmer consistency with a curated English word corpus.
     *
     * These words cover common English morphological patterns:
     * plurals, verb conjugations, comparatives, gerunds, etc.
     * Expected stems are pagefind_stem 1.0.0 output (modern Snowball).
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
            'organization' => 'organiz', 'information' => 'inform',
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
            "Stemmer divergences found:\n" . implode("\n", $divergences),
        );
    }

    /**
     * Guard the modern (post-3.0) Snowball direction of the backend.
     *
     * Each of these words stems differently under the old (pre-3.0)
     * algorithms wamania/php-stemmer implemented, so any one of them failing
     * means the backend regressed to old-Snowball behaviour — e.g. a silent
     * regeneration from the wrong snowball revision (see
     * src/Index/Snowball/PROVENANCE.md). The old stem is noted per word.
     */
    public function testModernSnowballDirection(): void
    {
        $modern = [
            'en' => [
                'added' => 'add',           // old: ad
                'organic' => 'organic',     // old: organ
                'evening' => 'evening',     // old: even
                'paste' => 'paste',         // old: past
                'internal' => 'internal',   // old: intern
                'interval' => 'interval',   // old: interv
                'emergency' => 'emergenc',  // old: emerg
                'geologist' => 'geolog',    // old: geologist
                'skis' => 'ski',            // old: skis-related exception differs
            ],
            'fr' => [
                'aiguë' => 'aigu',          // old: aiguë
            ],
            'de' => [
                'abgebildet' => 'abgebild', // old: abgebildet
            ],
            'es' => [
                'constitucion' => 'constitu', // old: constitucion
            ],
            'ru' => [
                'актёр' => 'актер',         // old never folds ё→е
            ],
        ];

        foreach ($modern as $lang => $pairs) {
            $stemmer = new Stemmer($lang);
            foreach ($pairs as $word => $expected) {
                $this->assertSame(
                    $expected,
                    $stemmer->stem($word),
                    "Modern-Snowball tell '{$word}' ({$lang}) regressed — backend may have been regenerated from the wrong snowball revision",
                );
            }
        }
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
     * Every supported language must have a parity corpus, so no language can
     * ship without oracle coverage against pagefind_stem.
     */
    public function testEverySupportedLanguageHasCorpus(): void
    {
        foreach (Stemmer::getSupportedLanguages() as $lang) {
            $this->assertFileExists(
                __DIR__ . "/../fixtures/stemmer-corpus/{$lang}/words.txt",
                "Language '{$lang}' is in LANGUAGE_MAP but has no parity corpus",
            );
            $this->assertFileExists(
                __DIR__ . "/../fixtures/stemmer-corpus/{$lang}/expected-stems.txt",
                "Language '{$lang}' is in LANGUAGE_MAP but has no golden stems",
            );
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

        // 74.8% coverage (330 of 441 reference stems). The gap (111 stems):
        // - 53 path-derived number stems (01,02,...,099,110 from URL paths)
        // - ~42 compound-word stems (motherinlaw, stateoftheart, etc.)
        // - 3 CJK compound stems, 1 structural stem
        // - ~12 tokenization artifacts (em-dash joins, entity fragments)
        // These are architectural (tokenizer) differences — the component
        // words are in PHP's index. Stemming of shared words is identical;
        // the per-language corpus tests above prove byte-parity.
        $this->assertGreaterThanOrEqual(
            0.748,
            $coverage,
            sprintf(
                "Stemmer coverage: %.1f%% of reference stems found in PHP.\n"
                . "Reference: %d stems, PHP: %d stems, shared: %d.\n"
                . 'In ref but not PHP (sample): %s',
                $coverage * 100,
                $refCount,
                count($phpStemSet),
                $intersection,
                implode(', ', array_slice(array_diff($refStemSet, $phpStemSet), 0, 20)),
            ),
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

    /**
     * The hard parity gate: the PHP stemmer must reproduce Pagefind's own
     * stems (pagefind_stem 1.0.0, via tools/stemmer-golden) byte-for-byte
     * over the full corpus of every supported language. Zero tolerance —
     * any divergent word is a query that silently misses documents.
     *
     * @dataProvider largeCorpusProvider
     */
    public function testStemmerMatchesPagefindStem(string $lang): void
    {
        $wordsFile = __DIR__ . "/../fixtures/stemmer-corpus/{$lang}/words.txt";
        $expectedFile = __DIR__ . "/../fixtures/stemmer-corpus/{$lang}/expected-stems.txt";

        $words = file($wordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $expected = file($expectedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($words, "Missing corpus for {$lang}");
        $this->assertNotFalse($expected, "Missing golden stems for {$lang}");
        $this->assertCount(count($words), $expected, "Corpus alignment mismatch for {$lang}");

        $stemmer = new Stemmer($lang);
        $mismatches = [];
        $total = count($words);

        for ($i = 0; $i < $total; $i++) {
            $actual = $stemmer->stem($words[$i]);
            if ($actual !== $expected[$i]) {
                $mismatches[] = sprintf("'%s' → '%s' (expected '%s')", $words[$i], $actual, $expected[$i]);
            }
        }

        fwrite(STDERR, sprintf(
            "[stemmer] %s: %d/%d words match (%d mismatches)\n",
            strtoupper($lang),
            $total - count($mismatches),
            $total,
            count($mismatches),
        ));

        $this->assertSame(
            [],
            array_slice($mismatches, 0, 10),
            sprintf(
                'Stemmer parity for %s: %d of %d words diverge from pagefind_stem (first 10 shown)',
                strtoupper($lang),
                count($mismatches),
                $total,
            ),
        );
    }

    /** @return array<string, string[]> */
    public static function largeCorpusProvider(): array
    {
        $cases = [];
        foreach (Stemmer::getSupportedLanguages() as $lang) {
            $cases[$lang] = [$lang];
        }

        return $cases;
    }
}
