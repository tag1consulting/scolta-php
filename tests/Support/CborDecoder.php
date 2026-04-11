<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Support;

/**
 * Minimal CBOR decoder for reading Pagefind index files in tests.
 *
 * Handles only the CBOR types used by Pagefind:
 * - Major 0: unsigned integer
 * - Major 1: negative integer
 * - Major 2: byte string
 * - Major 3: text string
 * - Major 4: array
 * - Major 5: map
 *
 * This is test-only code — not used in production.
 */
class CborDecoder
{
    private string $data;
    private int $offset;

    /**
     * Decode a Pagefind .pf file (gzipped, with pagefind_dcd delimiter).
     *
     * @return mixed Decoded CBOR value.
     */
    public static function decodePfFile(string $filepath): mixed
    {
        $compressed = file_get_contents($filepath);
        if ($compressed === false) {
            throw new \RuntimeException("Cannot read file: {$filepath}");
        }

        $decompressed = gzdecode($compressed);
        if ($decompressed === false) {
            throw new \RuntimeException("Cannot decompress file: {$filepath}");
        }

        // Strip pagefind_dcd delimiter if present.
        if (str_starts_with($decompressed, 'pagefind_dcd')) {
            $decompressed = substr($decompressed, 12);
        }

        return self::decode($decompressed);
    }

    /**
     * Decode raw CBOR bytes.
     *
     * @return mixed Decoded value.
     */
    public static function decode(string $data): mixed
    {
        $decoder = new self($data);

        return $decoder->decodeItem();
    }

    private function __construct(string $data)
    {
        $this->data = $data;
        $this->offset = 0;
    }

    private function decodeItem(): mixed
    {
        if ($this->offset >= strlen($this->data)) {
            throw new \RuntimeException('Unexpected end of CBOR data');
        }

        $byte = ord($this->data[$this->offset]);
        $this->offset++;

        $major = ($byte >> 5) & 0x07;
        $additional = $byte & 0x1F;

        $value = $this->decodeAdditional($additional);

        return match ($major) {
            0 => $value,                          // unsigned int
            1 => -1 - $value,                     // negative int
            2 => $this->readBytes($value),        // byte string
            3 => $this->readBytes($value),        // text string (UTF-8)
            4 => $this->decodeArray($value),       // array
            5 => $this->decodeMap($value),         // map
            6 => $this->decodeTagged($value),      // tagged value
            7 => $this->decodeSimple($additional, $value), // simple/float
            default => throw new \RuntimeException("Unknown CBOR major type: {$major}"),
        };
    }

    private function decodeAdditional(int $additional): int
    {
        if ($additional <= 23) {
            return $additional;
        }

        return match ($additional) {
            24 => $this->readUint8(),
            25 => $this->readUint16(),
            26 => $this->readUint32(),
            27 => $this->readUint64(),
            default => throw new \RuntimeException("Unsupported CBOR additional info: {$additional}"),
        };
    }

    private function readUint8(): int
    {
        $val = ord($this->data[$this->offset]);
        $this->offset++;

        return $val;
    }

    private function readUint16(): int
    {
        $val = unpack('n', substr($this->data, $this->offset, 2));
        $this->offset += 2;

        return $val[1];
    }

    private function readUint32(): int
    {
        $val = unpack('N', substr($this->data, $this->offset, 4));
        $this->offset += 4;

        return $val[1];
    }

    private function readUint64(): int
    {
        $val = unpack('J', substr($this->data, $this->offset, 8));
        $this->offset += 8;

        return $val[1];
    }

    private function readBytes(int $length): string
    {
        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    private function decodeArray(int $count): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->decodeItem();
        }

        return $result;
    }

    private function decodeMap(int $count): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $this->decodeItem();
            $val = $this->decodeItem();
            $result[$key] = $val;
        }

        return $result;
    }

    private function decodeTagged(int $tag): mixed
    {
        // Just decode the tagged value and return it (ignore tag).
        return $this->decodeItem();
    }

    private function decodeSimple(int $additional, int $value): mixed
    {
        return match ($additional) {
            20 => false,
            21 => true,
            22 => null,
            25 => $this->decodeFloat16($value),
            26 => $this->decodeFloat32(),
            27 => $this->decodeFloat64(),
            default => $value,
        };
    }

    private function decodeFloat16(int $half): float
    {
        // Simple half-precision decode (rarely used in Pagefind).
        return (float) $half;
    }

    private function decodeFloat32(): float
    {
        // Back up — the value bytes were already consumed by decodeAdditional.
        // For float32, we need to re-read 4 bytes.
        $this->offset -= 4;
        $val = unpack('G', substr($this->data, $this->offset, 4));
        $this->offset += 4;

        return $val[1];
    }

    private function decodeFloat64(): float
    {
        $this->offset -= 8;
        $val = unpack('E', substr($this->data, $this->offset, 8));
        $this->offset += 8;

        return $val[1];
    }
}
