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
     * @param string|null $hmacSecret  HMAC key; null disables integrity tagging.
     * @throws \RuntimeException on I/O failure.
     */
    public function write(string $path, array $partial, ?string $hmacSecret = null): void
    {
        $pages = $partial['pages'] ?? [];
        $index = $partial['index'] ?? [];

        // Terms must be sorted alphabetically for the streaming merge.
        ksort($index);

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
            }

            // End-of-records sentinel: a 4-byte zero length is impossible for
            // a real record, so this is unambiguous even without a separate count.
            fwrite($fp, "\x00\x00\x00\x00");

            $hmac   = $hmacCtx !== null ? hash_final($hmacCtx) : '';
            $footer = json_encode(['hmac' => $hmac]);
            fwrite($fp, $footer . "\n");
        } finally {
            fclose($fp);
        }
    }
}
