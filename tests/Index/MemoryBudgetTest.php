<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\MemoryBudget;

class MemoryBudgetTest extends TestCase
{
    public function testConservativeProfile(): void
    {
        $b = MemoryBudget::conservative();
        $this->assertSame('conservative', $b->profile());
        $this->assertSame(50, $b->chunkSize());
        $this->assertSame(40_000, $b->fragmentFlushBytes());
        $this->assertSame(40_000, $b->wordIndexChunkBytes());
        $this->assertSame(50, $b->mergeOpenFileHandles());
        $this->assertSame(96 * 1024 * 1024, $b->totalBudgetBytes());
    }

    public function testBalancedProfile(): void
    {
        $b = MemoryBudget::balanced();
        $this->assertSame('balanced', $b->profile());
        $this->assertSame(200, $b->chunkSize());
        $this->assertGreaterThan(40_000, $b->fragmentFlushBytes());
        $this->assertGreaterThan(96 * 1024 * 1024, $b->totalBudgetBytes());
    }

    public function testAggressiveProfile(): void
    {
        $b = MemoryBudget::aggressive();
        $this->assertSame('aggressive', $b->profile());
        $this->assertSame(500, $b->chunkSize());
        $this->assertGreaterThan(100_000, $b->fragmentFlushBytes());
        $this->assertGreaterThanOrEqual(1024 * 1024 * 1024, $b->totalBudgetBytes());
    }

    public function testDefaultIsConservative(): void
    {
        $this->assertSame('conservative', MemoryBudget::default()->profile());
    }

    /** @dataProvider fromBytesProvider */
    public function testFromBytes(int $bytes, string $expectedProfile): void
    {
        $this->assertSame($expectedProfile, MemoryBudget::fromBytes($bytes)->profile());
    }

    public static function fromBytesProvider(): array
    {
        return [
            'small (<192MB)'     => [64 * 1024 * 1024, 'conservative'],
            'medium (256MB)'     => [256 * 1024 * 1024, 'balanced'],
            'large (1GB)'        => [1024 * 1024 * 1024, 'aggressive'],
            'exactly 192MB edge' => [192 * 1024 * 1024, 'balanced'],
            'below 192MB'        => [191 * 1024 * 1024, 'conservative'],
            'exactly 768MB edge' => [768 * 1024 * 1024, 'aggressive'],
            'below 768MB'        => [767 * 1024 * 1024, 'balanced'],
        ];
    }

    /** @dataProvider fromStringProvider */
    public function testFromString(string $input, string $expectedProfile): void
    {
        $this->assertSame($expectedProfile, MemoryBudget::fromString($input)->profile());
    }

    public static function fromStringProvider(): array
    {
        return [
            'conservative name'  => ['conservative', 'conservative'],
            'balanced name'      => ['balanced', 'balanced'],
            'aggressive name'    => ['aggressive', 'aggressive'],
            'uppercase'          => ['CONSERVATIVE', 'conservative'],
            '256M bytes string'  => ['256M', 'balanced'],
            '1G bytes string'    => ['1G', 'aggressive'],
            'unknown string'     => ['unknown', 'conservative'],
        ];
    }

    public function testProfilesAreImmutable(): void
    {
        $a = MemoryBudget::conservative();
        $b = MemoryBudget::conservative();
        $this->assertNotSame($a, $b, 'Each factory call returns a new instance');
        $this->assertSame($a->chunkSize(), $b->chunkSize(), 'But values are equal');
    }

    public function testChunkSizeIncreasesByProfile(): void
    {
        $this->assertLessThan(
            MemoryBudget::balanced()->chunkSize(),
            MemoryBudget::conservative()->chunkSize(),
        );
        $this->assertLessThan(
            MemoryBudget::aggressive()->chunkSize(),
            MemoryBudget::balanced()->chunkSize(),
        );
    }

    public function testWithChunkSizeOverridesChunkSize(): void
    {
        $base    = MemoryBudget::conservative(); // chunkSize = 50
        $custom  = $base->withChunkSize(75);

        $this->assertSame(75, $custom->chunkSize());
        $this->assertSame(50, $base->chunkSize(), 'withChunkSize must not mutate the original');
    }

    public function testWithChunkSizePreservesOtherValues(): void
    {
        $base   = MemoryBudget::balanced();
        $custom = $base->withChunkSize(100);

        $this->assertSame($base->fragmentFlushBytes(), $custom->fragmentFlushBytes());
        $this->assertSame($base->wordIndexChunkBytes(), $custom->wordIndexChunkBytes());
        $this->assertSame($base->totalBudgetBytes(), $custom->totalBudgetBytes());
        $this->assertSame($base->profile(), $custom->profile());
    }

    public function testWithChunkSizeLargerThanProfileHandlesScalesUpHandles(): void
    {
        // Conservative has mergeOpenFileHandles = 50.
        // Requesting chunkSize = 300 should raise handles to at least 300.
        $custom = MemoryBudget::conservative()->withChunkSize(300);

        $this->assertSame(300, $custom->chunkSize());
        $this->assertGreaterThanOrEqual(300, $custom->mergeOpenFileHandles());
    }

    public function testWithChunkSizeSmallerThanProfileHandlesDoesNotReduceHandles(): void
    {
        // Conservative has mergeOpenFileHandles = 50.
        // A smaller chunk size (30) should keep handles at 50 (not drop to 30).
        $custom = MemoryBudget::conservative()->withChunkSize(30);

        $this->assertSame(30, $custom->chunkSize());
        $this->assertGreaterThanOrEqual(30, $custom->mergeOpenFileHandles());
    }

    public function testFromOptionsNamedProfileNoOverride(): void
    {
        // Explicit "unlimited" rather than whatever the runner's php.ini says:
        // fromOptions() caps a budget that would not fit the process, so a
        // profile-mapping assertion has to name the process it maps in.
        $b = MemoryBudget::fromOptions('balanced', null, processLimitBytes: 0);
        $this->assertSame('balanced', $b->profile());
        $this->assertSame(MemoryBudget::balanced()->chunkSize(), $b->chunkSize());
    }

    public function testFromOptionsWithChunkSizeOverride(): void
    {
        $b = MemoryBudget::fromOptions('conservative', 75);
        $this->assertSame('conservative', $b->profile());
        $this->assertSame(75, $b->chunkSize());
    }

    public function testFromOptionsByteStringWithChunkOverride(): void
    {
        $b = MemoryBudget::fromOptions('256M', 100, processLimitBytes: 0);
        $this->assertSame(100, $b->chunkSize());
        // 256M routes to balanced threshold — verify memory budget applied
        $this->assertGreaterThan(96 * 1024 * 1024, $b->totalBudgetBytes());
    }

    public function testFromOptionsNullChunkSizeUsesProfileDefault(): void
    {
        $b = MemoryBudget::fromOptions('aggressive', null, processLimitBytes: 0);
        $this->assertSame(MemoryBudget::aggressive()->chunkSize(), $b->chunkSize());
    }

    public function testFromOptionsZeroChunkSizeIsIgnored(): void
    {
        // 0 is not a valid chunk size; fromOptions must ignore it and keep the profile default.
        $b = MemoryBudget::fromOptions('conservative', 0, processLimitBytes: 0);
        $this->assertSame(MemoryBudget::conservative()->chunkSize(), $b->chunkSize());
    }

    // -------------------------------------------------------------------
    // The budget is the operator's number, and it fits the process
    // -------------------------------------------------------------------

    public function testAByteBudgetKeepsTheRequestedSizeInsteadOfRoundingToAProfile(): void
    {
        // `--memory-budget=48M` used to land on conservative() and run with its
        // 96 MB, so the number the operator chose had no effect at all.
        $b = MemoryBudget::fromBytes(48 * 1024 * 1024);

        $this->assertSame(48 * 1024 * 1024, $b->totalBudgetBytes());
        $this->assertLessThan(
            MemoryBudget::conservative()->chunkSize(),
            $b->chunkSize(),
            'A smaller budget must buy smaller chunks, not the conservative default',
        );
        $this->assertLessThan(
            MemoryBudget::conservative()->tokenCacheChunkBytes(),
            $b->tokenCacheChunkBytes(),
        );
    }

    public function testABudgetLargerThanTheProcessLimitDegradesInsteadOfFataling(): void
    {
        // `--memory-budget=4G` in a 512 MB process selected the aggressive
        // profile, whose 500-page chunks and 64 MB token-cache flush fatal in a
        // single allocation before the RSS watchdog can abort between chunks.
        $limit = 512 * 1024 * 1024;
        $b     = MemoryBudget::fromOptions('4G', null, processLimitBytes: $limit);

        $this->assertLessThan($limit, $b->totalBudgetBytes());
        $this->assertLessThan(MemoryBudget::aggressive()->chunkSize(), $b->chunkSize());
        $this->assertLessThan(MemoryBudget::aggressive()->tokenCacheChunkBytes(), $b->tokenCacheChunkBytes());
    }

    public function testABudgetThatFitsTheProcessIsLeftAlone(): void
    {
        $b = MemoryBudget::conservative()->withCeiling(512 * 1024 * 1024);

        $this->assertSame(MemoryBudget::conservative()->chunkSize(), $b->chunkSize());
        $this->assertSame(MemoryBudget::conservative()->totalBudgetBytes(), $b->totalBudgetBytes());
    }

    public function testAnUnlimitedProcessAppliesNoCeiling(): void
    {
        $b = MemoryBudget::aggressive()->withCeiling(0);

        $this->assertSame(MemoryBudget::aggressive()->totalBudgetBytes(), $b->totalBudgetBytes());
    }

    public function testScalingNeverProducesADegenerateChunk(): void
    {
        $b = MemoryBudget::conservative()->scaledTo(1);

        $this->assertGreaterThanOrEqual(10, $b->chunkSize());
        $this->assertGreaterThanOrEqual(1, $b->mergeOpenFileHandles());
        $this->assertGreaterThan(0, $b->tokenCacheChunkBytes());
    }
}
