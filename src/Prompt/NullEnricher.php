<?php

declare(strict_types=1);

namespace Tag1\Scolta\Prompt;

/**
 * No-op prompt enricher that passes the prompt through unchanged.
 *
 * Used as the default when no site-specific enrichment is configured.
 *
 * @since 0.2.0
 * @stability experimental
 */
class NullEnricher implements PromptEnricherInterface
{
    public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string
    {
        return $resolvedPrompt;
    }
}
