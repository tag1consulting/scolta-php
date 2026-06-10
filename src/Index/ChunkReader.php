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
    public function __construct(private readonly string $path) {}

    /**
     * Stream page records in insertion order.
     *
     * @return \Generator<int, array> Yields pageNum => pageData.
     * @throws \RuntimeException if the file is not in v2 format.
     * @throws \RuntimeException       on I/O failure.
     * @since 1.0.0
     * @stability stable
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
     * @throws \RuntimeException if the file is not in v2 format.
     * @throws \RuntimeException       on I/O failure.
     * @since 1.0.0
     * @stability stable
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
     * Verify both footer digests (HMAC and CRC32) in a single file read.
     *
     * verifyHmac() and verifyCrc32() were near-identical full-file passes;
     * callers needing both (BuildState::readChunk()) read every chunk twice.
     * This computes both digests over one pass of the record stream.
     *
     * Returns per-digest verdicts rather than throwing, so callers can treat
     * it as a soft check and report each failure distinctly:
     *   true  = digest present and verified
     *   false = mismatch (or any I/O/format error)
     *   null  = not applicable (no $hmacSecret supplied; footer carries no
     *           crc32 — pre-0.3.3 chunks, backward-compatible)
     *
     * @return array{hmac: bool|null, crc32: bool|null}
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function verifyFooterDigests(?string $hmacSecret = null): array
    {
        $failure = ['hmac' => $hmacSecret !== null ? false : null, 'crc32' => false];

        $fp = fopen($this->path, 'rb');
        if ($fp === false) {
            return $failure;
        }

        try {
            $this->readHeader($fp);

            $hmacCtx = $hmacSecret !== null ? hash_init('sha256', HASH_HMAC, $hmacSecret) : null;
            $crcCtx  = hash_init('crc32b');

            while (true) {
                $lenRaw = fread($fp, 4);
                if ($lenRaw === false || strlen($lenRaw) < 4) {
                    return $failure;
                }
                $len = unpack('V', $lenRaw)[1];
                if ($len === 0) {
                    break; // Sentinel — not included in the digests.
                }
                $payload = fread($fp, $len);
                if ($payload === false || strlen($payload) < $len) {
                    return $failure;
                }
                if ($hmacCtx !== null) {
                    hash_update($hmacCtx, $lenRaw);
                    hash_update($hmacCtx, $payload);
                }
                hash_update($crcCtx, $lenRaw);
                hash_update($crcCtx, $payload);
            }

            $line = fgets($fp);
            if ($line === false) {
                return $failure;
            }
            $footer = json_decode(trim($line), true);
            if (!is_array($footer)) {
                return $failure;
            }

            $hmacOk = null;
            if ($hmacCtx !== null) {
                $hmacOk = isset($footer['hmac'])
                    && hash_equals(hash_final($hmacCtx), (string) $footer['hmac']);
            }

            // No crc32 field → pre-0.3.3 chunk: not applicable, not a failure.
            $crcOk = isset($footer['crc32'])
                ? hash_equals(hash_final($crcCtx), (string) $footer['crc32'])
                : null;

            return ['hmac' => $hmacOk, 'crc32' => $crcOk];
        } catch (\Throwable) {
            return $failure;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Verify the HMAC stored in the file footer.
     *
     * Requires reading the entire file. Returns false on any I/O or format
     * error rather than throwing, so callers can treat it as a soft check.
     * Delegates to verifyFooterDigests(); use that directly when you also
     * need the CRC32 verdict, to avoid a second full-file read.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function verifyHmac(string $hmacSecret): bool
    {
        return $this->verifyFooterDigests($hmacSecret)['hmac'] === true;
    }

    /**
     * Verify the CRC32 checksum stored in the file footer.
     *
     * Reads all record bytes and compares against the `crc32` field written by
     * ChunkWriter. Pre-0.3.3 chunks have no `crc32` in the footer — this method
     * returns true for those (backward-compatible, no error).
     *
     * Returns false on any I/O or format error rather than throwing.
     * Delegates to verifyFooterDigests(); use that directly when you also
     * need the HMAC verdict, to avoid a second full-file read.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function verifyCrc32(): bool
    {
        return $this->verifyFooterDigests()['crc32'] !== false;
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
            throw new \RuntimeException(
                "Chunk is not in v2 streaming format (first byte is not '{'). "
                . "Delete the state directory and re-run a fresh build: {$this->path}",
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
