<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Merge multiple partial index files into one complete inverted index.
 *
 * Loads one partial at a time to control peak memory usage. The merged
 * result contains all words from all chunks with page lists sorted by
 * page number.
 */
class IndexMerger
{
    /**
     * Merge partial index files into a complete inverted index.
     *
     * @param array $partials Array of partial index arrays (from InvertedIndexBuilder::build).
     * @return array{index: array, pages: array} Merged index and page metadata.
     */
    public function merge(array $partials): array
    {
        $mergedIndex = [];
        $mergedPages = [];

        foreach ($partials as $partial) {
            if (!isset($partial['index']) || !isset($partial['pages'])) {
                continue;
            }

            // Merge pages.
            foreach ($partial['pages'] as $pageNum => $pageData) {
                $mergedPages[$pageNum] = $pageData;
            }

            // Merge index entries.
            foreach ($partial['index'] as $word => $pageEntries) {
                if (!isset($mergedIndex[$word])) {
                    $mergedIndex[$word] = [];
                }

                foreach ($pageEntries as $pageNum => $data) {
                    // Handle variants separately.
                    if ($pageNum === '_variants') {
                        if (!isset($mergedIndex[$word]['_variants'])) {
                            $mergedIndex[$word]['_variants'] = [];
                        }
                        foreach ($data as $variant => $variantPages) {
                            if (!isset($mergedIndex[$word]['_variants'][$variant])) {
                                $mergedIndex[$word]['_variants'][$variant] = [];
                            }
                            $mergedIndex[$word]['_variants'][$variant] = array_values(
                                array_unique(
                                    array_merge($mergedIndex[$word]['_variants'][$variant], $variantPages)
                                )
                            );
                        }

                        continue;
                    }

                    if (!isset($mergedIndex[$word][$pageNum])) {
                        $mergedIndex[$word][$pageNum] = $data;
                    } else {
                        // Merge positions for duplicate page entries.
                        foreach ($data['positions'] as $weight => $positions) {
                            if (!isset($mergedIndex[$word][$pageNum]['positions'][$weight])) {
                                $mergedIndex[$word][$pageNum]['positions'][$weight] = [];
                            }
                            $mergedIndex[$word][$pageNum]['positions'][$weight] = array_values(
                                array_unique(
                                    array_merge($mergedIndex[$word][$pageNum]['positions'][$weight], $positions)
                                )
                            );
                            sort($mergedIndex[$word][$pageNum]['positions'][$weight]);
                        }
                        // Merge meta positions.
                        if (!empty($data['meta_positions'])) {
                            if (!isset($mergedIndex[$word][$pageNum]['meta_positions'])) {
                                $mergedIndex[$word][$pageNum]['meta_positions'] = [];
                            }
                            $mergedIndex[$word][$pageNum]['meta_positions'] = array_values(
                                array_unique(
                                    array_merge($mergedIndex[$word][$pageNum]['meta_positions'], $data['meta_positions'])
                                )
                            );
                            sort($mergedIndex[$word][$pageNum]['meta_positions']);
                        }
                    }
                }
            }
        }

        // Sort page lists by page number for each word.
        foreach ($mergedIndex as $word => &$pageEntries) {
            $variants = $pageEntries['_variants'] ?? null;
            unset($pageEntries['_variants']);
            ksort($pageEntries, SORT_NUMERIC);
            if ($variants !== null) {
                $pageEntries['_variants'] = $variants;
            }
        }
        unset($pageEntries);

        return ['index' => $mergedIndex, 'pages' => $mergedPages];
    }

    /**
     * Stream pages and terms from chunk files into a StreamingFormatWriter.
     *
     * Phase 1 — pages: each original chunk's pages are streamed sequentially.
     * Page numbers are already globally sequential (InvertedIndexBuilder assigns
     * them with a per-chunk offset), so no remapping is needed. This phase never
     * needs fan-in reduction — only one file handle is open at a time.
     *
     * Phase 2 — N-way term merge: one ChunkReader::openIndex() generator per
     * chunk is seeded into a SplMinHeap. When $budget->mergeOpenFileHandles()
     * is exceeded, a recursive pre-merge pass reduces fan-in by merging term
     * streams from batches of chunks into temporary term-only files before the
     * final pass. Pre-merged files contain no pages; phase 1 always reads from
     * the original paths.
     *
     * Peak RAM: O(chunk_count) generator frames + O(1) term data per step.
     *
     * @param string[]              $chunkPaths Paths to v2 chunk files.
     * @param StreamingFormatWriter $writer     Writer positioned after beginWrite().
     * @param MemoryBudget|null     $budget     Controls file-handle soft cap.
     */
    public function mergeStreaming(
        array $chunkPaths,
        StreamingFormatWriter $writer,
        ?MemoryBudget $budget = null,
    ): void {
        // ── Phase 1: stream pages from ALL original chunks ────────────────
        // Always uses sequential access (one handle at a time) — no fan-in issue.
        foreach ($chunkPaths as $path) {
            $reader = new ChunkReader($path);
            foreach ($reader->openPages() as $pageNum => $pageData) {
                $writer->writePage($pageNum, $pageData);
            }
        }

        // ── Phase 2: N-way merge of sorted term streams ───────────────────
        $cap       = $budget?->mergeOpenFileHandles() ?? PHP_INT_MAX;
        $termPaths = count($chunkPaths) > $cap
            ? $this->preMergeTerms($chunkPaths, $cap)
            : $chunkPaths;

        $this->nWayTermMerge($termPaths, $writer);
    }

    /**
     * Recursively reduce term-stream fan-in by merging batches into
     * temporary terms-only chunk files.
     *
     * Each batch of $cap chunks is merged via N-way heap (O(1) RAM per term)
     * and written to a temporary ChunkWriter file with an empty pages section.
     * Phase 1 is unaffected — it reads from the original paths.
     *
     * Temporary files are stored in sys_get_temp_dir() and may be cleaned up
     * by the caller; they are not cleaned here to allow the final nWayTermMerge
     * to read them. PHP's process exit will collect any leaks.
     *
     * @param string[] $chunkPaths
     * @return string[] Reduced set of (possibly temporary) paths for phase 2.
     */
    private function preMergeTerms(array $chunkPaths, int $cap): array
    {
        if (count($chunkPaths) <= $cap) {
            return $chunkPaths;
        }

        $tmpDir = sys_get_temp_dir() . '/scolta-premerge-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException("Failed to create temp directory: {$tmpDir}");
        }

        $batches   = array_chunk($chunkPaths, $cap);
        $outPaths  = [];

        foreach ($batches as $i => $batch) {
            if (count($batch) === 1) {
                $outPaths[] = $batch[0];
                continue;
            }

            $tmpPath = $tmpDir . sprintf('/premerge-%03d.dat', $i);
            $this->streamMergeTermsToFile($batch, $tmpPath);
            $outPaths[] = $tmpPath;
        }

        // Recurse until fan-in ≤ cap.
        return $this->preMergeTerms($outPaths, $cap);
    }

    /**
     * N-way stream-merge the term sections of $batch chunks, writing a
     * terms-only chunk file at $outputPath.
     *
     * Writes one merged term record at a time directly to the output file —
     * never accumulates the full vocabulary in RAM. Memory usage is O(one
     * term's page entries), not O(vocabulary × pages).
     *
     * The output uses the same v2 binary format as ChunkWriter so ChunkReader
     * can read it. term_count is left as 0 in the header because openIndex()
     * uses the sentinel, not the count.
     */
    private function streamMergeTermsToFile(array $batch, string $outputPath): void
    {
        $iterators = [];
        $heap      = new \SplMinHeap();

        foreach ($batch as $idx => $path) {
            $reader = new ChunkReader($path);
            $gen    = $reader->openIndex();
            if ($gen->valid()) {
                $iterators[$idx] = $gen;
                $heap->insert([$gen->current()[0], $idx]);
            }
        }

        $fp = fopen($outputPath, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open pre-merge output: {$outputPath}");
        }

        try {
            fwrite($fp, json_encode(['v' => 2, 'page_count' => 0, 'term_count' => 0]) . "\n");

            while (!$heap->isEmpty()) {
                [$minTerm] = $heap->top();

                $allEntries = [];
                while (!$heap->isEmpty() && $heap->top()[0] === $minTerm) {
                    [, $chunkIdx] = $heap->extract();
                    $allEntries[] = $iterators[$chunkIdx]->current()[1];

                    $iterators[$chunkIdx]->next();
                    if ($iterators[$chunkIdx]->valid()) {
                        $heap->insert([$iterators[$chunkIdx]->current()[0], $chunkIdx]);
                    }
                }

                $merged  = $this->mergeEntries($allEntries);
                $payload = serialize([$minTerm, $merged]);
                fwrite($fp, pack('V', strlen($payload)));
                fwrite($fp, $payload);
                unset($merged, $allEntries, $payload);
            }

            fwrite($fp, "\x00\x00\x00\x00");
            fwrite($fp, json_encode(['hmac' => '']) . "\n");
        } finally {
            fclose($fp);
        }
    }

    /**
     * N-way heap merge of term streams from the given chunk paths.
     */
    private function nWayTermMerge(array $chunkPaths, StreamingFormatWriter $writer): void
    {
        /** @var \Generator[] $iterators */
        $iterators = [];
        $heap      = new \SplMinHeap();

        foreach ($chunkPaths as $idx => $path) {
            $reader = new ChunkReader($path);
            $gen    = $reader->openIndex();
            if ($gen->valid()) {
                $iterators[$idx] = $gen;
                $heap->insert([$gen->current()[0], $idx]);
            }
        }

        while (!$heap->isEmpty()) {
            [$minTerm] = $heap->top();

            $allEntries = [];
            while (!$heap->isEmpty() && $heap->top()[0] === $minTerm) {
                [, $chunkIdx] = $heap->extract();
                $allEntries[] = $iterators[$chunkIdx]->current()[1];

                $iterators[$chunkIdx]->next();
                if ($iterators[$chunkIdx]->valid()) {
                    $heap->insert([$iterators[$chunkIdx]->current()[0], $chunkIdx]);
                }
            }

            $merged = $this->mergeEntries($allEntries);
            $writer->writeTerm($minTerm, $merged);
        }
    }

    /**
     * Merge page entries for a single term from multiple chunks.
     *
     * Because InvertedIndexBuilder assigns globally unique sequential page
     * numbers across chunks, regular page entries never collide. Only
     * _variants lists need to be unioned.
     *
     * @param array[] $allEntries One element per chunk that contained the term.
     * @return array Merged page-entry map suitable for StreamingFormatWriter::writeTerm().
     */
    private function mergeEntries(array $allEntries): array
    {
        $merged = [];

        foreach ($allEntries as $entries) {
            foreach ($entries as $key => $data) {
                if ($key === '_variants') {
                    if (!isset($merged['_variants'])) {
                        $merged['_variants'] = [];
                    }
                    foreach ($data as $variant => $variantPages) {
                        if (!isset($merged['_variants'][$variant])) {
                            $merged['_variants'][$variant] = [];
                        }
                        $merged['_variants'][$variant] = array_values(
                            array_unique(
                                array_merge($merged['_variants'][$variant], $variantPages)
                            )
                        );
                    }
                } else {
                    // Page numbers are globally unique; no collision possible.
                    $merged[$key] = $data;
                }
            }
        }

        $variants = $merged['_variants'] ?? null;
        unset($merged['_variants']);
        ksort($merged, SORT_NUMERIC);
        if ($variants !== null) {
            $merged['_variants'] = $variants;
        }

        return $merged;
    }
}
