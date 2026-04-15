<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Tokenizer;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Tokenizer;

/**
 * Unit tests for CJK bigram tokenization.
 *
 * @since 0.3.0
 * @stability experimental
 */
class CjkBigramTest extends TestCase
{
    private function stems(string $text): array
    {
        $tokenizer = new Tokenizer();

        return array_column($tokenizer->tokenize($text), 'stem');
    }

    public function testPureCjkFourChars(): void
    {
        $stems = $this->stems('人工智能');

        $this->assertContains('人工', $stems);
        $this->assertContains('工智', $stems);
        $this->assertContains('智能', $stems);

        // Single chars must NOT appear from a 4-char run.
        $this->assertNotContains('人', $stems);
        $this->assertNotContains('工', $stems);
        $this->assertNotContains('智', $stems);
        $this->assertNotContains('能', $stems);
    }

    public function testSingleCjkCharEmittedAlone(): void
    {
        $stems = $this->stems('猫');

        $this->assertSame(['猫'], $stems);
    }

    public function testMixedLatinCjkLatin(): void
    {
        $stems = $this->stems('Hello人工智能World');

        $this->assertContains('hello', $stems);
        $this->assertContains('人工', $stems);
        $this->assertContains('工智', $stems);
        $this->assertContains('智能', $stems);
        $this->assertContains('world', $stems);
    }

    public function testHiraganaBigrams(): void
    {
        $stems = $this->stems('おはよう');

        $this->assertContains('おは', $stems);
        $this->assertContains('はよ', $stems);
        $this->assertContains('よう', $stems);

        $this->assertNotContains('お', $stems);
        $this->assertNotContains('は', $stems);
        $this->assertNotContains('よ', $stems);
        $this->assertNotContains('う', $stems);
    }

    public function testKoreanBigrams(): void
    {
        $stems = $this->stems('안녕하세요');

        $this->assertContains('안녕', $stems);
        $this->assertContains('녕하', $stems);
        $this->assertContains('하세', $stems);
        $this->assertContains('세요', $stems);

        $this->assertNotContains('안', $stems);
        $this->assertNotContains('녕', $stems);
        $this->assertNotContains('하', $stems);
        $this->assertNotContains('세', $stems);
        $this->assertNotContains('요', $stems);
    }

    public function testTwoCjkChars(): void
    {
        $stems = $this->stems('日本');

        $this->assertContains('日本', $stems);
        $this->assertCount(1, $stems, 'Length-2 CJK run should produce exactly one bigram.');
    }

    public function testPureLatin(): void
    {
        $stems = $this->stems('hello world');

        $this->assertSame(['hello', 'world'], $stems);
    }

    public function testRussianUnaffected(): void
    {
        $stems = $this->stems('физика');

        $this->assertNotEmpty($stems, 'Cyrillic text should tokenize to at least one token.');

        // No bigrams — Cyrillic is not in CJK ranges.
        foreach ($stems as $stem) {
            $this->assertGreaterThan(
                1,
                mb_strlen($stem),
                'Cyrillic word should not produce single-char tokens via bigram logic.'
            );
        }
    }
}
