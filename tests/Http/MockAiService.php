<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Exception\RateLimitException;

/**
 * In-memory mock AI service implementing the duck-typed interface.
 */
class MockAiService
{
    public int $callCount = 0;

    public function __construct(
        private readonly string $response = '',
        private readonly bool $throwOnMessage = false,
        private readonly bool $throwOnConversation = false,
        private readonly bool $throwApiKeyMissing = false,
        private readonly bool $throwApiKeyInvalid = false,
        private readonly bool $throwRateLimit = false,
        private readonly ?string $rateLimitRetryAfter = null,
    ) {}

    public function getExpandPrompt(): string
    {
        return 'Expand the following search query.';
    }

    public function getSummarizePrompt(): string
    {
        return 'Summarize the following search results.';
    }

    public function getFollowUpPrompt(): string
    {
        return 'Continue the conversation.';
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        $this->throwIfConfigured();
        $this->callCount++;
        return $this->response;
    }

    public function messageForOperation(string $operation, string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        return $this->message($systemPrompt, $userMessage, $maxTokens);
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        if ($this->throwOnConversation) {
            throw new \RuntimeException('AI service unavailable');
        }
        $this->throwIfConfigured();
        $this->callCount++;
        return $this->response;
    }

    private function throwIfConfigured(): void
    {
        if ($this->throwApiKeyMissing) {
            throw new ApiKeyMissingException('Scolta AI API key not configured.');
        }
        if ($this->throwApiKeyInvalid) {
            throw new ApiKeyInvalidException('Scolta AI API key is invalid or expired.');
        }
        if ($this->throwRateLimit) {
            throw new RateLimitException('Scolta AI API rate limit reached.', $this->rateLimitRetryAfter);
        }
        if ($this->throwOnMessage) {
            throw new \RuntimeException('AI service unavailable');
        }
    }
}
