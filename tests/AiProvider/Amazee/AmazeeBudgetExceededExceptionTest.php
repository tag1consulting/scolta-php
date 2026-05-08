<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;

class AmazeeBudgetExceededExceptionTest extends TestCase
{
    public function testIsRuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new AmazeeBudgetExceededException());
    }

    public function testMessageDescribesBudgetExhaustion(): void
    {
        $e = new AmazeeBudgetExceededException();
        $this->assertStringContainsStringIgnoringCase('budget', $e->getMessage());
    }

    public function testPreviousExceptionIsPreserved(): void
    {
        $prev = new \RuntimeException('original');
        $e = new AmazeeBudgetExceededException($prev);
        $this->assertSame($prev, $e->getPrevious());
    }
}
