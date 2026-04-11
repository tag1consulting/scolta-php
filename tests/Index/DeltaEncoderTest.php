<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\DeltaEncoder;

class DeltaEncoderTest extends TestCase
{
    public function testDeltaEncodeBasic(): void
    {
        $this->assertSame([3, 4, 5, 3], DeltaEncoder::deltaEncode([3, 7, 12, 15]));
    }

    public function testDeltaEncodeEmpty(): void
    {
        $this->assertSame([], DeltaEncoder::deltaEncode([]));
    }

    public function testDeltaEncodeSingle(): void
    {
        $this->assertSame([42], DeltaEncoder::deltaEncode([42]));
    }

    public function testDeltaEncodeConsecutive(): void
    {
        $this->assertSame([1, 1, 1], DeltaEncoder::deltaEncode([1, 2, 3]));
    }

    public function testEncodePositionsDefaultWeightOnly(): void
    {
        $result = DeltaEncoder::encodePositions([
            25 => [5, 20, 35],
        ]);
        $this->assertSame([5, 15, 15], $result);
    }

    public function testEncodePositionsMultipleWeights(): void
    {
        $result = DeltaEncoder::encodePositions([
            25 => [5, 20, 35],
            50 => [10, 15],
        ]);
        $this->assertSame([5, 15, 15, -51, 10, 5], $result);
    }

    public function testEncodePositionsEmpty(): void
    {
        $this->assertSame([], DeltaEncoder::encodePositions([]));
    }

    public function testEncodePositionsEmptyWeightGroupFiltered(): void
    {
        $result = DeltaEncoder::encodePositions([
            25 => [5],
            50 => [],
        ]);
        $this->assertSame([5], $result);
    }

    public function testEncodePositionsNonDefaultWeightOnly(): void
    {
        $result = DeltaEncoder::encodePositions([
            50 => [10, 15],
        ]);
        $this->assertSame([-51, 10, 5], $result);
    }

    public function testEncodePositionsSinglePositionPerWeight(): void
    {
        $result = DeltaEncoder::encodePositions([
            25 => [100],
            75 => [200],
        ]);
        $this->assertSame([100, -76, 200], $result);
    }

    public function testWeightMarkerCalculation(): void
    {
        // Weight 50 → marker -(50+1) = -51
        $result = DeltaEncoder::encodePositions([50 => [10]]);
        $this->assertSame([-51, 10], $result);
    }

    public function testMultipleNonDefaultWeightsSorted(): void
    {
        $result = DeltaEncoder::encodePositions([
            75 => [30],
            50 => [10],
        ]);
        // Should be sorted by weight: 50 first, then 75
        $this->assertSame([-51, 10, -76, 30], $result);
    }
}
