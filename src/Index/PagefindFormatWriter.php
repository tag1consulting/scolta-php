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
        $buildDir = $outputDir . '/.scolta-building';
        $this->ensureDir($buildDir);
        $this->ensureDir($buildDir . '/index');
        $this->ensureDir($buildDir . '/fragment');

        // Write fragments (gzipped JSON, one per page).
        foreach ($pages as $pageNum => $page) {
            $fragment = json_encode([
                'url' => $page['url'],
                'content' => $page['content'] ?? '',
                'word_count' => $page['wordCount'],
                'filters' => !empty($page['filters']) ? $page['filters'] : new \stdClass(),
                'meta' => !empty($page['meta']) ? $page['meta'] : new \stdClass(),
                'anchors' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $hash = substr(hash('sha256', (string) $pageNum . $page['url']), 0, 16);
            $compressed = gzencode($fragment, 9);
            file_put_contents($buildDir . "/fragment/{$hash}.pf_fragment", $compressed);
        }

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

            $cborData = $this->cbor->encodeArray($cborItems);
            $hash = substr(hash('sha256', implode(',', $chunkWords)), 0, 16);
            $compressed = gzencode(self::DELIMITER . $cborData, 9);
            file_put_contents($buildDir . "/index/{$hash}.pf_index", $compressed);

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
            $filterHash = substr(hash('sha256', 'filter'), 0, 16);
            $compressed = gzencode(self::DELIMITER . $filterData, 9);
            file_put_contents($buildDir . "/pagefind.{$filterHash}.pf_filter", $compressed);
        }

        // Collect meta fields dynamically from page data.
        $metaFields = $this->collectMetaFields($pages);

        // Collect filter names for metadata reference.
        $filterNames = $this->collectFilterNames($pages);

        // Write metadata file (gzipped CBOR).
        $metaCbor = $this->buildMetadata($pages, $indexChunkMeta, $filterNames, $filterHash, $metaFields);
        $metaHash = substr(hash('sha256', 'meta'), 0, 16);
        $compressed = gzencode(self::DELIMITER . $metaCbor, 9);
        file_put_contents($buildDir . "/pagefind.{$metaHash}.pf_meta", $compressed);

        // Write entry.json (plain JSON, NOT gzipped).
        $langHash = substr(hash('sha256', 'en'), 0, 16);
        $entry = [
            'version' => $this->getVersion(),
            'languages' => [
                'en' => [
                    'hash' => $langHash,
                    'wasm' => null,
                    'page_count' => count($pages),
                ],
            ],
            'include_characters' => [],
        ];
        file_put_contents(
            $buildDir . '/pagefind-entry.json',
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Copy bundled pagefind.js if available.
        $bundledJs = dirname(__DIR__, 2) . '/assets/pagefind/pagefind.js';
        if (file_exists($bundledJs)) {
            copy($bundledJs, $buildDir . '/pagefind.js');
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
            $positions = DeltaEncoder::encodePositions($entry['positions']);

            $pageItems = [
                $this->cbor->encodeUint($deltaPages[$idx]),
            ];
            // Encode positions.
            $posItems = [];
            foreach ($positions as $pos) {
                $posItems[] = $pos >= 0
                    ? $this->cbor->encodeUint($pos)
                    : $this->cbor->encodeNegInt($pos);
            }
            $pageItems[] = $this->cbor->encodeArray($posItems);
            // Empty meta positions.
            $pageItems[] = $this->cbor->encodeArray([]);

            $encodedPages[] = $this->cbor->encodeArray($pageItems);
        }

        // Encode variants.
        $encodedVariants = [];
        foreach ($variants as $form => $variantPages) {
            $encodedVariants[] = $this->cbor->encodeArray([
                $this->cbor->encodeString((string) $form),
                $this->cbor->encodeArray(
                    array_map(fn (int $p) => $this->cbor->encodeUint($p), $variantPages)
                ),
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
        $pageItems = [];
        foreach ($pages as $page) {
            $pageItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($page['hash']),
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
     * Ensures standard fields (title, url) are always present.
     *
     * @return string[]
     */
    private function collectMetaFields(array $pages): array
    {
        $fields = ['title' => true, 'url' => true];
        foreach ($pages as $page) {
            foreach (array_keys($page['meta'] ?? []) as $key) {
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
     * Ensure a directory exists.
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
