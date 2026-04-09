<?php

declare(strict_types=1);

namespace Tag1\Scolta\Prompt;

/**
 * Allows site-specific context injection between WASM prompt resolution and the LLM call.
 *
 * Implementations can modify the resolved prompt text before it is sent to the AI
 * provider, enabling domain-specific context enrichment (e.g., injecting product
 * catalogs, compliance rules, or tenant-specific instructions).
 *
 * @since 0.2.0
 * @stability experimental
 */
interface PromptEnricherInterface
{
    /**
     * Enrich a resolved prompt before it is sent to the AI provider.
     *
     * @param string $resolvedPrompt The prompt text after WASM template resolution.
     * @param string $promptName     The prompt identifier ('expand_query', 'summarize', or 'follow_up').
     * @param array  $context        Additional context (e.g., query, search results, messages).
     * @return string The enriched prompt text.
     */
    public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string;
}
