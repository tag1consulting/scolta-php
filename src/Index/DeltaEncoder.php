<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Delta-encode page numbers and positions with weight markers.
 *
 * Delta encoding stores differences between consecutive sorted values
 * instead of absolute values, significantly reducing encoded size.
 *
 * Weight markers signal changes in position weight groups using
 * negative values: -(weight + 1).
 */
class DeltaEncoder
{
    /** Default position weight (no marker emitted). */
    public const DEFAULT_WEIGHT = 25;

    /**
     * Delta-encode a sorted array of integers.
     *
     * Input:  [3, 7, 12, 15]
     * Output: [3, 4, 5, 3]
     *
     * First value is absolute; subsequent are differences.
     *
     * @param int[] $values Sorted ascending integers.
     * @return int[] Delta-encoded values.
     */
    public static function deltaEncode(array $values): array
    {
        if (count($values) === 0) {
            return [];
        }

        $result = [$values[0]];
        for ($i = 1, $count = count($values); $i < $count; $i++) {
            $result[] = $values[$i] - $values[$i - 1];
        }

        return $result;
    }

    /**
     * Encode positions grouped by weight with weight-change markers.
     *
     * Default weight (25) positions come first with no marker.
     * Weight changes are signaled by inserting -(weight + 1).
     * Positions within each weight group are delta-encoded.
     *
     * @param array<int, int[]> $positionsByWeight Weight => sorted positions.
     * @return int[] Encoded positions with weight markers.
     */
    public static function encodePositions(array $positionsByWeight): array
    {
        // Filter out empty weight groups.
        $positionsByWeight = array_filter($positionsByWeight, fn (array $positions) => count($positions) > 0);

        if (count($positionsByWeight) === 0) {
            return [];
        }

        $result = [];

        // Default weight first (no marker).
        if (isset($positionsByWeight[self::DEFAULT_WEIGHT])) {
            $result = self::deltaEncode($positionsByWeight[self::DEFAULT_WEIGHT]);
            unset($positionsByWeight[self::DEFAULT_WEIGHT]);
        }

        // Remaining weights with markers, sorted by weight.
        ksort($positionsByWeight);
        foreach ($positionsByWeight as $weight => $positions) {
            // Weight change marker: -(weight + 1).
            $result[] = -($weight + 1);
            // Delta-encode positions within this weight group.
            $deltaPositions = self::deltaEncode($positions);
            foreach ($deltaPositions as $pos) {
                $result[] = $pos;
            }
        }

        return $result;
    }
}
