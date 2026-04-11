<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Minimal CBOR encoder for Pagefind index format.
 *
 * Implements RFC 8949 major types 0-4 only (unsigned int, negative int,
 * text string, array). Always produces canonical (smallest) encoding.
 */
class CborEncoder
{
    /**
     * Encode a CBOR header byte(s) for the given major type and value.
     */
    private function head(int $major, int $val): string
    {
        $majorShifted = $major << 5;

        if ($val <= 23) {
            return pack('C', $majorShifted | $val);
        }
        if ($val <= 0xFF) {
            return pack('CC', $majorShifted | 24, $val);
        }
        if ($val <= 0xFFFF) {
            return pack('Cn', $majorShifted | 25, $val);
        }
        if ($val <= 0xFFFFFFFF) {
            return pack('CN', $majorShifted | 26, $val);
        }

        return pack('CJ', $majorShifted | 27, $val);
    }

    /**
     * Encode an unsigned integer (CBOR major type 0).
     */
    public function encodeUint(int $n): string
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('encodeUint requires non-negative integer');
        }

        return $this->head(0, $n);
    }

    /**
     * Encode a negative integer (CBOR major type 1).
     *
     * CBOR encodes -1 as value 0, -2 as value 1, etc.
     */
    public function encodeNegInt(int $n): string
    {
        if ($n >= 0) {
            throw new \InvalidArgumentException('encodeNegInt requires negative integer');
        }

        return $this->head(1, -1 - $n);
    }

    /**
     * Encode a UTF-8 text string (CBOR major type 3).
     */
    public function encodeString(string $s): string
    {
        return $this->head(3, strlen($s)) . $s;
    }

    /**
     * Encode an array of pre-encoded CBOR items (CBOR major type 4).
     *
     * @param string[] $items Already-encoded CBOR byte strings.
     */
    public function encodeArray(array $items): string
    {
        return $this->head(4, count($items)) . implode('', $items);
    }
}
