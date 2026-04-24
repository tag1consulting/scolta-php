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
            MemoryBudget::conservative()->chunkSize()
        );
        $this->assertLessThan(
            MemoryBudget::aggressive()->chunkSize(),
            MemoryBudget::balanced()->chunkSize()
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
}
