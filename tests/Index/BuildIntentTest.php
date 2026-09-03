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

    /**
     * A reset empties the ledger, which would make the scoped-build guard in
     * IndexBuildOrchestrator (see PartialScopeBuildTest) find no rows outside
     * the scope and publish an index holding only the pages the run gathered.
     * Refused in both orders, because either order asks for the same thing.
     */
    public function testAPageTableResetAndAPartialScopeAreRefusedInBothOrders(): void
    {
        $partialFirst = BuildIntent::fresh(3, MemoryBudget::default())->withPartialScope();
        try {
            $partialFirst->withPageTableReset();
            $this->fail('A scoped build must not be allowed to renumber the page table.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('scope-limited build', $e->getMessage());
        }

        $resetFirst = BuildIntent::fresh(3, MemoryBudget::default())->withPageTableReset();
        try {
            $resetFirst->withPartialScope();
            $this->fail('A renumbering build must not be allowed to narrow its scope.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('scope-limited build', $e->getMessage());
        }
    }

    public function testARestartCannotBeNarrowedToAPartialScope(): void
    {
        $this->expectException(\LogicException::class);
        BuildIntent::restart(3, MemoryBudget::default())->withPartialScope();
    }

    public function testAPartialScopeSurvivesAnUnrelatedIntentCopy(): void
    {
        $intent = BuildIntent::fresh(3, MemoryBudget::default())->withPartialScope();

        $this->assertTrue($intent->isPartial());
        $this->assertSame(BuildIntent::SCOPE_PARTIAL, $intent->scope());
        $this->assertFalse($intent->resetsPageTable());
    }

    public function testAPageTableResetKeepsFullScope(): void
    {
        $intent = BuildIntent::fresh(3, MemoryBudget::default())->withPageTableReset();

        $this->assertTrue($intent->resetsPageTable());
        $this->assertFalse($intent->isPartial());
        $this->assertSame(BuildIntent::SCOPE_FULL, $intent->scope());
        $this->assertSame(BuildIntent::SCOPE_FULL, BuildIntent::restart(3, MemoryBudget::default())->scope());
    }

    public function testFreshAndRestartAreBothFresh(): void
    {
        $this->assertTrue(BuildIntent::fresh(1, MemoryBudget::default())->isFresh());
        $this->assertTrue(BuildIntent::restart(1, MemoryBudget::default())->isFresh());
        $this->assertFalse(BuildIntent::resume(MemoryBudget::default())->isFresh());
    }
}
