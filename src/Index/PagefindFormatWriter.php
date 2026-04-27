<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Serialize merged inverted index to Pagefind-compatible format files.
 *
 * Produces the directory structure that pagefind.js expects:
 * - pagefind-entry.json (plain JSON)
 * - pf_meta file (gzipped CBOR)
 * - pf_index files (gzipped CBOR with pagefind_dcd delimiter)
 * - pf_filter files (gzipped CBOR with pagefind_dcd delimiter)
 * - pf_fragment files (gzipped JSON)
 *
 * Compatible with pagefind.js 1.3.0 through 1.5.1. The CBOR array
 * format and pagefind_dcd delimiter are stable across these versions.
 */
class PagefindFormatWriter
{
    /** Magic delimiter prepended inside uncompressed data before gzip. */
    private const DELIMITER = 'pagefind_dcd';

    public function __construct(
        private readonly CborEncoder $cbor,
        private readonly string $pagefindVersion = '',
    ) {
    }

    private function getVersion(): string
    {
        return $this->pagefindVersion !== ''
            ? $this->pagefindVersion
            : SupportedVersions::getVersionForMetadata();
    }

    /**
     * Write the complete Pagefind index to disk.
     *
     * @param array $mergedIndex From IndexMerger.
     * @param array $pages       Page metadata.
     * @param string $outputDir  Destination directory.
     */
    public function write(array $mergedIndex, array $pages, string $outputDir): void
    {
        // Remap page numbers to sequential 0-based indices.
        // pagefind.js resolves search results by using page numbers as array
        // indices into pf_meta: pf_meta[1][page_num]. The page numbers in
        // pf_index MUST be 0-based sequential positions in the pf_meta array.
        [$pages, $mergedIndex] = $this->remapPageNumbers($pages, $mergedIndex);

        $buildDir = $outputDir . '/.scolta-building';
        $this->ensureDir($buildDir);
        $this->ensureDir($buildDir . '/index');
        $this->ensureDir($buildDir . '/fragment');

        // Write fragments (gzipped JSON, one per page).
        // Store the fragment hash in page data so pf_meta references the
        // correct filename. pagefind.js uses pf_meta page hashes to construct
        // fragment URLs: fragment/{hash}.pf_fragment
        foreach ($pages as $pageNum => &$page) {
            $fragment = json_encode([
                'url' => $page['url'],
                'content' => $page['content'] ?? '',
                'word_count' => $page['wordCount'],
                'filters' => !empty($page['filters']) ? $page['filters'] : new \stdClass(),
                'meta' => !empty($page['meta']) ? $page['meta'] : new \stdClass(),
                'anchors' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $hash = 'en_' . substr(hash('sha256', (string) $pageNum . $page['url']), 0, 10);
            $page['fragmentHash'] = $hash;
            $compressed = gzencode(self::DELIMITER . $fragment, 9);
            $fragPath = $buildDir . "/fragment/{$hash}.pf_fragment";
            if (file_put_contents($fragPath, $compressed) === false) {
                throw new \RuntimeException("Failed to write file: {$fragPath}");
            }
        }
        unset($page);

        // Chunk and write index files.
        $wordList = array_map('strval', array_keys($mergedIndex));
        sort($wordList);

        $chunks = $this->chunkWords($wordList, $mergedIndex);
        $indexChunkMeta = [];

        foreach ($chunks as $i => $chunkWords) {
            $cborItems = [];
            foreach ($chunkWords as $word) {
                $cborItems[] = $this->encodeWordEntry($word, $mergedIndex[$word]);
            }

            // Pagefind wraps word entries in an outer array: [[entries...]].
            // The WASM expects this wrapper when parsing pf_index chunks.
            $innerArray = $this->cbor->encodeArray($cborItems);
            $cborData = $this->cbor->encodeArray([$innerArray]);
            $hash = 'en_' . substr(hash('sha256', implode(',', $chunkWords)), 0, 10);
            $compressed = gzencode(self::DELIMITER . $cborData, 9);
            $indexPath = $buildDir . "/index/{$hash}.pf_index";
            if (file_put_contents($indexPath, $compressed) === false) {
                throw new \RuntimeException("Failed to write file: {$indexPath}");
            }

            $indexChunkMeta[] = [
                'from' => $chunkWords[0],
                'to' => $chunkWords[count($chunkWords) - 1],
                'hash' => $hash,
            ];
        }

        // Write filter index.
        $filterData = $this->buildFilterIndex($pages);
        $filterHash = null;
        if ($filterData !== null) {
            $this->ensureDir($buildDir . '/filter');
            $filterHash = 'en_' . substr(hash('sha256', $filterData), 0, 10);
            $compressed = gzencode(self::DELIMITER . $filterData, 9);
            $filterPath = $buildDir . "/filter/{$filterHash}.pf_filter";
            if (file_put_contents($filterPath, $compressed) === false) {
                throw new \RuntimeException("Failed to write file: {$filterPath}");
            }
        }

        // Collect meta fields dynamically from page data.
        $metaFields = $this->collectMetaFields($pages);

        // Collect filter names for metadata reference.
        $filterNames = $this->collectFilterNames($pages);

        // Write metadata file (gzipped CBOR).
        // The hash in the filename must match the hash in entry.json so
        // pagefind.js can locate the file: pagefind.{hash}.pf_meta
        $metaCbor = $this->buildMetadata($pages, $indexChunkMeta, $filterNames, $filterHash, $metaFields);
        $metaHash = 'en_' . substr(hash('sha256', $metaCbor), 0, 10);
        $compressed = gzencode(self::DELIMITER . $metaCbor, 9);
        $metaPath = $buildDir . "/pagefind.{$metaHash}.pf_meta";
        if (file_put_contents($metaPath, $compressed) === false) {
            throw new \RuntimeException("Failed to write file: {$metaPath}");
        }

        // Write entry.json (plain JSON, NOT gzipped).
        // The hash here MUST match the meta filename hash above.
        $entry = [
            'version' => $this->getVersion(),
            'languages' => [
                'en' => [
                    'hash' => $metaHash,
                    'wasm' => 'en',
                    'page_count' => count($pages),
                ],
            ],
            'include_characters' => [],
        ];
        $entryPath = $buildDir . '/pagefind-entry.json';
        if (file_put_contents($entryPath, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException("Failed to write file: {$entryPath}");
        }

        // Copy bundled pagefind runtime assets (JS, WASM, worker) if available.
        $assetsDir = dirname(__DIR__, 2) . '/assets/pagefind';
        foreach (['pagefind.js', 'pagefind-worker.js', 'wasm.en.pagefind', 'wasm.unknown.pagefind'] as $asset) {
            $src = $assetsDir . '/' . $asset;
            if (file_exists($src)) {
                copy($src, $buildDir . '/' . $asset);
            }
        }
    }

    /**
     * Encode a single word entry as CBOR.
     */
    private function encodeWordEntry(string $word, array $pageEntries): string
    {
        $variants = $pageEntries['_variants'] ?? [];
        unset($pageEntries['_variants']);

        // Encode page references.
        $pageNums = array_keys($pageEntries);
        sort($pageNums, SORT_NUMERIC);
        $deltaPages = DeltaEncoder::deltaEncode($pageNums);

        $encodedPages = [];
        foreach ($pageNums as $idx => $pageNum) {
            $entry = $pageEntries[$pageNum];
            $pageItems = [
                $this->cbor->encodeUint($deltaPages[$idx]),
            ];

            // Encode locs: Pagefind always emits a weight marker, even for
            // the default body weight. Body weight in Pagefind is 24 (marker -25).
            $allBodyPositions = [];
            foreach ($entry['positions'] as $weight => $positions) {
                sort($positions);
                $allBodyPositions = array_merge($allBodyPositions, $positions);
            }
            sort($allBodyPositions);

            $posItems = [];
            if (!empty($allBodyPositions)) {
                // Weight marker for body content: -(24 + 1) = -25
                $posItems[] = $this->cbor->encodeNegInt(-25);
                $deltaPos = DeltaEncoder::deltaEncode($allBodyPositions);
                foreach ($deltaPos as $dp) {
                    $posItems[] = $dp >= 0
                        ? $this->cbor->encodeUint($dp)
                        : $this->cbor->encodeNegInt($dp);
                }
            }
            $pageItems[] = $this->cbor->encodeArray($posItems);

            // Encode meta_locs: title positions use field index markers.
            // Pagefind format: -(field_index + 1) as marker, then delta positions.
            // Title is always field index 0 in our meta_fields = ["title", "date"].
            // Encode meta_locs: title positions use field index markers.
            // The marker is -(field_index + 1) where field_index is the
            // position of 'title' in the meta_fields array.
            $metaPositions = $entry['meta_positions'] ?? [];
            $metaItems = [];
            if (!empty($metaPositions)) {
                sort($metaPositions);
                // Field marker for title: find its index in meta_fields.
                // meta_fields is sorted alphabetically (collectMetaFields returns
                // keys($fields) which preserves insertion order: title first).
                // For safety, we use index 0 (title is always first).
                $titleFieldIndex = 0;
                $metaItems[] = $this->cbor->encodeNegInt(-($titleFieldIndex + 1));
                $deltaMetaPos = DeltaEncoder::deltaEncode($metaPositions);
                foreach ($deltaMetaPos as $mp) {
                    $metaItems[] = $mp >= 0
                        ? $this->cbor->encodeUint($mp)
                        : $this->cbor->encodeNegInt($mp);
                }
            }
            $pageItems[] = $this->cbor->encodeArray($metaItems);

            $encodedPages[] = $this->cbor->encodeArray($pageItems);
        }

        // Encode variants. Each variant page is a full PackedPage entry
        // [page_num, locs, meta_locs], not a bare integer.
        $encodedVariants = [];
        foreach ($variants as $form => $variantPages) {
            $variantPageEntries = [];
            foreach ($variantPages as $vp) {
                $variantPageEntries[] = $this->cbor->encodeArray([
                    $this->cbor->encodeUint($vp),
                    $this->cbor->encodeArray([]),  // empty locs
                    $this->cbor->encodeArray([]),  // empty meta_locs
                ]);
            }
            $encodedVariants[] = $this->cbor->encodeArray([
                $this->cbor->encodeString((string) $form),
                $this->cbor->encodeArray($variantPageEntries),
            ]);
        }

        return $this->cbor->encodeArray([
            $this->cbor->encodeString($word),
            $this->cbor->encodeArray($encodedPages),
            $this->cbor->encodeArray($encodedVariants),
        ]);
    }

    /**
     * Build CBOR metadata.
     *
     * MetaIndex → [version, pages, index_chunks, filters, sorts, meta_fields]
     *
     * @param array       $pages        Page data.
     * @param array       $indexChunks  Index chunk references.
     * @param string[]    $filterNames  Filter names found in pages.
     * @param string|null $filterHash   Hash of the filter file, if created.
     * @param string[]    $metaFields   Meta field names.
     */
    private function buildMetadata(
        array $pages,
        array $indexChunks,
        array $filterNames,
        ?string $filterHash,
        array $metaFields,
    ): string {
        // Pages array: [page_hash, word_count] for each page.
        // page_hash must match the fragment filename so pagefind.js can
        // load fragment/{hash}.pf_fragment for search result display.
        $pageItems = [];
        foreach ($pages as $page) {
            $pageItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($page['fragmentHash'] ?? $page['hash']),
                $this->cbor->encodeUint($page['wordCount']),
            ]);
        }

        // Index chunks: [from_word, to_word, file_hash].
        $chunkItems = [];
        foreach ($indexChunks as $chunk) {
            $chunkItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($chunk['from']),
                $this->cbor->encodeString($chunk['to']),
                $this->cbor->encodeString($chunk['hash']),
            ]);
        }

        // Filters: [filter_name, file_hash] for each filter.
        $filterItems = [];
        if ($filterHash !== null) {
            foreach ($filterNames as $filterName) {
                $filterItems[] = $this->cbor->encodeArray([
                    $this->cbor->encodeString($filterName),
                    $this->cbor->encodeString($filterHash),
                ]);
            }
        }

        // Meta fields.
        $metaFieldItems = [];
        foreach ($metaFields as $field) {
            $metaFieldItems[] = $this->cbor->encodeString($field);
        }

        return $this->cbor->encodeArray([
            $this->cbor->encodeString($this->getVersion()),
            $this->cbor->encodeArray($pageItems),
            $this->cbor->encodeArray($chunkItems),
            $this->cbor->encodeArray($filterItems),
            $this->cbor->encodeArray([]),  // sorts (not used by Scolta)
            $this->cbor->encodeArray($metaFieldItems),
        ]);
    }

    /**
     * Build filter index CBOR.
     */
    private function buildFilterIndex(array $pages): ?string
    {
        $filters = [];
        foreach ($pages as $pageNum => $page) {
            foreach ($page['filters'] ?? [] as $filterName => $filterValue) {
                if (!isset($filters[$filterName])) {
                    $filters[$filterName] = [];
                }
                if (!isset($filters[$filterName][$filterValue])) {
                    $filters[$filterName][$filterValue] = [];
                }
                $filters[$filterName][$filterValue][] = $pageNum;
            }
        }

        if (count($filters) === 0) {
            return null;
        }

        $filterItems = [];
        foreach ($filters as $filterName => $values) {
            $valueItems = [];
            foreach ($values as $value => $pageNums) {
                $valueItems[] = $this->cbor->encodeArray([
                    $this->cbor->encodeString((string) $value),
                    $this->cbor->encodeArray(
                        array_map(fn (int $p) => $this->cbor->encodeUint($p), $pageNums)
                    ),
                ]);
            }
            $filterItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($filterName),
                $this->cbor->encodeArray($valueItems),
            ]);
        }

        return $this->cbor->encodeArray($filterItems);
    }

    /**
     * Collect meta field names from all pages.
     *
     * Only includes fields that Pagefind treats as meta fields.
     * 'url' is NOT a meta field — it is a top-level fragment property
     * that pagefind.js accesses directly. Including 'url' here corrupts
     * the positional field index that pagefind.js uses to resolve metadata.
     *
     * @return string[]
     */
    private function collectMetaFields(array $pages): array
    {
        $fields = ['title' => true];
        foreach ($pages as $page) {
            foreach (array_keys($page['meta'] ?? []) as $key) {
                if ($key === 'url') {
                    continue;
                }
                $fields[$key] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * Collect unique filter names from all pages.
     *
     * @return string[]
     */
    private function collectFilterNames(array $pages): array
    {
        $names = [];
        foreach ($pages as $page) {
            foreach (array_keys($page['filters'] ?? []) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Chunk words into groups for separate index files.
     *
     * @return string[][] Array of word groups.
     */
    private function chunkWords(array $wordList, array $index): array
    {
        if (count($wordList) === 0) {
            return [];
        }

        $chunks = [];
        $currentChunk = [];
        $currentSize = 0;
        $maxChunkSize = 40000; // ~40KB per chunk.

        foreach ($wordList as $word) {
            $wordStr = (string) $word;
            $pageCount = count($index[$word]) - (isset($index[$word]['_variants']) ? 1 : 0);
            $estimatedSize = strlen($wordStr) * 2 + $pageCount * 20;

            if ($currentSize + $estimatedSize > $maxChunkSize && count($currentChunk) > 0) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentSize = 0;
            }

            $currentChunk[] = $word;
            $currentSize += $estimatedSize;
        }

        if (count($currentChunk) > 0) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Remap page numbers to sequential 0-based indices.
     *
     * InvertedIndexBuilder may use crc32 hashes or arbitrary integers as
     * page numbers. pagefind.js expects page numbers to be 0-based indices
     * into the pf_meta page array. This method normalizes them.
     *
     * @return array{0: array, 1: array} [remapped_pages, remapped_index]
     */
    private function remapPageNumbers(array $pages, array $mergedIndex): array
    {
        $originalKeys = array_keys($pages);
        $pageMap = array_flip(array_values(array_map('intval', $originalKeys)));

        // Build mapping: original page number → sequential index.
        $map = [];
        $i = 0;
        foreach ($originalKeys as $key) {
            $map[(int) $key] = $i++;
        }

        // Rekey pages to sequential.
        $newPages = array_values($pages);

        // Remap page numbers in the inverted index.
        $newIndex = [];
        foreach ($mergedIndex as $word => $entries) {
            $newIndex[$word] = [];

            // Handle _variants separately.
            if (isset($entries['_variants'])) {
                $newVariants = [];
                foreach ($entries['_variants'] as $variant => $variantPages) {
                    $newVariants[$variant] = array_map(
                        fn (int $p) => $map[$p] ?? $p,
                        $variantPages
                    );
                }
                $newIndex[$word]['_variants'] = $newVariants;
            }

            foreach ($entries as $pageNum => $data) {
                if ($pageNum === '_variants') {
                    continue;
                }
                $newPageNum = $map[(int) $pageNum] ?? (int) $pageNum;
                $newIndex[$word][$newPageNum] = $data;
            }
        }

        return [$newPages, $newIndex];
    }

    /**
     * Ensure a directory exists.
     */
    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
