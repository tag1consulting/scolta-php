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
     * Merge from serialized chunk files on disk.
     *
     * @param string[] $chunkFiles Paths to chunk data files.
     * @return array{index: array, pages: array}
     */
    public function mergeFromFiles(array $chunkFiles): array
    {
        $partials = [];
        foreach ($chunkFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $data = file_get_contents($file);
            if ($data === false) {
                continue;
            }
            $partial = unserialize($data);
            if (is_array($partial)) {
                $partials[] = $partial;
            }
        }

        return $this->merge($partials);
    }
}
