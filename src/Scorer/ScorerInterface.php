<?php

namespace Tag1\Scolta\Scorer;

interface ScorerInterface {
    /**
     * Re-rank search results based on scoring factors.
     *
     * @param array $results Raw pagefind results
     * @param array $config Scoring configuration
     * @return array Re-ranked results
     */
    public function score(array $results, array $config = []): array;
}
