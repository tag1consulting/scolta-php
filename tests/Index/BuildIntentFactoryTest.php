<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\MemoryBudget;

class BuildIntentFactoryTest extends TestCase
{
    public function testFromFlagsReturnsFreshWhenNeitherFlagSet(): void
    {
        $budget = MemoryBudget::conservative();
        $intent = BuildIntentFactory::fromFlags(false, false, 500, $budget);

        $this->assertSame('fresh', $intent->mode());
        $this->assertSame(500, $intent->totalPages());
    }

    public function testFromFlagsReturnsResumeWhenResumeFlagSet(): void
    {
        $budget = MemoryBudget::balanced();
        $intent = BuildIntentFactory::fromFlags(true, false, 500, $budget);

        $this->assertSame('resume', $intent->mode());
        $this->assertNull($intent->totalPages());
    }

    public function testFromFlagsReturnsRestartWhenRestartFlagSet(): void
    {
        $budget = MemoryBudget::aggressive();
        $intent = BuildIntentFactory::fromFlags(false, true, 500, $budget);

        $this->assertSame('restart', $intent->mode());
        $this->assertSame(500, $intent->totalPages());
    }

    public function testResumeTakesPrecedenceOverRestart(): void
    {
        $budget = MemoryBudget::conservative();
        $intent = BuildIntentFactory::fromFlags(true, true, 500, $budget);

        $this->assertSame('resume', $intent->mode());
    }

    public function testBudgetIsPassedThrough(): void
    {
        $budget = MemoryBudget::aggressive();
        $intent = BuildIntentFactory::fromFlags(false, false, 100, $budget);

        $this->assertSame('aggressive', $intent->memoryBudget()->profile());
    }
}
