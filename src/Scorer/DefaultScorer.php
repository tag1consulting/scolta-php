<?php

declare(strict_types=1);

namespace Tag1\Scolta\Scorer;

use Tag1\Scolta\Wasm\ScoltaWasm;

/**
 * Default scoring implementation backed by the WASM module.
 *
 * Provides the canonical Scolta ranking algorithm: recency decay,
 * title/content match boosting, and result merging with deduplication.
 * All math runs in the WASM module for cross-platform consistency.
 */
class DefaultScorer implements ScorerInterface
{
    public function score(array $results, array $config = []): array
    {
        $query = $config['query'] ?? '';
        return ScoltaWasm::scoreResults($results, $config, $query);
    }

    /**
     * Merge original and expanded result sets with deduplication.
     *
     * @param array $original Results from the original query.
     * @param array $expanded Results from expanded terms.
     * @param float $primaryWeight Weight for original results (0.0-1.0).
     *
     * @return array Merged, deduplicated, re-ranked results.
     */
    public function merge(array $original, array $expanded, float $primaryWeight = 0.7): array
    {
        return ScoltaWasm::mergeResults($original, $expanded, $primaryWeight);
    }

    /**
     * Parse an LLM expansion response into a term array.
     */
    public function parseExpansion(string $llmResponse): array
    {
        return ScoltaWasm::parseExpansion($llmResponse);
    }
}
