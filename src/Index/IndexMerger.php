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
     * Phase 1 — pages: each chunk's pages are streamed in order via
     * ChunkReader::openPages(). Page numbers are already globally sequential
     * (assigned by InvertedIndexBuilder with a per-chunk offset), so no
     * remapping is needed.
     *
     * Phase 2 — N-way term merge: one ChunkReader::openIndex() generator per
     * chunk is seeded into a SplMinHeap keyed on [term, chunk_index]. The
     * heap always yields the lexicographically smallest outstanding term.
     * When multiple chunks share the same term (same word appears in pages
     * from different chunks) their entries are merged via mergeEntries()
     * before being passed to the writer.
     *
     * Peak RAM: O(chunk_count) generator frames + O(1) term data per step.
     *
     * @param string[]              $chunkPaths Paths to v2 chunk files.
     * @param StreamingFormatWriter $writer     Writer positioned after beginWrite().
     * @throws OldChunkFormatException if any chunk uses the pre-0.2.5 format.
     */
    public function mergeStreaming(array $chunkPaths, StreamingFormatWriter $writer): void
    {
        // ── Phase 1: stream pages ─────────────────────────────────────────
        foreach ($chunkPaths as $path) {
            $reader = new ChunkReader($path);
            foreach ($reader->openPages() as $pageNum => $pageData) {
                $writer->writePage($pageNum, $pageData);
            }
        }

        // ── Phase 2: N-way merge of sorted term streams ───────────────────
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
            // Peek at the minimum term without extracting.
            [$minTerm] = $heap->top();

            // Drain all iterators that are currently at this term.
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
