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
     * @since 1.3.0
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
            $encodedPages[] = self::encodePageItem($cbor, $deltaPages[$idx], $pageEntries[$pageNum]);
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
     * Encode one page item of a word entry: `[delta, locs, meta_locs]`.
     *
     * The single definition of a posting's bytes. `encodeWordEntry()` emits a
     * whole entry through this and {@see self::patchEntry()} splices individual
     * items through it, so an inserted posting is byte-identical to the one a
     * full rebuild would have written. Two copies of this would be a
     * differential test that passes on the cases someone thought of.
     *
     * @param int                  $delta Page number minus the previous item's.
     * @param array<string, mixed> $entry `['positions' => ..., 'meta_positions' => ...]`.
     * @since 1.4.0
     * @stability experimental
     */
    public static function encodePageItem(CborEncoder $cbor, int $delta, array $entry): string
    {
        $pageItems = [$cbor->encodeUint($delta)];

        $posItems    = self::encodePositionBuckets($cbor, $entry['positions'] ?? []);
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

        return $cbor->encodeArray($pageItems);
    }

    /**
     * Split a chunk body into its word entries, without decoding any of them.
     *
     * Why this exists: an incremental update decodes a whole chunk, mutates one
     * or two entries, and re-encodes everything. On this corpus a common word's
     * entry holds around 100,000 postings, and the touched entries are 78% of a
     * touched chunk's bytes — so decoding "only what changed" at entry
     * granularity saves almost nothing, and the work has to stay inside the
     * entry. This is the first half of that: find each entry's bytes without
     * building a single PHP array from them.
     *
     * Measured on a 47 KB chunk: 1.0 ms here against 4.7 ms to decode it.
     *
     * @param string $cborBody The chunk bytes with gzip and delimiter stripped.
     * @return array<string, string> Word => that entry's raw CBOR, in file order.
     * @since 1.4.0
     * @stability experimental
     */
    public static function splitEntries(string $cborBody): array
    {
        $offset = 0;
        $outer  = self::readHead($cborBody, $offset);
        if ($outer['major'] !== 4 || $outer['value'] !== 1) {
            throw new \RuntimeException('Malformed pf_index chunk: expected a one-element array of word entries.');
        }

        $inner = self::readHead($cborBody, $offset);
        if ($inner['major'] !== 4) {
            throw new \RuntimeException('Malformed pf_index chunk: expected an array of word entries.');
        }

        $entries = [];
        for ($i = 0; $i < $inner['value']; $i++) {
            $start = $offset;

            $entryHead = self::readHead($cborBody, $offset);
            if ($entryHead['major'] !== 4 || $entryHead['value'] !== 3) {
                throw new \RuntimeException('Malformed pf_index word entry: expected [word, pages, variants].');
            }

            $wordHead = self::readHead($cborBody, $offset);
            if ($wordHead['major'] !== 3) {
                throw new \RuntimeException('Malformed pf_index word entry: expected a text word.');
            }
            $word    = substr($cborBody, $offset, $wordHead['value']);
            $offset += $wordHead['value'];

            self::skipItem($cborBody, $offset); // pages
            self::skipItem($cborBody, $offset); // variants

            $entries[$word] = substr($cborBody, $start, $offset - $start);
        }

        return $entries;
    }

    /**
     * Read a chunk file and split it into raw entries.
     *
     * The byte-level counterpart of {@see self::decodeChunkFile()}.
     *
     * @return array<string, string> Word => that entry's raw CBOR, in file order.
     * @since 1.4.0
     * @stability experimental
     */
    public static function splitEntriesFromFile(string $path): array
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

        return self::splitEntries($raw);
    }

    /**
     * Reassemble a chunk body from raw entries, in the order given.
     *
     * @param array<string, string> $rawEntries Word => raw CBOR entry.
     * @since 1.4.0
     * @stability experimental
     */
    public static function assembleChunk(CborEncoder $cbor, array $rawEntries): string
    {
        return $cbor->encodeArray([$cbor->encodeArray(array_values($rawEntries))]);
    }

    /**
     * Apply page-level changes to one word entry, at the byte level.
     *
     * The postings of a term are a delta-coded run, so a change in the middle
     * only disturbs the item it touches and the delta of the item after it.
     * Everything else can be copied verbatim, and on a 100,000-posting entry
     * "everything else" is the entire cost. Once the last staged ordinal has
     * been passed, the remaining items are copied as one slice with only their
     * first delta head rewritten.
     *
     * An addition for an ordinal the entry already holds is a replace in place,
     * which is what an edit to an existing page produces.
     *
     * @param string                                  $raw            The entry's raw CBOR, from {@see self::splitEntries()}.
     * @param list<int>                               $removeOrdinals Ordinals whose postings must go.
     * @param array<int, array<string, mixed>>        $addEntries     Ordinal => new page entry.
     * @param array<string, list<int>>                $variantAdds    Variant form => ordinals to add.
     * @return string|null The new entry bytes, or null when no page remains.
     * @since 1.4.0
     * @stability experimental
     */
    public static function patchEntry(
        CborEncoder $cbor,
        string $raw,
        array $removeOrdinals,
        array $addEntries,
        array $variantAdds,
    ): ?string {
        $parsed = self::parseEntry($raw);
        $remove = array_fill_keys(array_map(intval(...), $removeOrdinals), true);

        // An ordinal both removed and re-added is a replace, and the addition
        // is what should land. Dropping it from the removal set keeps the two
        // from fighting over emission order.
        foreach (array_keys($addEntries) as $ordinal) {
            unset($remove[(int) $ordinal]);
        }

        $additions = [];
        foreach ($addEntries as $ordinal => $entry) {
            $additions[(int) $ordinal] = $entry;
        }
        ksort($additions, SORT_NUMERIC);

        $emitted     = '';
        $count       = 0;
        $lastEmitted = 0;
        $pending     = $additions;

        foreach ($parsed['pages'] as $index => $item) {
            $page = $item['page'];

            // Insertions that sort before this posting.
            foreach ($pending as $ordinal => $entry) {
                if ($ordinal >= $page) {
                    break;
                }
                $emitted .= self::encodePageItem($cbor, $ordinal - $lastEmitted, $entry);
                $lastEmitted = $ordinal;
                $count++;
                unset($pending[$ordinal]);
            }

            if (isset($pending[$page])) {
                // Replace in place.
                $emitted .= self::encodePageItem($cbor, $page - $lastEmitted, $pending[$page]);
                $lastEmitted = $page;
                $count++;
                unset($pending[$page]);
                continue;
            }

            if (isset($remove[$page])) {
                continue;
            }

            // Nothing staged is left to interleave, so the rest of the run can
            // go out in one piece: only the first of those items has a delta
            // that could have moved.
            if ($pending === [] && $remove === []) {
                $tail = substr($raw, $item['start'], $parsed['pagesEnd'] - $item['start']);
                if ($lastEmitted !== $item['previousPage']) {
                    $tail = $item['head']
                        . $cbor->encodeUint($page - $lastEmitted)
                        . substr($raw, $item['restStart'], $parsed['pagesEnd'] - $item['restStart']);
                }
                $emitted .= $tail;
                $count   += count($parsed['pages']) - $index;
                $pending  = [];
                $lastEmitted = $parsed['lastPage'];
                break;
            }

            if ($lastEmitted === $item['previousPage']) {
                $emitted .= substr($raw, $item['start'], $item['end'] - $item['start']);
            } else {
                $emitted .= $item['head']
                    . $cbor->encodeUint($page - $lastEmitted)
                    . substr($raw, $item['restStart'], $item['end'] - $item['restStart']);
            }
            $lastEmitted = $page;
            $count++;
        }

        // Insertions past the end of the run.
        foreach ($pending as $ordinal => $entry) {
            $emitted .= self::encodePageItem($cbor, $ordinal - $lastEmitted, $entry);
            $lastEmitted = $ordinal;
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        $variants = self::patchVariants($cbor, $raw, $parsed, $remove, $variantAdds);

        return $cbor->encodeArray([
            $parsed['word'],
            $cbor->encodeArrayHead($count) . $emitted,
            $variants,
        ]);
    }

    /**
     * Rewrite the variants array, or hand back the bytes it already has.
     *
     * Variants are absent on almost every term, and an absent variants array is
     * one byte. Decoding and re-encoding it unconditionally would spend more
     * time on the empty case than the patch saves.
     *
     * @param array{word: string, pages: list<array<string, mixed>>, pagesEnd: int, lastPage: int, variantsStart: int, variantsEnd: int, variantCount: int} $parsed
     * @param array<int, true>         $remove
     * @param array<string, list<int>> $variantAdds
     */
    private static function patchVariants(
        CborEncoder $cbor,
        string $raw,
        array $parsed,
        array $remove,
        array $variantAdds,
    ): string {
        $verbatim = substr($raw, $parsed['variantsStart'], $parsed['variantsEnd'] - $parsed['variantsStart']);

        if ($variantAdds === [] && ($parsed['variantCount'] === 0 || $remove === [])) {
            return $verbatim;
        }

        /** @var list<mixed> $decoded */
        $decoded = CborDecoder::decode($verbatim);
        $forms   = [];
        foreach ($decoded as $variantEntry) {
            if (!is_array($variantEntry) || count($variantEntry) !== 2) {
                throw new \RuntimeException('Malformed pf_index variant entry: expected [form, pages].');
            }
            [$form, $variantPages] = $variantEntry;
            $ordinals              = [];
            foreach ($variantPages as $vp) {
                $ordinal = (int) $vp[0];
                if (!isset($remove[$ordinal])) {
                    $ordinals[] = $ordinal;
                }
            }
            if ($ordinals !== []) {
                $forms[(string) $form] = $ordinals;
            }
        }

        foreach ($variantAdds as $form => $ordinals) {
            $merged = array_merge($forms[(string) $form] ?? [], array_map(intval(...), $ordinals));
            sort($merged, SORT_NUMERIC);
            $forms[(string) $form] = array_values(array_unique($merged));
        }

        $encoded = [];
        foreach ($forms as $form => $ordinals) {
            $pageEntries = [];
            foreach ($ordinals as $ordinal) {
                $pageEntries[] = $cbor->encodeArray([
                    $cbor->encodeUint($ordinal),
                    $cbor->encodeArray([]),
                    $cbor->encodeArray([]),
                ]);
            }
            $encoded[] = $cbor->encodeArray([
                $cbor->encodeString((string) $form),
                $cbor->encodeArray($pageEntries),
            ]);
        }

        return $cbor->encodeArray($encoded);
    }

    /**
     * Locate an entry's word, its page items and its variants array.
     *
     * Every offset is into $raw. `previousPage` is the page number the item's
     * delta was originally coded against, which is what says whether the item's
     * bytes can be copied without touching its head.
     *
     * @return array{word: string, pages: list<array{page: int, previousPage: int, start: int, restStart: int, end: int, head: string}>, pagesEnd: int, lastPage: int, variantsStart: int, variantsEnd: int, variantCount: int}
     */
    private static function parseEntry(string $raw): array
    {
        $offset = 0;
        $head   = self::readHead($raw, $offset);
        if ($head['major'] !== 4 || $head['value'] !== 3) {
            throw new \RuntimeException('Malformed pf_index word entry: expected [word, pages, variants].');
        }

        $wordStart = $offset;
        $wordHead  = self::readHead($raw, $offset);
        if ($wordHead['major'] !== 3) {
            throw new \RuntimeException('Malformed pf_index word entry: expected a text word.');
        }
        $offset += $wordHead['value'];
        $word    = substr($raw, $wordStart, $offset - $wordStart);

        $pagesHead = self::readHead($raw, $offset);
        if ($pagesHead['major'] !== 4) {
            throw new \RuntimeException('Malformed pf_index word entry: expected an array of page items.');
        }

        $pages       = [];
        $running     = 0;
        for ($i = 0; $i < $pagesHead['value']; $i++) {
            $start    = $offset;
            $itemHead = self::readHead($raw, $offset);
            if ($itemHead['major'] !== 4 || $itemHead['value'] !== 3) {
                throw new \RuntimeException('Malformed pf_index page entry: expected [delta, locs, meta_locs].');
            }
            $headBytes  = substr($raw, $start, $offset - $start);
            $deltaHead  = self::readHead($raw, $offset);
            if ($deltaHead['major'] !== 0) {
                throw new \RuntimeException('Malformed pf_index page entry: expected an unsigned page delta.');
            }
            $restStart = $offset;
            $previous  = $running;
            $running  += $deltaHead['value'];

            self::skipItem($raw, $offset); // locs
            self::skipItem($raw, $offset); // meta_locs

            $pages[] = [
                'page'         => $running,
                'previousPage' => $previous,
                'start'        => $start,
                'restStart'    => $restStart,
                'end'          => $offset,
                'head'         => $headBytes,
            ];
        }
        $pagesEnd = $offset;

        $variantsStart = $offset;
        $variantsHead  = self::readHead($raw, $offset);
        if ($variantsHead['major'] !== 4) {
            throw new \RuntimeException('Malformed pf_index word entry: expected an array of variants.');
        }
        $offset = $variantsStart;
        self::skipItem($raw, $offset);

        return [
            'word'          => $word,
            'pages'         => $pages,
            'pagesEnd'      => $pagesEnd,
            'lastPage'      => $running,
            'variantsStart' => $variantsStart,
            'variantsEnd'   => $offset,
            'variantCount'  => $variantsHead['value'],
        ];
    }

    /**
     * Advance $offset past exactly one CBOR item, building nothing.
     *
     * One loop over heads rather than recursion: a major 0 or 1 carries its
     * whole value in the head, a major 3 skips its byte length, and a major 4
     * adds its count to the number of items still owed. The loop ends when
     * nothing is owed, which is the end of the item it was asked to skip.
     */
    private static function skipItem(string $data, int &$offset): void
    {
        $pending = 1;
        while ($pending > 0) {
            $head = self::readHead($data, $offset);
            $pending--;
            if ($head['major'] === 3) {
                $offset += $head['value'];
                continue;
            }
            if ($head['major'] === 4) {
                $pending += $head['value'];
                continue;
            }
            if ($head['major'] !== 0 && $head['major'] !== 1) {
                throw new \RuntimeException(
                    "Unsupported CBOR major type {$head['major']} in a pf_index chunk.",
                );
            }
        }
    }

    /**
     * Read one CBOR head, advancing $offset past it.
     *
     * @return array{major: int, value: int}
     */
    private static function readHead(string $data, int &$offset): array
    {
        if ($offset >= strlen($data)) {
            throw new \RuntimeException('Truncated pf_index chunk: expected another CBOR head.');
        }

        $byte       = ord($data[$offset++]);
        $major      = ($byte >> 5) & 0x07;
        $additional = $byte & 0x1F;

        if ($additional <= 23) {
            return ['major' => $major, 'value' => $additional];
        }

        $width = match ($additional) {
            24      => 1,
            25      => 2,
            26      => 4,
            27      => 8,
            default => throw new \RuntimeException(
                "Unsupported CBOR additional info {$additional} in a pf_index chunk.",
            ),
        };

        if ($offset + $width > strlen($data)) {
            throw new \RuntimeException('Truncated pf_index chunk: head runs past the end.');
        }

        $format = match ($width) {
            1       => 'C',
            2       => 'n',
            4       => 'N',
            default => 'J',
        };
        /** @var array{1: int} $unpacked */
        $unpacked = unpack($format, substr($data, $offset, $width));
        $offset  += $width;

        return ['major' => $major, 'value' => $unpacked[1]];
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
        $weight  = TextChannel::implicitBucketMarker();
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
