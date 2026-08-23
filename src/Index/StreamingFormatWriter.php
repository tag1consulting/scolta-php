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

    /** field → [pageNum → value] — accumulated in transposed form for pf_meta sorts. */
    private array $sortFields = [];

    // ── Current open index-chunk state ─────────────────────────────────────

    /** CBOR-encoded word entries for the chunk being accumulated. */
    private array $currentChunkItems = [];

    /**
     * All words in the current chunk, in written order (names the chunk file).
     *
     * @var list<string>
     */
    private array $currentChunkWords = [];

    /** Estimated byte size of the current chunk. */
    private int $currentChunkSize = 0;

    /** Completed index chunks: [{from, to, hash}]. */
    private array $indexChunkMeta = [];

    /** Active flush threshold (bytes), derived from the MemoryBudget. */
    private int $flushBytes;

    /** Active gzip level, derived from the MemoryBudget. */
    private int $compressionLevel;

    /** Build-scoped instrumentation; null disables phase emission. */
    private ?MemoryTelemetry $telemetry = null;

    // ───────────────────────────────────────────────────────────────────────

    public function __construct(
        private readonly CborEncoder $cbor,
        private readonly string $pagefindVersion = '',
        ?MemoryBudget $budget = null,
    ) {
        $this->flushBytes       = $budget?->fragmentFlushBytes() ?? self::DEFAULT_FLUSH_BYTES;
        $this->compressionLevel = $budget?->compressionLevel() ?? MemoryBudget::DEFAULT_COMPRESSION_LEVEL;
    }

    /**
     * Attach build-scoped telemetry so endWrite() reports its internal phases.
     *
     * Setter rather than constructor injection: the constructor is part of the
     * stable surface and this class is not final, so a new parameter would
     * break subclasses. Filters, pf_meta, the facet index and the asset copy
     * are four separate whole-corpus writes with no boundary between them
     * otherwise.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function setTelemetry(?MemoryTelemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
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
     *
     * @since 1.0.0
     * @stability stable
     */
    public function beginWrite(string $outputDir): void
    {
        $this->outputDir  = $outputDir;
        $this->buildDir   = $outputDir . '/.scolta-building';

        $this->pageMeta             = [];
        $this->filterData           = [];
        $this->sortFields           = [];
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
     * @since 1.0.0
     * @stability stable
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
        // Unchecked, invalid UTF-8 anywhere in the page turns json_encode()'s
        // false into an empty string on concatenation, and the fragment file
        // ends up holding nothing but the delimiter — which fails JSON.parse()
        // in the browser with no error anywhere upstream. Fail the build here
        // instead, the way FacetIndexWriter::build() already does.
        if ($fragment === false) {
            throw new \RuntimeException(sprintf(
                'Failed to encode fragment for page %d (%s): %s',
                $pageNum,
                (string) $pageData['url'],
                json_last_error_msg(),
            ));
        }

        $hash       = IndexFileNaming::fragmentHash($pageNum, (string) $pageData['url'], $fragment);
        $compressed = gzencode(self::DELIMITER . $fragment, $this->compressionLevel);
        $fragPath   = $this->buildDir . "/fragment/{$hash}.pf_fragment";
        if (file_put_contents($fragPath, $compressed) === false) {
            throw new \RuntimeException("Failed to write file: {$fragPath}");
        }

        // Retain only what pf_meta needs (~40 bytes per page).
        $this->pageMeta[$pageNum] = [
            'fragmentHash' => $hash,
            'wordCount'    => (int) $pageData['wordCount'],
        ];

        // Accumulate filter data (typically one 'site' key per page).
        foreach ($pageData['filters'] ?? [] as $filterName => $filterValue) {
            $values = is_array($filterValue) ? $filterValue : [$filterValue];
            foreach ($values as $v) {
                $this->filterData[$filterName][(string) $v][] = $pageNum;
            }
        }

        // Accumulate sortable values directly in transposed form (field → pageNum → value)
        // to avoid a temporary doubling during buildSortsArray().
        $sortableData = $pageData['sortable'] ?? [];
        if (!empty($pageData['date']) && !isset($sortableData['date'])) {
            $sortableData['date'] = $pageData['date'];
        }
        foreach ($sortableData as $field => $value) {
            $this->sortFields[$field][$pageNum] = (string) $value;
        }

        // Track meta field names so pf_meta has the correct field list.
        foreach (array_keys($pageData['meta'] ?? []) as $key) {
            if ($key !== 'url') {
                $this->collectedMetaFields[$key] = true;
            }
        }
    }

    /**
     * Write an empty fragment for every ordinal below $pageTableSize that no
     * page claimed.
     *
     * A delete releases its ordinal but does not renumber, so the page table
     * keeps a row there. `pf_meta[1]` is positional and `FacetIndexWriter`
     * refuses a table with a hole ("Facet index needs a contiguous page
     * table"), so the row has to exist. A tombstone is a real fragment with no
     * content, `word_count` 0, and no posting anywhere referencing its ordinal:
     * the page table stays dense, nothing downstream grows a hole case, and the
     * ordinal is simply unreachable by search.
     *
     * Call after every writePage() and before endWrite(). A no-op when the
     * table is already dense, which is every build that has never deleted.
     *
     * @param int $pageTableSize Total ordinals in the table, live plus tombstoned.
     * @return int Number of tombstones written.
     * @since 1.2.0
     * @stability experimental
     */
    public function fillTombstones(int $pageTableSize): int
    {
        $written = 0;
        for ($pageNum = 0; $pageNum < $pageTableSize; $pageNum++) {
            if (isset($this->pageMeta[$pageNum])) {
                continue;
            }
            $this->writePage($pageNum, [
                'url'       => '',
                'content'   => '',
                'wordCount' => 0,
                'filters'   => [],
                'meta'      => [],
                'sortable'  => [],
                'date'      => '',
            ]);
            $written++;
        }

        $this->telemetry?->emit('tombstones_filled', ['items' => $written]);

        return $written;
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
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
     */
    public function endWrite(): void
    {
        // Flush any remaining terms.
        $this->flushIndexChunk();

        // pf_meta[1] is a positional array: pagefind.js resolves a result via
        // pf_meta[1][page_num]. The metadata writer emits it in ordinal order,
        // which is only the same as accumulation order while page numbers come
        // from a running counter over the gather order. Nothing enforced that;
        // it held by accident. Sorting is done inside the metadata writer so
        // the requirement has one home.
        $this->telemetry?->emit('endwrite_metadata', ['items' => count($this->pageMeta)]);

        (new IndexMetadataWriter($this->cbor, $this->compressionLevel))->write(
            $this->buildDir,
            $this->pageMeta,
            $this->filterData,
            $this->sortFields,
            array_values($this->indexChunkMeta),
            array_keys($this->collectedMetaFields),
            $this->getVersion(),
        );
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
        $hash       = IndexFileNaming::chunkHash($this->currentChunkWords, $cborData);
        $compressed = gzencode(self::DELIMITER . $cborData, $this->compressionLevel);
        $indexPath  = $this->buildDir . "/index/{$hash}.pf_index";
        if (file_put_contents($indexPath, $compressed) === false) {
            throw new \RuntimeException("Failed to write file: {$indexPath}");
        }

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
     * Encode a single word entry as CBOR.
     *
     * Delegates to PfIndexCodec, which owns both directions of the chunk
     * format. Keeping the encoder here and a decoder elsewhere would be two
     * descriptions of one format, free to drift; the round-trip test only
     * means something while there is exactly one.
     */
    private function encodeWordEntry(string $word, array $pageEntries): string
    {
        return PfIndexCodec::encodeWordEntry($this->cbor, $word, $pageEntries);
    }

    /**
     * Create a directory, tolerating a parallel process racing to create it.
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
