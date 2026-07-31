<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Write the Scolta facet index — the artifact that replaces Pagefind's filter
 * chunks for everything Scolta actually needs from them.
 *
 * Scolta uses exactly two things from Pagefind's filter feature: the list of
 * values per dimension with each value's corpus-wide total, and per-query
 * counts. Both are answerable from the data the index build already holds, and
 * answering them here means the browser never loads a `.pf_filter` chunk.
 *
 * Why that matters: Pagefind's `SearchIndex::get_filters` counts by scanning the
 * matched-result set linearly for every `(value, page)` posting in every LOADED
 * filter chunk, so once any chunk is loaded every subsequent search costs
 * `matched results x loaded postings`. Measured on a 109,308-page corpus with
 * 3,208,134 postings, a query matching 7,789 results went from 155 ms with no
 * chunk loaded to 18,589 ms with all ten loaded. The cost tracks postings, not
 * distinct values: one 19-value dimension carrying 491,074 postings cost 2,859 ms
 * while a 55-value dimension carrying 6,468 postings cost 104 ms.
 *
 * Format — the whole file is gzipped, and inside it:
 *
 *   <json header>\n
 *   <id table: one fragment hash per line, pageCount lines>
 *   <posting bodies, dimension order then value order from the header>
 *
 * Each posting body is self-delimiting and starts with a tag byte:
 *   0x00  varint count, then `count` varint deltas of ascending page indices
 *   0x01  a bitmap of ceil(pageCount / 8) bytes
 * Whichever encoding is smaller for that value wins. Pagefind's own format
 * spends roughly 2.2 gzipped bytes per posting because it writes each page id as
 * a plain CBOR uint; delta-varint and bitmap encoding brought the same 3,208,134
 * postings from 6,142,564 gzipped bytes to 805,618.
 *
 * The header carries every value's corpus-wide total, which is precisely what
 * `pagefind.filters()` returns (Pagefind reports posting-list lengths), so the
 * facet panel's value list and totals come from the header alone with no posting
 * decode at all.
 *
 * Page ids are the fragment hash, because that is the id
 * `pagefind.search().results[n].id` already returns. Keying on it means the
 * browser needs no CBOR decoder, no `pf_meta` parsing, and no mapping from
 * fragment hash back to page number.
 *
 * @since 1.1.0
 * @stability experimental
 */
class FacetIndexWriter
{
    /** Artifact filename, written alongside pagefind-entry.json. */
    public const FILENAME = 'scolta.facets';

    /** Format identifier, checked by the browser before trusting the payload. */
    public const FORMAT = 'scolta-facets';

    /** Format version. Bump only for a change the browser cannot read. */
    public const VERSION = 1;

    private const TAG_VARINT = 0;
    private const TAG_BITMAP = 1;

    /**
     * Build the artifact bytes.
     *
     * Single-value dimensions are kept: they cost almost nothing here (a
     * bitmap covering every page gzips to about a hundred bytes) and dropping
     * them would leave a filter Scolta can still be asked to APPLY — the
     * `AUTO_LANGUAGE_FILTER` path applies `language` — with no posting list to
     * apply it from. The facet panel's own single-value guard already hides
     * them from the UI. The guard that does pay off is on Pagefind's chunks,
     * where those two dimensions cost 485,100 served bytes.
     *
     * @param array<string, array<string, int[]>> $filterData Dimension => value => page numbers.
     * @param array<int, string>                  $pageHashes Page number => fragment hash, in page order.
     * @param string                              $indexHash  pf_meta hash this artifact was built against.
     * @since 1.1.0
     * @stability experimental
     */
    public function build(array $filterData, array $pageHashes, string $indexHash = ''): string
    {
        $pageCount = count($pageHashes);

        // Page numbers are sequential positions, so the table is dense and its
        // order IS the page numbering the posting lists refer to.
        $ids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            if (!isset($pageHashes[$i])) {
                throw new \RuntimeException(
                    "Facet index needs a contiguous page table; page {$i} of {$pageCount} is missing.",
                );
            }
            $ids[] = $pageHashes[$i];
        }

        $dimensions = [];
        $values     = [];
        $bodies     = [];
        foreach ($filterData as $dimension => $dimValues) {
            $dimensions[] = (string) $dimension;
            $valueList    = [];
            foreach ($dimValues as $value => $pageNums) {
                // The total is the posting-list length, matching exactly what
                // Pagefind reports for the same value, so no facet count moves.
                $valueList[] = [(string) $value, count($pageNums)];
                $bodies[]    = $this->encodePostings($pageNums, $pageCount);
            }
            $values[(string) $dimension] = $valueList;
        }

        $header = json_encode([
            'format'     => self::FORMAT,
            'version'    => self::VERSION,
            // The pf_meta hash this artifact was built against. The browser
            // checks it against the hash in the cache-busted
            // pagefind-entry.json, so a stale cached artifact is detected and
            // ignored instead of quietly counting against ids that moved.
            'indexHash'  => $indexHash,
            'pageCount'  => $pageCount,
            'dimensions' => $dimensions,
            'values'     => $values,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($header === false) {
            throw new \RuntimeException('Failed to encode facet index header: ' . json_last_error_msg());
        }

        return $header . "\n"
            . ($pageCount > 0 ? implode("\n", $ids) . "\n" : '')
            . implode('', $bodies);
    }

    /**
     * Write the artifact into a built index directory.
     *
     * @param array<string, array<string, int[]>> $filterData Dimension => value => page numbers.
     * @param array<int, string>                  $pageHashes Page number => fragment hash, in page order.
     * @param string                              $indexHash  pf_meta hash this artifact was built against.
     * @since 1.1.0
     * @stability experimental
     */
    public function write(string $buildDir, array $filterData, array $pageHashes, string $indexHash = ''): void
    {
        $path       = rtrim($buildDir, '/') . '/' . self::FILENAME;
        $compressed = gzencode($this->build($filterData, $pageHashes, $indexHash), 9);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to gzip the facet index.');
        }
        if (file_put_contents($path, $compressed) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    /**
     * Encode one value's page list, choosing the smaller of the two encodings.
     *
     * @param int[] $pageNums
     */
    private function encodePostings(array $pageNums, int $pageCount): string
    {
        $sorted = $pageNums;
        sort($sorted, SORT_NUMERIC);

        $varint = chr(self::TAG_VARINT) . self::varint(count($sorted));
        $prev   = 0;
        foreach ($sorted as $p) {
            // Duplicates encode as a zero delta rather than being dropped, so
            // the artifact's count stays identical to the count Pagefind's own
            // chunk reports for the same value.
            $varint .= self::varint($p - $prev);
            $prev = $p;
        }

        $bitmapBytes = intdiv($pageCount + 7, 8);
        if ($bitmapBytes > 0 && strlen($varint) > $bitmapBytes + 1) {
            // Assigned byte by byte into a string rather than built as an array
            // and packed: a million-page corpus would otherwise spread 125,000
            // arguments across a single pack() call.
            $bitmap = str_repeat("\0", $bitmapBytes);
            foreach ($sorted as $p) {
                $byte          = $p >> 3;
                $bitmap[$byte] = chr((ord($bitmap[$byte]) | (1 << ($p & 7))) & 0xff);
            }
            return chr(self::TAG_BITMAP) . $bitmap;
        }

        return $varint;
    }

    /** LEB128 unsigned varint. */
    private static function varint(int $value): string
    {
        if ($value < 0) {
            throw new \RuntimeException("Facet index page deltas cannot be negative; got {$value}.");
        }
        $out = '';
        while ($value >= 0x80) {
            $out .= chr((($value & 0x7f) | 0x80) & 0xff);
            $value >>= 7;
        }
        return $out . chr($value & 0xff);
    }
}
