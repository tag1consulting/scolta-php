<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Token;

/**
 * @since 1.1.0
 * @stability experimental
 */
class TokenTest extends TestCase
{
    public function testPropertiesAreReadable(): void
    {
        $token = new Token('hello', 'Hello', 42);
        $this->assertSame('hello', $token->stem);
        $this->assertSame('Hello', $token->original);
        $this->assertSame(42, $token->position);
    }

    public function testTokenUsesLessMemoryThanEquivalentArray(): void
    {
        gc_collect_cycles();
        $before = memory_get_usage();
        $objects = [];
        for ($i = 0; $i < 10000; $i++) {
            $objects[] = new Token('stem' . $i, 'original' . $i, $i);
        }
        $objectMem = memory_get_usage() - $before;

        unset($objects);
        gc_collect_cycles();

        $before = memory_get_usage();
        $arrays = [];
        for ($i = 0; $i < 10000; $i++) {
            $arrays[] = ['stem' => 'stem' . $i, 'original' => 'original' . $i, 'position' => $i];
        }
        $arrayMem = memory_get_usage() - $before;

        // Token objects should use at most 60% of equivalent array memory.
        $this->assertLessThan(
            $arrayMem * 0.6,
            $objectMem,
            sprintf(
                'Token objects used %d bytes vs arrays %d bytes (%.1f%%)',
                $objectMem,
                $arrayMem,
                $objectMem / $arrayMem * 100
            )
        );
    }
}
