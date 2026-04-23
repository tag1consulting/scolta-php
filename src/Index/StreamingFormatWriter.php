<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Write a Pagefind-compatible index directory in a single streaming pass.
 *
 * Unlike PagefindFormatWriter::write() — which buffers the entire merged
 * index in RAM — this class accepts pages and terms one at a time:
 *
 *   $w = new StreamingFormatWriter(new CborEncoder());
 *   $w->beginWrite($outputDir);
 *   foreach ($pages as $num => $data) { $w->writePage($num, $data); }
 *   foreach ($terms as [$term, $data]) { $w->writeTerm($term, $data); }
 *   $w->endWrite();
 *
 * writePage() flushes each fragment file immediately so only minimal
 * per-page metadata (~40 bytes) is kept in RAM.  writeTerm() accumulates
 * CBOR-encoded words into 40 KB index chunks and flushes automatically.
 * endWrite() writes the filter index, pf_meta, and pagefind-entry.json.
 *
 * Peak RSS for 50 000 pages is roughly:
 *   - $pageMeta:   ~50 000 × 40 B  ≈ 2 MB
 *   - $filterData: ~50 000 pages with one filter value ≈ 4 MB
 *   - index chunk buffer: ≤ 40 KB
 *
 * Terms must be passed in ascending alphabetical order (as produced by the
 * N-way streaming merge in IndexMerger::mergeStreaming()).
 */
class StreamingFormatWriter
{
    private const DELIMITER = 'pagefind_dcd';

    /** Flush threshold used when no MemoryBudget is provided. */
    private const DEFAULT_FLUSH_BYTES = 40_000;

    // ── State initialised by beginWrite() ──────────────────────────────────

    private string $outputDir = '';
    private string $buildDir  = '';

    /** Sequential page number → minimal metadata. */
    private array $pageMeta = [];

    /** filter_name → filter_value → [page numbers]. */
    private array $filterData = [];

    /** Meta field names seen across all pages. */
    private array $collectedMetaFields = ['title' => true];

    // ── Current open index-chunk state ─────────────────────────────────────

    /** CBOR-encoded word entries for the chunk being accumulated. */
    private array $currentChunkItems = [];

    /** All words in the current chunk (for hash computation). */
    private array $currentChunkWords = [];

    /** Estimated byte size of the current chunk. */
    private int $currentChunkSize = 0;

    /** Completed index chunks: [{from, to, hash}]. */
    private array $indexChunkMeta = [];

    /** Active flush threshold (bytes), derived from the MemoryBudget. */
    private int $flushBytes;

    // ───────────────────────────────────────────────────────────────────────

    public function __construct(
        private readonly CborEncoder $cbor,
        private readonly string $pagefindVersion = '',
        ?MemoryBudget $budget = null,
    ) {
        $this->flushBytes = $budget?->fragmentFlushBytes() ?? self::DEFAULT_FLUSH_BYTES;
    }

    private function getVersion(): string
    {
        return $this->pagefindVersion !== ''
            ? $this->pagefindVersion
            : SupportedVersions::getVersionForMetadata();
    }

    /**
     * Open the output directory and create the build-time working tree.
     *
     * Must be called before writePage() or writeTerm().
     */
    public function beginWrite(string $outputDir): void
    {
        $this->outputDir  = $outputDir;
        $this->buildDir   = $outputDir . '/.scolta-building';

        $this->pageMeta             = [];
        $this->filterData           = [];
        $this->collectedMetaFields  = ['title' => true];
        $this->currentChunkItems    = [];
        $this->currentChunkWords    = [];
        $this->currentChunkSize     = 0;
        $this->indexChunkMeta       = [];

        $this->ensureDir($this->buildDir);
        $this->ensureDir($this->buildDir . '/index');
        $this->ensureDir($this->buildDir . '/fragment');
    }

    /**
     * Write one page fragment and record its metadata.
     *
     * Flushes the fragment file immediately — only the hash and word count
     * are retained in RAM.
     *
     * @param int   $pageNum  Sequential 0-based page number.
     * @param array $pageData Page data from InvertedIndexBuilder::build().
     */
    public function writePage(int $pageNum, array $pageData): void
    {
        $fragment = json_encode([
            'url'        => $pageData['url'],
            'content'    => $pageData['content'] ?? '',
            'word_count' => $pageData['wordCount'],
            'filters'    => !empty($pageData['filters']) ? $pageData['filters'] : new \stdClass(),
            'meta'       => !empty($pageData['meta']) ? $pageData['meta'] : new \stdClass(),
            'anchors'    => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $hash       = 'en_' . substr(hash('sha256', (string) $pageNum . $pageData['url']), 0, 10);
        $compressed = gzencode(self::DELIMITER . $fragment, 9);
        file_put_contents($this->buildDir . "/fragment/{$hash}.pf_fragment", $compressed);

        // Retain only what pf_meta needs (~40 bytes per page).
        $this->pageMeta[$pageNum] = [
            'fragmentHash' => $hash,
            'wordCount'    => (int) $pageData['wordCount'],
        ];

        // Accumulate filter data (typically one 'site' key per page).
        foreach ($pageData['filters'] ?? [] as $filterName => $filterValue) {
            $this->filterData[$filterName][$filterValue][] = $pageNum;
        }

        // Track meta field names so pf_meta has the correct field list.
        foreach (array_keys($pageData['meta'] ?? []) as $key) {
            if ($key !== 'url') {
                $this->collectedMetaFields[$key] = true;
            }
        }
    }

    /**
     * Encode one term entry and append it to the current index chunk.
     *
     * Flushes the chunk to disk when it reaches ~40 KB.
     *
     * Terms MUST be passed in ascending alphabetical order.
     *
     * @param string $term     Index term (stemmed).
     * @param array  $termData Merged page entries for this term.
     */
    public function writeTerm(string $term, array $termData): void
    {
        $encoded      = $this->encodeWordEntry($term, $termData);
        $pageCount    = count($termData) - (isset($termData['_variants']) ? 1 : 0);
        $estimatedSize = strlen($term) * 2 + $pageCount * 20;

        if ($this->currentChunkSize + $estimatedSize > $this->flushBytes
            && count($this->currentChunkItems) > 0) {
            $this->flushIndexChunk();
        }

        $this->currentChunkWords[] = $term;
        $this->currentChunkItems[] = $encoded;
        $this->currentChunkSize   += $estimatedSize;
    }

    /**
     * Flush the last index chunk and write pf_meta, entry.json, and assets.
     *
     * Must be called after all writePage() and writeTerm() calls.
     */
    public function endWrite(): void
    {
        // Flush any remaining terms.
        $this->flushIndexChunk();

        // Write filter index.
        $filterData = $this->buildFilterIndex();
        $filterHash = null;
        if ($filterData !== null) {
            $this->ensureDir($this->buildDir . '/filter');
            $filterHash = 'en_' . substr(hash('sha256', $filterData), 0, 10);
            $compressed = gzencode(self::DELIMITER . $filterData, 9);
            file_put_contents($this->buildDir . "/filter/{$filterHash}.pf_filter", $compressed);
        }

        $filterNames = array_keys($this->filterData);
        $metaFields  = array_keys($this->collectedMetaFields);

        // Write pf_meta.
        $metaCbor   = $this->buildMetadata($filterNames, $filterHash, $metaFields);
        $metaHash   = 'en_' . substr(hash('sha256', $metaCbor), 0, 10);
        $compressed = gzencode(self::DELIMITER . $metaCbor, 9);
        file_put_contents($this->buildDir . "/pagefind.{$metaHash}.pf_meta", $compressed);

        // Write pagefind-entry.json.
        $entry = [
            'version'            => $this->getVersion(),
            'languages'          => [
                'en' => [
                    'hash'       => $metaHash,
                    'wasm'       => 'en',
                    'page_count' => count($this->pageMeta),
                ],
            ],
            'include_characters' => [],
        ];
        file_put_contents(
            $this->buildDir . '/pagefind-entry.json',
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Copy bundled runtime assets.
        $assetsDir = dirname(__DIR__, 2) . '/assets/pagefind';
        foreach (['pagefind.js', 'pagefind-worker.js', 'wasm.en.pagefind', 'wasm.unknown.pagefind'] as $asset) {
            $src = $assetsDir . '/' . $asset;
            if (file_exists($src)) {
                copy($src, $this->buildDir . '/' . $asset);
            }
        }
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Flush the current accumulated chunk to a pf_index file.
     */
    private function flushIndexChunk(): void
    {
        if (empty($this->currentChunkItems)) {
            return;
        }

        $innerArray = $this->cbor->encodeArray($this->currentChunkItems);
        $cborData   = $this->cbor->encodeArray([$innerArray]);
        $hash       = 'en_' . substr(hash('sha256', implode(',', $this->currentChunkWords)), 0, 10);
        $compressed = gzencode(self::DELIMITER . $cborData, 9);
        file_put_contents($this->buildDir . "/index/{$hash}.pf_index", $compressed);

        $this->indexChunkMeta[] = [
            'from' => $this->currentChunkWords[0],
            'to'   => $this->currentChunkWords[count($this->currentChunkWords) - 1],
            'hash' => $hash,
        ];

        $this->currentChunkItems = [];
        $this->currentChunkWords = [];
        $this->currentChunkSize  = 0;
    }

    /**
     * Build the filter index CBOR, or null if no filters were seen.
     */
    private function buildFilterIndex(): ?string
    {
        if (empty($this->filterData)) {
            return null;
        }

        $filterItems = [];
        foreach ($this->filterData as $filterName => $values) {
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
     * Build the pf_meta CBOR structure.
     *
     * @param string[]    $filterNames Filter names in the index.
     * @param string|null $filterHash  Hash of the pf_filter file, if any.
     * @param string[]    $metaFields  Meta field names (e.g. ['title', 'date']).
     */
    private function buildMetadata(
        array $filterNames,
        ?string $filterHash,
        array $metaFields,
    ): string {
        $pageItems = [];
        foreach ($this->pageMeta as $meta) {
            $pageItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($meta['fragmentHash']),
                $this->cbor->encodeUint($meta['wordCount']),
            ]);
        }

        $chunkItems = [];
        foreach ($this->indexChunkMeta as $chunk) {
            $chunkItems[] = $this->cbor->encodeArray([
                $this->cbor->encodeString($chunk['from']),
                $this->cbor->encodeString($chunk['to']),
                $this->cbor->encodeString($chunk['hash']),
            ]);
        }

        $filterItems = [];
        if ($filterHash !== null) {
            foreach ($filterNames as $filterName) {
                $filterItems[] = $this->cbor->encodeArray([
                    $this->cbor->encodeString($filterName),
                    $this->cbor->encodeString($filterHash),
                ]);
            }
        }

        $metaFieldItems = [];
        foreach ($metaFields as $field) {
            $metaFieldItems[] = $this->cbor->encodeString($field);
        }

        return $this->cbor->encodeArray([
            $this->cbor->encodeString($this->getVersion()),
            $this->cbor->encodeArray($pageItems),
            $this->cbor->encodeArray($chunkItems),
            $this->cbor->encodeArray($filterItems),
            $this->cbor->encodeArray([]),       // sorts (unused by Scolta)
            $this->cbor->encodeArray($metaFieldItems),
        ]);
    }

    /**
     * Encode a single word entry as CBOR.
     *
     * Identical logic to PagefindFormatWriter::encodeWordEntry().
     */
    private function encodeWordEntry(string $word, array $pageEntries): string
    {
        $variants = $pageEntries['_variants'] ?? [];
        unset($pageEntries['_variants']);

        $pageNums   = array_keys($pageEntries);
        sort($pageNums, SORT_NUMERIC);
        $deltaPages = DeltaEncoder::deltaEncode($pageNums);

        $encodedPages = [];
        foreach ($pageNums as $idx => $pageNum) {
            $entry     = $pageEntries[$pageNum];
            $pageItems = [$this->cbor->encodeUint($deltaPages[$idx])];

            $allBodyPositions = [];
            foreach ($entry['positions'] as $positions) {
                sort($positions);
                $allBodyPositions = array_merge($allBodyPositions, $positions);
            }
            sort($allBodyPositions);

            $posItems = [];
            if (!empty($allBodyPositions)) {
                $posItems[] = $this->cbor->encodeNegInt(-25); // body weight marker
                $deltaPos   = DeltaEncoder::deltaEncode($allBodyPositions);
                foreach ($deltaPos as $dp) {
                    $posItems[] = $dp >= 0
                        ? $this->cbor->encodeUint($dp)
                        : $this->cbor->encodeNegInt($dp);
                }
            }
            $pageItems[] = $this->cbor->encodeArray($posItems);

            $metaPositions = $entry['meta_positions'] ?? [];
            $metaItems     = [];
            if (!empty($metaPositions)) {
                sort($metaPositions);
                $metaItems[] = $this->cbor->encodeNegInt(-1); // title field marker (index 0)
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

        $encodedVariants = [];
        foreach ($variants as $form => $variantPages) {
            $variantPageEntries = [];
            foreach ($variantPages as $vp) {
                $variantPageEntries[] = $this->cbor->encodeArray([
                    $this->cbor->encodeUint($vp),
                    $this->cbor->encodeArray([]),
                    $this->cbor->encodeArray([]),
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
     * Create a directory, tolerating a parallel process racing to create it.
     */
    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        @mkdir($dir, 0755, true);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
