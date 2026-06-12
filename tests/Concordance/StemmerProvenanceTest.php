<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Stemmer;

/**
 * Stemmer corpus drift guard.
 *
 * The stemmer corpus is the Pagefind query-stemmer oracle (generated from
 * pagefind_stem — see tests/fixtures/stemmer-corpus/PROVENANCE.md). This test
 * pins the fixtures to the sha256 manifest recorded in PROVENANCE.md, so a
 * silent re-baseline (e.g. regenerating against a different Pagefind stemmer
 * revision) fails CI until the manifest and the targeted-version table are
 * updated in the same commit. It is the cheap counterpart to the full-corpus
 * parity test: that one proves *the stemmer* still matches the oracle; this
 * one proves *the oracle fixtures themselves* have not moved without a paper
 * trail.
 */
class StemmerProvenanceTest extends TestCase
{
    private const CORPUS_DIR = __DIR__ . '/../fixtures/stemmer-corpus';

    /** @return array<string, array{words: string, stems: string}> */
    private static function manifest(): array
    {
        $provenance = file_get_contents(self::CORPUS_DIR . '/PROVENANCE.md');
        self::assertNotFalse($provenance, 'PROVENANCE.md missing from stemmer corpus');

        $rows = [];
        foreach (explode("\n", $provenance) as $line) {
            if (preg_match('/^\|\s*([a-z]{2})\s*\|\s*`([0-9a-f]{64})`\s*\|\s*`([0-9a-f]{64})`\s*\|/', $line, $m)) {
                $rows[$m[1]] = ['words' => $m[2], 'stems' => $m[3]];
            }
        }

        return $rows;
    }

    public function testManifestListsEverySupportedLanguage(): void
    {
        $manifest = self::manifest();
        $languages = Stemmer::getSupportedLanguages();
        sort($languages);
        $listed = array_keys($manifest);
        sort($listed);

        $this->assertSame(
            $languages,
            $listed,
            'PROVENANCE.md manifest must list exactly the languages in Stemmer::LANGUAGE_MAP',
        );
    }

    /**
     * @dataProvider languageProvider
     */
    public function testFixtureHashesMatchProvenance(string $lang): void
    {
        $expected = self::manifest()[$lang] ?? null;
        $this->assertNotNull($expected, "No manifest row for {$lang} in PROVENANCE.md");

        $this->assertSame(
            $expected['words'],
            hash_file('sha256', self::CORPUS_DIR . "/{$lang}/words.txt"),
            "{$lang}/words.txt changed without updating PROVENANCE.md",
        );
        $this->assertSame(
            $expected['stems'],
            hash_file('sha256', self::CORPUS_DIR . "/{$lang}/expected-stems.txt"),
            "{$lang}/expected-stems.txt changed without updating PROVENANCE.md — "
            . 'if you re-targeted a new Pagefind stemmer, update the version table too',
        );
    }

    /** @return array<string, string[]> */
    public static function languageProvider(): array
    {
        $cases = [];
        foreach (Stemmer::getSupportedLanguages() as $lang) {
            $cases[$lang] = [$lang];
        }

        return $cases;
    }
}
