<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Token;
use Tag1\Scolta\Index\Tokenizer;

/**
 * @since 1.0.0
 * @stability experimental
 */
class MemoryUsageTest extends TestCase
{
    public function testLargeCorpusTokenizationStaysWithinMemoryBudget(): void
    {
        $words = ['the', 'quick', 'brown', 'fox', 'jumps', 'over', 'lazy', 'dog',
            'and', 'then', 'runs', 'through', 'field', 'with', 'great', 'speed'];
        $text = '';
        for ($i = 0; $i < 20000; $i++) {
            $text .= $words[$i % count($words)] . ' ';
        }

        $tokenizer = new Tokenizer();

        gc_collect_cycles();
        $before = memory_get_usage();

        $tokens = $tokenizer->tokenize($text);

        $used = memory_get_usage() - $before;

        // 20,000 tokens at ~126 B/token ≈ 2.5 MB. Allow up to 5 MB for other allocations.
        $this->assertLessThan(
            5 * 1024 * 1024,
            $used,
            sprintf('Tokenizing 20k tokens used %.1f MB — expected under 5 MB', $used / 1024 / 1024),
        );
        $this->assertCount(20000, $tokens);
        $this->assertInstanceOf(Token::class, $tokens[0]);
    }
}
