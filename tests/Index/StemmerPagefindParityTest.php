<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Stemmer;

/**
 * Stemmer ⇄ Pagefind parity guard.
 *
 * Pagefind stems *queries* at runtime with its bundled WASM — the rust-stemmers
 * crate, i.e. the old (pre-Snowball-3.x) Porter2 English algorithm. A scolta-php
 * built index is only searchable if its build-time stems match those runtime
 * query stems exactly.
 *
 * wamania/php-stemmer v3.0.1 already produces that old-Porter2 output (its own
 * vendor/wamania/php-stemmer/test/files/en.txt asserts added->ad, organic->organ,
 * evening->even, pasted->past). This test is a guard, not a behaviour change: it
 * locks that parity so a future wamania bump to a new-Porter2 revision cannot
 * silently move the index off Pagefind compatibility. Every CORRECT pair below
 * differs from snowballstemmer 3.x output, so such a regression would turn this
 * red rather than passing unnoticed.
 */
class StemmerPagefindParityTest extends TestCase
{
    /**
     * Words whose old-Porter2 stem (Pagefind / rust-stemmers / wamania v3.0.1)
     * differs from snowballstemmer 3.x. word => old-Porter2 stem.
     */
    private const PAGEFIND_DIVERGENT = [
        'added' => 'ad',
        'adding' => 'ad',
        'erred' => 'er',
        'egged' => 'eg',
        'offing' => 'of',
        'organic' => 'organ',
        'evening' => 'even',
        'paste' => 'past',
        'pasted' => 'past',
        'pasting' => 'past',
        'lateral' => 'later',
        'vying' => 'vy',
        'acarologist' => 'acarologist',
    ];

    /**
     * Control words: identical under old and new Porter2. They prove the stemmer
     * is still doing real work (not just echoing the input) on the pinned version.
     */
    private const CONTROL = [
        'running' => 'run',
        'fruitlessly' => 'fruitless',
        'generously' => 'generous',
        'national' => 'nation',
        'communism' => 'communism',
    ];

    public function testPagefindDivergentWordsUseOldPorter2(): void
    {
        $stemmer = new Stemmer('en');
        $mismatches = [];
        foreach (self::PAGEFIND_DIVERGENT as $word => $expected) {
            $actual = $stemmer->stem($word);
            if ($actual !== $expected) {
                $mismatches[] = sprintf("'%s' => '%s' (expected '%s')", $word, $actual, $expected);
            }
        }

        $this->assertEmpty(
            $mismatches,
            "Stemmer drifted off old Porter2 (Pagefind) — did wamania/php-stemmer move "
            . "to a new-Porter2 revision?\n" . implode("\n", $mismatches)
        );
    }

    public function testControlWordsStemIdenticallyInBothAlgorithms(): void
    {
        $stemmer = new Stemmer('en');
        foreach (self::CONTROL as $word => $expected) {
            $this->assertSame($expected, $stemmer->stem($word));
        }
    }

    public function testAddedStemsToAdNotAdd(): void
    {
        // The canonical tell: old Porter2 -> 'ad', snowballstemmer 3.x -> 'add'.
        $this->assertSame('ad', (new Stemmer('en'))->stem('added'));
    }

    public function testOrganicStemsToOrganNotOrganic(): void
    {
        // old Porter2 -> 'organ', snowballstemmer 3.x leaves it as 'organic'.
        $this->assertSame('organ', (new Stemmer('en'))->stem('organic'));
    }
}
