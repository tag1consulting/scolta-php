<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Write the whole-corpus artifacts: the filter chunks, `pf_meta`,
 * `scolta.facets`, `pagefind-entry.json` and the bundled runtime assets.
 *
 * These four are small relative to the index — on a 109,308-page corpus,
 * 5.5 MB of filter chunks, a 1.19 MB `pf_meta` and a 1.54 MB facet index —
 * and every one of them is a function of the entire page table. There is no
 * useful partial rewrite of any of them, so both the full build and an
 * incremental update rewrite all four in full. Rewriting them whole removes a
 * case rather than adding one.
 *
 * Extracted from StreamingFormatWriter::endWrite() so the incremental updater
 * can produce them without going through the streaming page and term phases.
 * The full build still reaches them through endWrite(); this is one
 * implementation with two callers, not a copy.
 *
 * @since 1.2.0
 * @stability experimental
 */
final class IndexMetadataWriter
{
    private const DELIMITER = 'pagefind_dcd';

    /** Runtime assets copied next to the index. */
    private const ASSETS = ['pagefind.js', 'pagefind-worker.js', 'wasm.en.pagefind', 'wasm.unknown.pagefind'];

    public function __construct(
        private readonly CborEncoder $cbor,
        private readonly int $compressionLevel = MemoryBudget::DEFAULT_COMPRESSION_LEVEL,
    ) {}

    /**
     * Write all four artifacts into $buildDir.
     *
     * @param array<int, array{fragmentHash: string, wordCount: int}> $pageMeta       Ordinal => page row, ascending and dense.
     * @param array<string, array<string, list<int>>>                 $filterData     Dimension => value => ordinals.
     * @param array<string, array<int, string>>                       $sortFields     Field => ordinal => value.
     * @param list<array{from: string, to: string, hash: string}>     $indexChunkMeta Sorted, non-overlapping term ranges.
     * @param list<string>                                            $metaFields     Meta field names.
     * @return string The pf_meta hash, which stamps the facet index and the entry file.
     * @since 1.2.0
     * @stability experimental
     */
    public function write(
        string $buildDir,
        array $pageMeta,
        array $filterData,
        array $sortFields,
        array $indexChunkMeta,
        array $metaFields,
        string $version,
    ): string {
        // pf_meta[1] is positional — pagefind.js resolves a hit via
        // pf_meta[1][page_num] — so the table must be emitted in ordinal order
        // regardless of the order the caller accumulated it in.
        ksort($pageMeta, SORT_NUMERIC);

        // Both the filter chunks and the facet index encode this structure in
        // array order, and it is accumulated in page-ARRIVAL order. Arrival
        // order equals ordinal order only while ordinals are a running counter
        // over the gather order; the moment a page reuses a freed ordinal it
        // arrives out of position, and two indexes of identical content encode
        // their postings differently. Normalising here, once, before either
        // consumer, is what makes the full build and an incremental update
        // produce the same bytes. On a build whose arrival order already is
        // ordinal order it changes nothing.
        $filterData = self::normaliseFilterData($filterData);

        $filterHashes = $this->writeFilterChunks($buildDir, $filterData);

        $metaCbor   = $this->buildMetadata($pageMeta, $indexChunkMeta, $filterHashes, $sortFields, $metaFields, $version);
        $metaHash   = 'en_' . substr(hash('sha256', $metaCbor), 0, 10);
        self::putFile(
            $buildDir . "/pagefind.{$metaHash}.pf_meta",
            gzencode(self::DELIMITER . $metaCbor, $this->compressionLevel),
        );

        // The facet index carries EVERY dimension, including the single-value
        // ones writeFilterChunks() skips: a dimension Scolta can be asked to
        // apply (AUTO_LANGUAGE_FILTER applies `language`) needs a posting list
        // even when it is useless as a facet. It is stamped with the pf_meta
        // hash so the browser can detect a stale cached artifact.
        (new FacetIndexWriter($this->compressionLevel))->write(
            $buildDir,
            $filterData,
            array_map(static fn(array $meta): string => $meta['fragmentHash'], $pageMeta),
            $metaHash,
        );

        $entry = [
            'version'            => $version,
            'languages'          => [
                'en' => [
                    'hash'       => $metaHash,
                    'wasm'       => 'en',
                    'page_count' => count($pageMeta),
                ],
            ],
            'include_characters' => [],
        ];
        self::putFile(
            $buildDir . '/pagefind-entry.json',
            (string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->copyAssets($buildDir);

        return $metaHash;
    }

    /**
     * Write `pf_meta` and restamp `scolta.facets` without rebuilding either
     * corpus-wide table.
     *
     * `write()` rebuilds the filter chunks, the sorts table and the whole facet
     * index every time, because all three are functions of the entire page
     * table. For a body-only edit none of that is true: no page's filter values
     * moved, no page's sortable values moved, and no page joined or left. The
     * only thing that changed is one page's fragment hash, and the page table is
     * the one place that appears.
     *
     * So the filter hashes are taken from the previous `pf_meta[3]` — which
     * leaves the `.pf_filter` files on disk untouched, rather than rewriting
     * them to the same bytes under the same names — the sorts table is
     * re-encoded from the previous `pf_meta[4]`'s ordering, and the facet index
     * is restamped from its own bytes. The caller decides when this is legal;
     * see `IncrementalIndexUpdater::commit()`.
     *
     * @param array<int, array{fragmentHash: string, wordCount: int}> $pageMeta        Ordinal => page row.
     * @param list<mixed>                                             $previousFilters Decoded `pf_meta[3]`.
     * @param list<mixed>                                             $previousSorts   Decoded `pf_meta[4]`.
     * @param list<array{from: string, to: string, hash: string}>     $indexChunkMeta
     * @param list<string>                                            $metaFields
     * @return string The new pf_meta hash.
     * @throws \RuntimeException When the previous facet index cannot be reused.
     * @since 1.3.1
     * @stability experimental
     */
    public function writeReusingCorpusTables(
        string $buildDir,
        array $pageMeta,
        array $previousFilters,
        array $previousSorts,
        array $indexChunkMeta,
        array $metaFields,
        string $version,
    ): string {
        ksort($pageMeta, SORT_NUMERIC);

        $filterItems = [];
        foreach ($previousFilters as $row) {
            if (!is_array($row) || count($row) !== 2) {
                throw new \RuntimeException('Malformed pf_meta filter row: expected [name, hash].');
            }
            $filterItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString((string) $row[0]),
                $this->cbor->encodeString((string) $row[1]),
            ]);
        }

        $sortItems = [];
        foreach ($previousSorts as $row) {
            if (!is_array($row) || count($row) !== 2 || !is_array($row[1])) {
                throw new \RuntimeException('Malformed pf_meta sorts row: expected [field, ordinals].');
            }
            $sortItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString((string) $row[0]),
                $this->cbor->encodeArray(array_map(
                    fn($ordinal): string => $this->cbor->encodeUint((int) $ordinal),
                    $row[1],
                )),
            ]);
        }

        $metaCbor = $this->assembleMetadata(
            $pageMeta,
            $indexChunkMeta,
            $filterItems,
            $this->cbor->encodeArray($sortItems),
            $metaFields,
            $version,
        );
        $metaHash = 'en_' . substr(hash('sha256', $metaCbor), 0, 10);

        $pageHashes = array_map(static fn(array $meta): string => $meta['fragmentHash'], $pageMeta);
        if (!(new FacetIndexWriter($this->compressionLevel))->rewriteWithNewPageTable($buildDir, $pageHashes, $metaHash)) {
            throw new \RuntimeException(
                'The existing facet index cannot be restamped for this update. '
                . 'The caller must fall back to a full corpus-table rebuild.',
            );
        }

        self::putFile(
            $buildDir . "/pagefind.{$metaHash}.pf_meta",
            gzencode(self::DELIMITER . $metaCbor, $this->compressionLevel),
        );

        $entry = [
            'version'            => $version,
            'languages'          => [
                'en' => [
                    'hash'       => $metaHash,
                    'wasm'       => 'en',
                    'page_count' => count($pageMeta),
                ],
            ],
            'include_characters' => [],
        ];
        self::putFile(
            $buildDir . '/pagefind-entry.json',
            (string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->copyAssets($buildDir);

        return $metaHash;
    }

    /**
     * Put filter data into the one order every writer can reproduce.
     *
     * Within a value, page ordinals ascending. Within a dimension, values
     * ordered by their lowest ordinal — which is first-appearance order when
     * pages arrive in ordinal order, so a conventional build is unaffected.
     *
     * @param array<string, array<string, list<int>>> $filterData
     * @return array<string, array<string, list<int>>>
     */
    private static function normaliseFilterData(array $filterData): array
    {
        foreach ($filterData as $dimension => $values) {
            foreach ($values as $value => $pageNums) {
                sort($pageNums, SORT_NUMERIC);
                $values[$value] = $pageNums;
            }
            uasort($values, static fn(array $a, array $b): int => ($a[0] ?? PHP_INT_MAX) <=> ($b[0] ?? PHP_INT_MAX));
            $filterData[$dimension] = $values;
        }

        return $filterData;
    }

    /**
     * Write one `.pf_filter` chunk per multi-value dimension.
     *
     * @param array<string, array<string, list<int>>> $filterData
     * @return array<string, string> Dimension => file hash.
     */
    private function writeFilterChunks(string $buildDir, array $filterData): array
    {
        $hashes = [];
        $bodies = [];

        foreach ($filterData as $filterName => $values) {
            // A dimension with one distinct value cannot filter anything, and
            // it is not free: on a 109,308-page corpus the auto-injected
            // single-value `site` and `language` dimensions were 485,100
            // served bytes and 218,616 postings, and every posting in a loaded
            // chunk costs Pagefind one linear scan of the matched-result set on
            // every subsequent search.
            if (count($values) < 2) {
                continue;
            }
            $valueTuples = [];
            foreach ($values as $value => $pageNums) {
                $valueTuples[] = $this->cbor->encodeArray([
                    $this->cbor->encodeString((string) $value),
                    $this->cbor->encodeArray(
                        array_map(fn(int $p): string => $this->cbor->encodeUint($p), $pageNums),
                    ),
                ]);
            }
            $bodies[$filterName] = $this->cbor->encodeArray([
                $this->cbor->encodeString((string) $filterName),
                $this->cbor->encodeArray($valueTuples),
            ]);
        }

        if ($bodies !== []) {
            self::ensureDir($buildDir . '/filter');
            foreach ($bodies as $filterName => $body) {
                $hash = 'en_' . substr(hash('sha256', $body), 0, 10);
                self::putFile(
                    $buildDir . "/filter/{$hash}.pf_filter",
                    gzencode(self::DELIMITER . $body, $this->compressionLevel),
                );
                $hashes[$filterName] = $hash;
            }
        }

        // A filter chunk is named after a hash of its contents, so changing a
        // dimension writes a new file rather than overwriting the old one. The
        // full build never notices: it writes into a fresh .scolta-building
        // tree and swaps the whole directory, so anything stale disappears. An
        // in-place update has no such swap and would accumulate one orphan per
        // dimension per update — files nothing references, growing without
        // bound. Removing them here keeps the two paths producing identical
        // directories, which is what the differential test compares.
        $keep = array_flip(array_map(static fn(string $h): string => "{$h}.pf_filter", array_values($hashes)));
        foreach (glob($buildDir . '/filter/*.pf_filter') ?: [] as $existing) {
            if (!isset($keep[basename($existing)])) {
                unlink($existing);
            }
        }

        return $hashes;
    }

    /**
     * Build the pf_meta CBOR: [version, pages, index_chunks, filters, sorts, meta_fields].
     *
     * @param array<int, array{fragmentHash: string, wordCount: int}> $pageMeta
     * @param list<array{from: string, to: string, hash: string}>     $indexChunkMeta
     * @param array<string, string>                                   $filterHashes
     * @param array<string, array<int, string>>                       $sortFields
     * @param list<string>                                            $metaFields
     */
    private function buildMetadata(
        array $pageMeta,
        array $indexChunkMeta,
        array $filterHashes,
        array $sortFields,
        array $metaFields,
        string $version,
    ): string {
        $filterItems = [];
        foreach ($filterHashes as $filterName => $hash) {
            $filterItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString((string) $filterName),
                $this->cbor->encodeString($hash),
            ]);
        }

        return $this->assembleMetadata(
            $pageMeta,
            $indexChunkMeta,
            $filterItems,
            $this->buildSortsArray($sortFields),
            $metaFields,
            $version,
        );
    }

    /**
     * Assemble the six pf_meta positions from parts both writers can supply.
     *
     * The one place the pf_meta layout is written down, so the full path and the
     * restamping path cannot drift into producing differently-shaped metadata.
     *
     * @param array<int, array{fragmentHash: string, wordCount: int}> $pageMeta
     * @param list<array{from: string, to: string, hash: string}>     $indexChunkMeta
     * @param list<string>                                            $filterItems Encoded [name, hash] pairs.
     * @param string                                                  $sortsCbor   The encoded sorts array.
     * @param list<string>                                            $metaFields
     */
    private function assembleMetadata(
        array $pageMeta,
        array $indexChunkMeta,
        array $filterItems,
        string $sortsCbor,
        array $metaFields,
        string $version,
    ): string {
        $pageItems = [];
        foreach ($pageMeta as $meta) {
            $pageItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($meta['fragmentHash']),
                $this->cbor->encodeUint($meta['wordCount']),
            ]);
        }

        $chunkItems = [];
        foreach ($indexChunkMeta as $chunk) {
            $chunkItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($chunk['from']),
                $this->cbor->encodeString($chunk['to']),
                $this->cbor->encodeString($chunk['hash']),
            ]);
        }

        $metaFieldItems = [];
        foreach ($metaFields as $field) {
            $metaFieldItems[] = $this->cbor->encodeString($field);
        }

        return $this->cbor->encodeArray([
            $this->cbor->encodeString($version),
            $this->cbor->encodeArray($pageItems),
            $this->cbor->encodeArray($chunkItems),
            $this->cbor->encodeArray($filterItems),
            $sortsCbor,
            $this->cbor->encodeArray($metaFieldItems),
        ]);
    }

    /**
     * Build the sorts array for pf_meta position [4].
     *
     * Per field, the page ordinals ordered by that field's value. Numeric
     * values sort numerically, everything else lexicographically. Pages with
     * no value for a field are absent from that field's order.
     *
     * @param array<string, array<int, string>> $sortFields
     */
    private function buildSortsArray(array $sortFields): string
    {
        if ($sortFields === []) {
            return $this->cbor->encodeArray([]);
        }

        $sortItems = [];
        foreach ($sortFields as $field => $pageValues) {
            $allNumeric = array_reduce(
                $pageValues,
                static fn(bool $carry, string $v): bool => $carry && is_numeric($v),
                true,
            );

            // PHP's sorts are stable, so pages sharing a sort value keep the
            // order they were inserted in — and real corpora share sort values
            // constantly (every page published the same day). Insertion order
            // is arrival order, which differs between a full build streaming
            // the gather order and an update reading the page table by ordinal.
            // Ordering the keys first makes the tie-break "then by ordinal",
            // which both paths can reproduce. On a build whose arrival order
            // already is ordinal order this changes nothing.
            ksort($pageValues, SORT_NUMERIC);

            if ($allNumeric) {
                asort($pageValues, SORT_NUMERIC);
            } else {
                asort($pageValues, SORT_STRING);
            }

            $sortItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString((string) $field),
                $this->cbor->encodeArray(array_map(
                    fn(int $p): string => $this->cbor->encodeUint($p),
                    array_keys($pageValues),
                )),
            ]);
        }

        return $this->cbor->encodeArray($sortItems);
    }

    private function copyAssets(string $buildDir): void
    {
        $assetsDir = dirname(__DIR__, 2) . '/assets/pagefind';
        foreach (self::ASSETS as $asset) {
            $src = $assetsDir . '/' . $asset;
            if (file_exists($src)) {
                copy($src, $buildDir . '/' . $asset);
            }
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    private static function putFile(string $path, string|false $contents): void
    {
        if ($contents === false) {
            throw new \RuntimeException("Failed to encode contents for: {$path}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }
}
