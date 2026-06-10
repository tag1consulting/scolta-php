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
    /**
     * The exact message Amazee.ai returns (inside an HTTP 429 body) when a
     * key's spending limit is reached. Public so platform adapters can refer
     * to one definition instead of duplicating the magic string.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public const BUDGET_MESSAGE = 'Budget has been exceeded!';

    public function __construct(private readonly AiClient $client) {}

    /**
     * Send a single-turn message, re-throwing budget errors distinctly.
     *
     * @throws AmazeeBudgetExceededException When the Amazee budget is exhausted.
     * @throws \RuntimeException             For all other API errors.
     * @since 1.0.0
     * @stability stable
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
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
     */
    public function getClient(): AiClient
    {
        return $this->client;
    }

    /**
     * Whether an exception (anywhere in its chain) is an Amazee budget-
     * exhaustion error.
     *
     * Platform adapters use this instead of duplicating the message-substring
     * check against their own copy of the budget string.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public static function isBudgetError(\Throwable $e): bool
    {
        // Walk the exception chain: RateLimitException wraps the Guzzle ClientException
        // whose message contains the raw API response body with the budget error text.
        $cause = $e;
        while ($cause !== null) {
            if ($cause instanceof AmazeeBudgetExceededException
                || str_contains($cause->getMessage(), self::BUDGET_MESSAGE)
            ) {
                return true;
            }
            $cause = $cause->getPrevious();
        }
        return false;
    }

    private function rethrowIfBudgetExceeded(\RuntimeException $e): void
    {
        if (self::isBudgetError($e)) {
            throw new AmazeeBudgetExceededException($e);
        }
    }
}
