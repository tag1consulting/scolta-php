<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

/**
 * AI service that captures the system prompt passed to message()/conversation().
 */
class PromptCapturingAiService extends MockAiService
{
    public ?string $lastSystemPrompt = null;

    public function __construct(
        string $response = '',
        private readonly bool $captureConversation = false,
    ) {
        parent::__construct($response);
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        $this->lastSystemPrompt = $systemPrompt;
        return parent::message($systemPrompt, $userMessage, $maxTokens);
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        $this->lastSystemPrompt = $systemPrompt;
        return parent::conversation($systemPrompt, $messages, $maxTokens);
    }
}
