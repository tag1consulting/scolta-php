<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\MemoryBudget;

class BuildIntentTest extends TestCase
{
    public function testFreshMode(): void
    {
        $budget = MemoryBudget::conservative();
        $intent = BuildIntent::fresh(1000, $budget, ['language' => 'en']);

        $this->assertSame('fresh', $intent->mode());
        $this->assertSame(1000, $intent->totalPages());
        $this->assertSame('conservative', $intent->memoryBudget()->profile());
        $this->assertSame(['language' => 'en'], $intent->sourceMeta());
        $this->assertTrue($intent->isFresh());
    }

    public function testResumeMode(): void
    {
        $intent = BuildIntent::resume(MemoryBudget::balanced());

        $this->assertSame('resume', $intent->mode());
        $this->assertNull($intent->totalPages());
        $this->assertSame('balanced', $intent->memoryBudget()->profile());
        $this->assertFalse($intent->isFresh());
    }

    public function testRestartMode(): void
    {
        $intent = BuildIntent::restart(500, MemoryBudget::aggressive());

        $this->assertSame('restart', $intent->mode());
        $this->assertSame(500, $intent->totalPages());
        $this->assertTrue($intent->isFresh());
    }

    public function testSourceMetaDefaultsToEmpty(): void
    {
        $intent = BuildIntent::fresh(10, MemoryBudget::default());
        $this->assertSame([], $intent->sourceMeta());
    }

    public function testOnlyRestartResetsThePageTableByDefault(): void
    {
        $this->assertTrue(BuildIntent::restart(1, MemoryBudget::default())->resetsPageTable());
        $this->assertFalse(BuildIntent::fresh(1, MemoryBudget::default())->resetsPageTable());
        $this->assertFalse(BuildIntent::resume(MemoryBudget::default())->resetsPageTable());
    }

    public function testWithPageTableResetOptsAFreshBuildIn(): void
    {
        $intent = BuildIntent::fresh(7, MemoryBudget::balanced(), ['language' => 'de'])->withPageTableReset();

        $this->assertTrue($intent->resetsPageTable());
        $this->assertSame('fresh', $intent->mode());
        $this->assertSame(7, $intent->totalPages());
        $this->assertSame('balanced', $intent->memoryBudget()->profile());
        $this->assertSame(['language' => 'de'], $intent->sourceMeta());
    }

    public function testWithPageTableResetIsRefusedOnAResume(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/resumed build/');
        BuildIntent::resume(MemoryBudget::default())->withPageTableReset();
    }

    public function testFreshAndRestartAreBothFresh(): void
    {
        $this->assertTrue(BuildIntent::fresh(1, MemoryBudget::default())->isFresh());
        $this->assertTrue(BuildIntent::restart(1, MemoryBudget::default())->isFresh());
        $this->assertFalse(BuildIntent::resume(MemoryBudget::default())->isFresh());
    }
}
