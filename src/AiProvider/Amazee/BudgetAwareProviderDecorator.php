<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

use Tag1\Scolta\AiClient;

/**
 * Wraps an AiClient and converts Amazee.ai budget-exceeded errors into
 * AmazeeBudgetExceededException so callers can handle them distinctly.
 *
 * Amazee.ai returns HTTP 429 with the message "Budget has been exceeded!"
 * when a key's spending limit is reached. Without this decorator, that
 * surfaces as a generic RuntimeException.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class BudgetAwareProviderDecorator
{
    private const BUDGET_MESSAGE = 'Budget has been exceeded!';

    public function __construct(private readonly AiClient $client)
    {
    }

    /**
     * Send a single-turn message, re-throwing budget errors distinctly.
     *
     * @throws AmazeeBudgetExceededException When the Amazee budget is exhausted.
     * @throws \RuntimeException             For all other API errors.
     */
    public function message(
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 1024,
        ?string $model = null,
    ): string {
        try {
            return $this->client->message($systemPrompt, $userMessage, $maxTokens, $model);
        } catch (\RuntimeException $e) {
            $this->rethrowIfBudgetExceeded($e);
            throw $e;
        }
    }

    /**
     * Send a multi-turn conversation, re-throwing budget errors distinctly.
     *
     * @throws AmazeeBudgetExceededException When the Amazee budget is exhausted.
     * @throws \RuntimeException             For all other API errors.
     */
    public function conversation(
        string $systemPrompt,
        array $messages,
        int $maxTokens = 1024,
        ?string $model = null,
    ): string {
        try {
            return $this->client->conversation($systemPrompt, $messages, $maxTokens, $model);
        } catch (\RuntimeException $e) {
            $this->rethrowIfBudgetExceeded($e);
            throw $e;
        }
    }

    /**
     * Expose the underlying AiClient for direct use when needed.
     */
    public function getClient(): AiClient
    {
        return $this->client;
    }

    private function rethrowIfBudgetExceeded(\RuntimeException $e): void
    {
        if (str_contains($e->getMessage(), self::BUDGET_MESSAGE)) {
            throw new AmazeeBudgetExceededException($e);
        }
    }
}
