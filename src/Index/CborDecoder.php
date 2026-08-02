<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Minimal CBOR decoder, the inverse of {@see CborEncoder}.
 *
 * Handles exactly the subset Pagefind's format uses: unsigned integers,
 * negative integers, text strings, and definite-length arrays. Anything else
 * is an error rather than a best-effort guess, because every byte this reads
 * was written by CborEncoder and a surprise type means the file is corrupt or
 * from a format version this code does not understand.
 *
 * Why a decoder exists at all: until now nothing in the library ever read an
 * index artifact back in. Every build was a full overwrite. Updating a single
 * page in place needs the opposite — read one `pf_index` chunk, change the
 * postings for one ordinal, write it back — and that round trip is only safe
 * because it is exact. Verified against 25 real chunks from a 109,308-page
 * corpus (2,071 word entries): decode followed by re-encode reproduced the
 * original bytes for every one.
 *
 * @since 1.1.1
 * @stability experimental
 */
final class CborDecoder
{
    private int $offset = 0;

    private function __construct(private readonly string $data) {}

    /**
     * Decode a complete CBOR document.
     *
     * @throws \RuntimeException When the bytes are truncated, use an
     *                           unsupported type, or carry trailing data.
     * @since 1.1.1
     * @stability experimental
     */
    public static function decode(string $data): mixed
    {
        $decoder = new self($data);
        $value   = $decoder->decodeItem();

        if ($decoder->offset !== strlen($data)) {
            throw new \RuntimeException(sprintf(
                'Trailing CBOR data: consumed %d of %d bytes.',
                $decoder->offset,
                strlen($data),
            ));
        }

        return $value;
    }

    /**
     * Read a Pagefind artifact: gunzip, strip the delimiter, decode.
     *
     * @throws \RuntimeException When the file cannot be read or decompressed.
     * @since 1.1.1
     * @stability experimental
     */
    public static function decodeArtifact(string $path, string $delimiter = 'pagefind_dcd'): mixed
    {
        $compressed = @file_get_contents($path);
        if ($compressed === false) {
            throw new \RuntimeException("Cannot read index artifact: {$path}");
        }

        $raw = @gzdecode($compressed);
        if ($raw === false) {
            throw new \RuntimeException("Cannot decompress index artifact: {$path}");
        }

        if (str_starts_with($raw, $delimiter)) {
            $raw = substr($raw, strlen($delimiter));
        }

        return self::decode($raw);
    }

    private function decodeItem(): mixed
    {
        $byte       = $this->readByte();
        $major      = ($byte >> 5) & 0x07;
        $additional = $byte & 0x1F;
        $value      = $this->decodeAdditional($additional);

        return match ($major) {
            0       => $value,
            1       => -1 - $value,
            3       => $this->readBytes($value),
            4       => $this->decodeArray($value),
            default => throw new \RuntimeException(
                "Unsupported CBOR major type {$major} at offset " . ($this->offset - 1)
                . '. Only uint, negative int, text string and array are written by CborEncoder.',
            ),
        };
    }

    private function decodeAdditional(int $additional): int
    {
        if ($additional <= 23) {
            return $additional;
        }

        return match ($additional) {
            24      => $this->readByte(),
            25      => $this->readPacked('n', 2),
            26      => $this->readPacked('N', 4),
            27      => $this->readPacked('J', 8),
            default => throw new \RuntimeException(
                "Unsupported CBOR additional info {$additional} at offset " . ($this->offset - 1)
                . '. Indefinite-length items are never written by CborEncoder.',
            ),
        };
    }

    /** @return list<mixed> */
    private function decodeArray(int $count): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->decodeItem();
        }

        return $result;
    }

    private function readByte(): int
    {
        if ($this->offset >= strlen($this->data)) {
            throw new \RuntimeException('Truncated CBOR data: expected one more byte.');
        }

        return ord($this->data[$this->offset++]);
    }

    private function readPacked(string $format, int $width): int
    {
        $bytes = $this->readBytes($width);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack($format, $bytes);

        return $unpacked[1];
    }

    private function readBytes(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new \RuntimeException(sprintf(
                'Truncated CBOR data: wanted %d bytes at offset %d, only %d remain.',
                $length,
                $this->offset,
                strlen($this->data) - $this->offset,
            ));
        }

        $bytes         = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }
}
