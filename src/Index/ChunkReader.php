<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Read a v2 streaming chunk file produced by ChunkWriter.
 *
 * Provides two independent generators so the caller can stream pages and
 * index terms without loading the entire chunk into RAM.
 *
 * Each ChunkReader instance should be used for either openPages() or
 * openIndex() — not both simultaneously — since they each open their own
 * file handle and set internal state from the header.
 */
class ChunkReader
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Stream page records in insertion order.
     *
     * @return \Generator<int, array> Yields pageNum => pageData.
     * @throws OldChunkFormatException if the file uses the pre-0.2.5 format.
     * @throws \RuntimeException       on I/O failure.
     */
    public function openPages(): \Generator
    {
        $fp = fopen($this->path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open chunk file: {$this->path}");
        }

        try {
            ['page_count' => $pageCount] = $this->readHeader($fp);

            for ($i = 0; $i < $pageCount; $i++) {
                [$pageNum, $pageData] = $this->readRecord($fp, "page #{$i}");
                yield (int) $pageNum => $pageData;
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Stream index term records in alphabetical order.
     *
     * Skips the page section and yields [term, termData] pairs until the
     * end-of-records sentinel is reached.
     *
     * @return \Generator<int, array{0: string, 1: array}> Yields [term, termData].
     * @throws OldChunkFormatException if the file uses the pre-0.2.5 format.
     * @throws \RuntimeException       on I/O failure.
     */
    public function openIndex(): \Generator
    {
        $fp = fopen($this->path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open chunk file: {$this->path}");
        }

        try {
            ['page_count' => $pageCount] = $this->readHeader($fp);

            // Skip page records via length-prefix seek (no deserialization needed).
            for ($i = 0; $i < $pageCount; $i++) {
                $lenRaw = fread($fp, 4);
                if ($lenRaw === false || strlen($lenRaw) < 4) {
                    throw new \RuntimeException("Unexpected EOF skipping page #{$i} in: {$this->path}");
                }
                $len = unpack('V', $lenRaw)[1];
                if (fseek($fp, $len, SEEK_CUR) !== 0) {
                    throw new \RuntimeException("Seek failed skipping page #{$i} in: {$this->path}");
                }
            }

            // Read term records until the sentinel.
            while (true) {
                $lenRaw = fread($fp, 4);
                if ($lenRaw === false || strlen($lenRaw) < 4) {
                    break;
                }
                $len = unpack('V', $lenRaw)[1];
                if ($len === 0) {
                    break; // End-of-records sentinel.
                }

                $payload = fread($fp, $len);
                if ($payload === false || strlen($payload) < $len) {
                    throw new \RuntimeException("Truncated term record in: {$this->path}");
                }
                $record = unserialize($payload, ['allowed_classes' => false]);
                if (!is_array($record) || count($record) < 2) {
                    throw new \RuntimeException("Malformed term record in: {$this->path}");
                }
                yield [(string) $record[0], (array) $record[1]];
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Verify the HMAC stored in the file footer.
     *
     * Requires reading the entire file. Returns false on any I/O or format
     * error rather than throwing, so callers can treat it as a soft check.
     */
    public function verifyHmac(string $hmacSecret): bool
    {
        $fp = fopen($this->path, 'rb');
        if ($fp === false) {
            return false;
        }

        try {
            $this->readHeader($fp);

            $hmacCtx = hash_init('sha256', HASH_HMAC, $hmacSecret);

            while (true) {
                $lenRaw = fread($fp, 4);
                if ($lenRaw === false || strlen($lenRaw) < 4) {
                    return false;
                }
                $len = unpack('V', $lenRaw)[1];
                if ($len === 0) {
                    break; // Sentinel — not included in HMAC.
                }
                $payload = fread($fp, $len);
                if ($payload === false || strlen($payload) < $len) {
                    return false;
                }
                hash_update($hmacCtx, pack('V', $len));
                hash_update($hmacCtx, $payload);
            }

            $expected = hash_final($hmacCtx);

            $line = fgets($fp);
            if ($line === false) {
                return false;
            }
            $footer = json_decode(trim($line), true);

            return is_array($footer)
                && isset($footer['hmac'])
                && hash_equals($expected, (string) $footer['hmac']);
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Read and validate the chunk header line.
     *
     * @param resource $fp Open file handle positioned at start of file.
     * @return array{page_count: int, term_count: int}
     * @throws OldChunkFormatException if the file is not in v2 format.
     * @throws \RuntimeException       on I/O failure.
     */
    private function readHeader(mixed $fp): array
    {
        $line = fgets($fp);
        if ($line === false) {
            throw new \RuntimeException("Cannot read chunk header: {$this->path}");
        }

        $firstByte = $line[0] ?? '';
        if ($firstByte !== '{') {
            // Pre-0.2.5 chunks start with PHP serialized data ('a:', 'N;', etc.).
            throw new OldChunkFormatException(
                "Chunk uses pre-0.2.5 serialized format (not streamable): {$this->path}"
            );
        }

        $header = json_decode(trim($line), true);
        if (!is_array($header) || (int) ($header['v'] ?? 0) !== 2) {
            throw new \RuntimeException("Malformed or unsupported chunk header in: {$this->path}");
        }

        return [
            'page_count' => (int) ($header['page_count'] ?? 0),
            'term_count' => (int) ($header['term_count'] ?? 0),
        ];
    }

    /**
     * Read a single length-prefixed serialized record.
     *
     * @return array Unserialized value.
     */
    private function readRecord(mixed $fp, string $label): array
    {
        $lenRaw = fread($fp, 4);
        if ($lenRaw === false || strlen($lenRaw) < 4) {
            throw new \RuntimeException("Unexpected EOF reading {$label} in: {$this->path}");
        }
        $len = unpack('V', $lenRaw)[1];
        $payload = fread($fp, $len);
        if ($payload === false || strlen($payload) < $len) {
            throw new \RuntimeException("Truncated {$label} record in: {$this->path}");
        }
        $record = unserialize($payload, ['allowed_classes' => false]);
        if (!is_array($record)) {
            throw new \RuntimeException("Malformed {$label} record in: {$this->path}");
        }

        return $record;
    }
}
