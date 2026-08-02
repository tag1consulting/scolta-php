<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Write a partial index chunk in the v2 streaming format.
 *
 * Format layout:
 *   {JSON header}\n
 *   [page records:  4-byte LE length + JSON payload]  × page_count
 *   [term records:  4-byte LE length + JSON payload]  × term_count  (sorted alphabetically)
 *   \x00\x00\x00\x00  (end-of-records sentinel)
 *   {JSON footer}\n
 *
 * Terms are sorted inside this method so ChunkReader::openIndex() yields
 * them in alphabetical order, which is required by the N-way streaming merge
 * in IndexMerger::mergeStreaming().
 */
class ChunkWriter
{
    /**
     * Write a partial index to disk in v2 format.
     *
     * @param string      $path        Destination file path.
     * @param array       $partial     Output of InvertedIndexBuilder::build().
     * @param string|null $hmacSecret  HMAC key. Null disables integrity tagging, and
     *                                 an empty or whitespace-only string means the
     *                                 same thing: callers that forward framework
     *                                 configuration get `''` rather than null when the
     *                                 operator has set no key, so both spellings of
     *                                 "unset" skip tagging instead of throwing.
     *                                 CRC32 is computed either way.
     * @throws \RuntimeException on I/O failure.
     * @since 1.0.0
     * @stability stable
     */
    public function write(string $path, array $partial, ?string $hmacSecret = null): void
    {
        $hmacSecret = HmacSecret::normalize($hmacSecret);

        $pages = $partial['pages'] ?? [];
        $index = $partial['index'] ?? [];

        // Terms must arrive at the streaming merge in the order that merge
        // compares them in, which is SplMinHeap's — PHP's standard comparison.
        //
        // ksort() with SORT_REGULAR is not that order on every PHP version. A
        // numeric-looking term ("41" from a title like "Part 41", or a url's
        // numeric suffix) becomes an INT array key, so this array has mixed
        // int and string keys, and the two orderings disagree:
        //
        //   PHP 8.1   ksort: alpha,beta,part,zulu,2,9,10,41   (strings first)
        //   PHP 8.2+  ksort: 2,9,10,41,alpha,beta,part,zulu   (ints first)
        //   SplMinHeap, every version: 2,9,10,41,alpha,…       (ints first)
        //
        // On 8.1 that broke IndexMerger's precondition that each chunk's term
        // stream is ascending. The merge groups equal terms by walking the heap
        // top, so an out-of-order stream lets one logical term be emitted more
        // than once and lands terms in the wrong pf_index chunk. It produced a
        // subtly wrong index rather than an error, and nothing noticed until
        // the round-trip and differential tests started comparing bytes.
        //
        // uksort with the same comparison the heap uses matches it on every
        // version, and is a no-op on 8.2+ — output there is byte-identical.
        uksort($index, static fn(int|string $a, int|string $b): int => $a <=> $b);

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open chunk file for writing: {$path}");
        }

        try {
            $pageCount = count($pages);
            $termCount = count($index);

            $header = json_encode(['v' => 2, 'page_count' => $pageCount, 'term_count' => $termCount]);
            fwrite($fp, $header . "\n");

            $hmacCtx = $hmacSecret !== null
                ? hash_init('sha256', HASH_HMAC, $hmacSecret)
                : null;

            // CRC32 is always computed regardless of hmacSecret; provides
            // corruption detection without a shared secret.
            $crcCtx = hash_init('crc32b');

            // Records use PHP serialize() rather than JSON to preserve integer
            // keys (page numbers, position weights) through the round-trip.
            foreach ($pages as $pageNum => $pageData) {
                $payload   = serialize([$pageNum, $pageData]);
                $lenPacked = pack('V', strlen($payload));
                fwrite($fp, $lenPacked);
                fwrite($fp, $payload);
                if ($hmacCtx !== null) {
                    hash_update($hmacCtx, $lenPacked);
                    hash_update($hmacCtx, $payload);
                }
                hash_update($crcCtx, $lenPacked);
                hash_update($crcCtx, $payload);
            }

            foreach ($index as $term => $termData) {
                $payload   = serialize([$term, $termData]);
                $lenPacked = pack('V', strlen($payload));
                fwrite($fp, $lenPacked);
                fwrite($fp, $payload);
                if ($hmacCtx !== null) {
                    hash_update($hmacCtx, $lenPacked);
                    hash_update($hmacCtx, $payload);
                }
                hash_update($crcCtx, $lenPacked);
                hash_update($crcCtx, $payload);
            }

            // End-of-records sentinel: a 4-byte zero length is impossible for
            // a real record, so this is unambiguous even without a separate count.
            fwrite($fp, "\x00\x00\x00\x00");

            $hmac   = $hmacCtx !== null ? hash_final($hmacCtx) : '';
            $crc32  = hash_final($crcCtx);
            $footer = json_encode(['hmac' => $hmac, 'crc32' => $crc32]);
            fwrite($fp, $footer . "\n");
        } finally {
            fclose($fp);
        }
    }
}
