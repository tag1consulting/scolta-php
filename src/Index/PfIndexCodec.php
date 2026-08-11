<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Read a `pf_index` chunk back into the term map the writer accepts.
 *
 * `StreamingFormatWriter::writeTerm()` takes `term => pageEntries` where a page
 * entry is `['positions' => [weight => int[]], 'meta_positions' => int[]]`, plus
 * an optional `_variants` key. This decodes a written chunk back into exactly
 * that shape, so a caller can mutate one ordinal's postings and hand the result
 * straight back to the writer.
 *
 * The round trip is exact, not approximate. Two details make it so:
 *
 *  - `encodeWordEntry()` emits one marker per weight bucket, heaviest first,
 *    and `decodePositionBuckets()` splits them back apart on those markers, so
 *    the per-weight structure survives the file rather than being flattened
 *    into it. Buckets are sorted before encoding, which is what makes a
 *    negative value unambiguously a marker rather than a delta.
 *  - Page numbers and positions are delta-encoded on the way out and are
 *    restored by running sums here, restarting at each marker.
 *
 * Verified against 25 chunks of a real 109,308-page index (2,071 word entries):
 * decode followed by re-encode reproduced the original bytes for all 25. Those
 * chunks predate multi-bucket entries and so exercise only the single-bucket
 * case; `AttachmentTextTest` covers the round trip with two buckets on a term.
 *
 * @since 1.2.0
 * @stability experimental
 */
final class PfIndexCodec
{
    /** Body-weight marker written by encodeWordEntry(). */
    private const BODY_WEIGHT = 25;

    /**
     * Decode a chunk's CBOR body into an ordered term map.
     *
     * Term order is preserved exactly as stored, because the chunk's filename
     * is `hash10(implode(',', words))` — reordering the map would rename the
     * file even when nothing about its content changed.
     *
     * @param string $cborBody The chunk bytes with the gzip and delimiter already stripped.
     * @return array<string, array<int|string, mixed>> term => pageEntries, in file order.
     * @since 1.2.0
     * @stability experimental
     */
    public static function decodeChunk(string $cborBody): array
    {
        $outer = CborDecoder::decode($cborBody);
        if (!is_array($outer) || !isset($outer[0]) || !is_array($outer[0])) {
            throw new \RuntimeException('Malformed pf_index chunk: expected a one-element array of word entries.');
        }

        $terms = [];
        foreach ($outer[0] as $wordEntry) {
            if (!is_array($wordEntry) || count($wordEntry) !== 3) {
                throw new \RuntimeException('Malformed pf_index word entry: expected [word, pages, variants].');
            }
            [$word, $pages, $variants] = $wordEntry;
            $terms[(string) $word]     = self::decodePageEntries($pages, $variants);
        }

        return $terms;
    }

    /**
     * Read and decode a chunk file.
     *
     * @return array<string, array<int|string, mixed>>
     * @since 1.2.0
     * @stability experimental
     */
    public static function decodeChunkFile(string $path): array
    {
        $compressed = @file_get_contents($path);
        if ($compressed === false) {
            throw new \RuntimeException("Cannot read pf_index chunk: {$path}");
        }
        $raw = @gzdecode($compressed);
        if ($raw === false) {
            throw new \RuntimeException("Cannot decompress pf_index chunk: {$path}");
        }
        if (str_starts_with($raw, 'pagefind_dcd')) {
            $raw = substr($raw, strlen('pagefind_dcd'));
        }

        return self::decodeChunk($raw);
    }

    /**
     * The word list of a decoded chunk, in the order that names the file.
     *
     * This is the single normalisation point for chunk identity: the filename
     * is derived from this list and from nothing else, here and in
     * `StreamingFormatWriter::flushIndexChunk()`. Two callers computing the
     * order differently would fragment chunk names rather than raise an error,
     * which is why it has one home.
     *
     * @param array<string, mixed> $terms
     * @return list<string>
     * @since 1.2.0
     * @stability experimental
     */
    public static function wordList(array $terms): array
    {
        // PHP converts a numeric-looking array key to an int, so a term like
        // "2024" comes back from array_keys() as int 2024. Every consumer here
        // wants a string — pf_meta[2] encodes the range bounds as CBOR text,
        // and the chunk hash joins them — so the cast happens once, here,
        // rather than at each call site where forgetting it is a TypeError at
        // best and a wrong chunk name at worst.
        return array_map(strval(...), array_keys($terms));
    }

    /**
     * The filename a chunk holding these terms and this body must have.
     *
     * The body is part of the name because the file is served from a static
     * directory a cache sits in front of; see {@see IndexFileNaming}.
     *
     * @param array<string, mixed> $terms
     * @param string               $body  The encoded chunk body, before compression.
     * @since 1.2.0
     * @stability experimental
     */
    public static function chunkHash(array $terms, string $body): string
    {
        return IndexFileNaming::chunkHash(self::wordList($terms), $body);
    }

    /**
     * Encode a whole chunk body from a term map.
     *
     * The inverse of decodeChunk(), and the only place chunk bytes are
     * assembled: `StreamingFormatWriter::flushIndexChunk()` calls through here
     * rather than keeping its own copy, so the two directions cannot drift.
     *
     * @param array<string, array<int|string, mixed>> $terms term => pageEntries, in the order that names the file.
     * @since 1.2.0
     * @stability experimental
     */
    public static function encodeChunk(CborEncoder $cbor, array $terms): string
    {
        $encoded = [];
        foreach ($terms as $word => $pageEntries) {
            $encoded[] = self::encodeWordEntry($cbor, (string) $word, $pageEntries);
        }

        return $cbor->encodeArray([$cbor->encodeArray($encoded)]);
    }

    /**
     * Encode weight buckets as one CBOR item list: a marker, then that
     * bucket's positions delta-encoded from its own first position.
     *
     * The single definition of the on-disk bucket layout. `PfIndexCodec` and
     * `PagefindFormatWriter` both write it, and nothing compares their output
     * — the round-trip test exercises the codec, the E2E test exercises the
     * writer — so two copies could drift into emitting different bytes for the
     * same index without either test noticing.
     *
     * Buckets are emitted heaviest-first, and each is sorted before encoding
     * so every delta within it is non-negative. That is what lets a decoder
     * treat a negative value as unambiguously the next marker, and it is why a
     * page carrying only body positions produces the exact bytes it did before
     * more than one bucket was possible.
     *
     * @param array<int|string, list<int>> $positionsByWeight Marker magnitude => positions.
     * @return list<string> CBOR-encoded items, ready to wrap in an array.
     * @since 1.2.1
     * @stability experimental
     */
    public static function encodePositionBuckets(CborEncoder $cbor, array $positionsByWeight): array
    {
        $buckets = [];
        foreach ($positionsByWeight as $marker => $positions) {
            $positions = array_map(intval(...), $positions);
            sort($positions);
            if ($positions !== []) {
                $buckets[(int) $marker] = $positions;
            }
        }
        krsort($buckets, SORT_NUMERIC);

        $items = [];
        foreach ($buckets as $marker => $positions) {
            $items[] = $cbor->encodeNegInt(-$marker);
            foreach (DeltaEncoder::deltaEncode($positions) as $dp) {
                $items[] = $dp >= 0 ? $cbor->encodeUint($dp) : $cbor->encodeNegInt($dp);
            }
        }

        return $items;
    }

    /**
     * Encode a single word entry as CBOR.
     *
     * Weight buckets are emitted one marker each by encodePositionBuckets(),
     * heaviest first, and the meta list is delta-encoded behind its own field
     * marker. decodePositionBuckets() splits them back apart on those markers,
     * which is what makes the round trip exact rather than approximate.
     *
     * @param array<int|string, mixed> $pageEntries
     * @since 1.2.0
     * @stability experimental
     */
    public static function encodeWordEntry(CborEncoder $cbor, string $word, array $pageEntries): string
    {
        $variants = $pageEntries['_variants'] ?? [];
        unset($pageEntries['_variants']);

        // Ordinals come back from array_keys() as int|string depending on how
        // PHP stored the key; the format needs ints.
        $pageNums = array_map(intval(...), array_keys($pageEntries));
        sort($pageNums, SORT_NUMERIC);
        $deltaPages = DeltaEncoder::deltaEncode($pageNums);

        $encodedPages = [];
        foreach ($pageNums as $idx => $pageNum) {
            $entry     = $pageEntries[$pageNum];
            $pageItems = [$cbor->encodeUint($deltaPages[$idx])];

            $posItems = self::encodePositionBuckets($cbor, $entry['positions']);
            $pageItems[] = $cbor->encodeArray($posItems);

            $metaPositions = $entry['meta_positions'] ?? [];
            $metaItems     = [];
            if (!empty($metaPositions)) {
                sort($metaPositions);
                $metaItems[] = $cbor->encodeNegInt(-1); // title field marker (index 0)
                foreach (DeltaEncoder::deltaEncode($metaPositions) as $mp) {
                    $metaItems[] = $mp >= 0 ? $cbor->encodeUint($mp) : $cbor->encodeNegInt($mp);
                }
            }
            $pageItems[] = $cbor->encodeArray($metaItems);

            $encodedPages[] = $cbor->encodeArray($pageItems);
        }

        $encodedVariants = [];
        foreach ($variants as $form => $variantPages) {
            $variantPageEntries = [];
            foreach ($variantPages as $vp) {
                $variantPageEntries[] = $cbor->encodeArray([
                    $cbor->encodeUint($vp),
                    $cbor->encodeArray([]),
                    $cbor->encodeArray([]),
                ]);
            }
            $encodedVariants[] = $cbor->encodeArray([
                $cbor->encodeString((string) $form),
                $cbor->encodeArray($variantPageEntries),
            ]);
        }

        return $cbor->encodeArray([
            $cbor->encodeString($word),
            $cbor->encodeArray($encodedPages),
            $cbor->encodeArray($encodedVariants),
        ]);
    }

    /**
     * Rebuild the page-entry map for one word entry.
     *
     * @param list<mixed> $pages
     * @param list<mixed> $variants
     * @return array<int|string, mixed>
     */
    private static function decodePageEntries(array $pages, array $variants): array
    {
        $entries = [];
        $pageNum = 0;

        foreach ($pages as $pageEntry) {
            if (!is_array($pageEntry) || count($pageEntry) !== 3) {
                throw new \RuntimeException('Malformed pf_index page entry: expected [delta, locs, meta_locs].');
            }
            [$delta, $locs, $metaLocs] = $pageEntry;
            $pageNum += (int) $delta;

            // Both position lists start with a field marker (-25 body,
            // -13 attachment, -1 title) that is not a position. Its absence
            // means an empty list.
            $entries[$pageNum] = [
                'positions'      => self::decodePositionBuckets($locs),
                'meta_positions' => self::decodePositions($metaLocs),
            ];
        }

        if ($variants !== []) {
            $decoded = [];
            foreach ($variants as $variantEntry) {
                if (!is_array($variantEntry) || count($variantEntry) !== 2) {
                    throw new \RuntimeException('Malformed pf_index variant entry: expected [form, pages].');
                }
                [$form, $variantPages] = $variantEntry;
                // Variant page numbers are written absolute, not delta-encoded.
                $decoded[(string) $form] = array_map(
                    static fn(array $vp): int => (int) $vp[0],
                    $variantPages,
                );
            }
            $entries['_variants'] = $decoded;
        }

        return $entries;
    }

    /**
     * Split a position list into its weight buckets.
     *
     * Positions inside a bucket are sorted before encoding, so every delta in
     * one is non-negative and any negative value is unambiguously the next
     * bucket's marker. Pagefind's own decoder relies on the same property.
     * Each bucket delta-encodes from its own first position, so the
     * accumulator resets at every marker.
     *
     * @param list<mixed> $locs
     * @return array<int, list<int>> Weight => absolute positions.
     */
    private static function decodePositionBuckets(array $locs): array
    {
        $buckets = [];
        $weight  = self::BODY_WEIGHT;
        $acc     = 0;
        $atStart = true;

        foreach ($locs as $value) {
            $value = (int) $value;
            if ($value < 0) {
                $weight  = -$value;
                $acc     = 0;
                $atStart = true;
                continue;
            }
            $acc               = $atStart ? $value : $acc + $value;
            $atStart           = false;
            $buckets[$weight][] = $acc;
        }

        return $buckets;
    }

    /**
     * Strip the field marker and run the deltas back into absolute positions.
     *
     * @param list<mixed> $locs
     * @return list<int>
     */
    private static function decodePositions(array $locs): array
    {
        if ($locs === []) {
            return [];
        }

        array_shift($locs);

        $positions = [];
        $acc       = 0;
        foreach ($locs as $delta) {
            $acc        += (int) $delta;
            $positions[] = $acc;
        }

        return $positions;
    }
}
