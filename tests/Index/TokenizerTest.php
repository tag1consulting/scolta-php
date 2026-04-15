<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Tokenizer;

class TokenizerTest extends TestCase
{
    private Tokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new Tokenizer();
    }

    public function testBasicWords(): void
    {
        $tokens = $this->tokenizer->tokenize('Hello World');
        $stems = array_column($tokens, 'stem');
        $this->assertSame(['hello', 'world'], $stems);
    }

    public function testDiacriticNormalization(): void
    {
        $tokens = $this->tokenizer->tokenize('café');
        $this->assertCount(1, $tokens);
        $this->assertSame('cafe', $tokens[0]['stem']);
        $this->assertSame('café', $tokens[0]['original']);
    }

    public function testHyphenSplitting(): void
    {
        $tokens = $this->tokenizer->tokenize('mother-in-law');
        $stems = array_column($tokens, 'stem');
        $this->assertContains('mother', $stems);
        $this->assertContains('in', $stems);
        $this->assertContains('law', $stems);
    }

    public function testCamelCaseSplitting(): void
    {
        $tokens = $this->tokenizer->tokenize('myPage');
        $stems = array_column($tokens, 'stem');
        $this->assertContains('my', $stems);
        $this->assertContains('page', $stems);
    }

    public function testNumbers(): void
    {
        $tokens = $this->tokenizer->tokenize('123abc');
        $stems = array_column($tokens, 'stem');
        $this->assertContains('123abc', $stems);
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], $this->tokenizer->tokenize(''));
    }

    public function testWhitespaceOnly(): void
    {
        $this->assertSame([], $this->tokenizer->tokenize('   '));
    }

    public function testPositionTracking(): void
    {
        $tokens = $this->tokenizer->tokenize('hello world');
        $this->assertSame(0, $tokens[0]['position']);
        $this->assertSame(6, $tokens[1]['position']);
    }

    public function testStartPositionOffset(): void
    {
        $tokens = $this->tokenizer->tokenize('hello', 100);
        $this->assertSame(100, $tokens[0]['position']);
    }

    public function testPunctuationStripped(): void
    {
        $tokens = $this->tokenizer->tokenize('hello, world!');
        $stems = array_column($tokens, 'stem');
        $this->assertContains('hello', $stems);
        $this->assertContains('world', $stems);
    }

    public function testMultipleSpaces(): void
    {
        $tokens = $this->tokenizer->tokenize('hello   world');
        $stems = array_column($tokens, 'stem');
        $this->assertSame(['hello', 'world'], $stems);
    }

    public function testUnicodeLowercasing(): void
    {
        $tokens = $this->tokenizer->tokenize('ÜBER');
        $this->assertSame('uber', $tokens[0]['stem']);
    }

    public function testCjkSplitting(): void
    {
        // Bigram tokenization: 4 chars → 3 overlapping bigrams.
        $tokens = $this->tokenizer->tokenize('你好世界');
        $this->assertCount(3, $tokens);
        $stems = array_column($tokens, 'stem');
        $this->assertContains('你好', $stems);
        $this->assertContains('好世', $stems);
        $this->assertContains('世界', $stems);
    }

    public function testMixedContent(): void
    {
        $tokens = $this->tokenizer->tokenize('Hello café 123');
        $this->assertGreaterThanOrEqual(3, count($tokens));
    }
}
