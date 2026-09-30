<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Spy enricher that records calls and prepends a prefix.
 */
class SpyEnricher implements PromptEnricherInterface
{
    public int $callCount = 0;
    public ?string $lastPromptName = null;
    public ?array $lastContext = null;
    public ?string $lastResolvedPrompt = null;

    public function __construct(
        private readonly string $prefix = '',
    ) {}

    public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string
    {
        $this->callCount++;
        $this->lastResolvedPrompt = $resolvedPrompt;
        $this->lastPromptName = $promptName;
        $this->lastContext = $context;

        return $this->prefix . $resolvedPrompt;
    }
}
