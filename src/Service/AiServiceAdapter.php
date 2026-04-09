<?php

declare(strict_types=1);

namespace Tag1\Scolta\Service;

use Tag1\Scolta\AiClient;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Base class for platform AI service adapters.
 *
 * Provides the shared dual-path AI routing pattern, prompt resolution,
 * and lazy AiClient instantiation. Each platform (Drupal, WordPress,
 * Laravel) extends this class and overrides only the framework-specific
 * hook methods.
 *
 * Dual-path strategy:
 *   1. Try the platform's native AI abstraction (if available).
 *   2. Fall back to scolta-php's built-in AiClient.
 *
 * @since 0.2.0
 * @stability experimental
 */
class AiServiceAdapter
{
    private ScoltaConfig $config;

    private ?AiClient $client = null;

    public function __construct(ScoltaConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Get the Scolta configuration.
     */
    public function getConfig(): ScoltaConfig
    {
        return $this->config;
    }

    /**
     * Send a single-turn message via the best available AI path.
     *
     * Tries the platform's native AI integration first (via tryFrameworkAi),
     * then falls back to the built-in AiClient.
     *
     * @param string $systemPrompt The system prompt.
     * @param string $userMessage The user message.
     * @param int $maxTokens Maximum response tokens.
     *
     * @return string The AI response text.
     */
    public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string
    {
        $result = $this->tryFrameworkAi($systemPrompt, $userMessage, $maxTokens);
        if ($result !== null) {
            return $result;
        }

        return $this->getClient()->message($systemPrompt, $userMessage, $maxTokens);
    }

    /**
     * Send a multi-turn conversation via the best available AI path.
     *
     * Tries the platform's native AI integration first (via tryFrameworkConversation),
     * then falls back to the built-in AiClient.
     *
     * @param string $systemPrompt The system prompt.
     * @param array $messages Array of message objects with 'role' and 'content' keys.
     * @param int $maxTokens Maximum response tokens.
     *
     * @return string The AI response text.
     */
    public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string
    {
        $result = $this->tryFrameworkConversation($systemPrompt, $messages, $maxTokens);
        if ($result !== null) {
            return $result;
        }

        return $this->getClient()->conversation($systemPrompt, $messages, $maxTokens);
    }

    /**
     * Get the expand-query system prompt (custom override or default).
     */
    public function getExpandPrompt(): string
    {
        if (!empty($this->config->promptExpandQuery)) {
            return $this->config->promptExpandQuery;
        }

        return $this->resolvePrompt(DefaultPrompts::EXPAND_QUERY);
    }

    /**
     * Get the summarize system prompt (custom override or default).
     */
    public function getSummarizePrompt(): string
    {
        if (!empty($this->config->promptSummarize)) {
            return $this->config->promptSummarize;
        }

        return $this->resolvePrompt(DefaultPrompts::SUMMARIZE);
    }

    /**
     * Get the follow-up system prompt (custom override or default).
     */
    public function getFollowUpPrompt(): string
    {
        if (!empty($this->config->promptFollowUp)) {
            return $this->config->promptFollowUp;
        }

        return $this->resolvePrompt(DefaultPrompts::FOLLOW_UP);
    }

    /**
     * Resolve a prompt template with site name and description from config.
     */
    public function resolvePrompt(string $template): string
    {
        return DefaultPrompts::resolve($template, $this->config->siteName, $this->config->siteDescription);
    }

    /**
     * Get the built-in AiClient (lazily instantiated).
     */
    protected function getClient(): AiClient
    {
        if ($this->client === null) {
            $this->client = $this->createClient();
        }

        return $this->client;
    }

    /**
     * Create a new AiClient instance from config.
     *
     * Override in platform subclasses to inject a custom HTTP client
     * (e.g., Drupal's Guzzle instance).
     */
    protected function createClient(): AiClient
    {
        return new AiClient($this->config->toAiClientConfig());
    }

    /**
     * Try sending a single-turn message via the platform's native AI integration.
     *
     * Override in platform subclasses to route through the framework's AI layer.
     * Return null to fall back to the built-in AiClient.
     *
     * @param string $systemPrompt The system prompt.
     * @param string $userMessage The user message.
     * @param int $maxTokens Maximum response tokens.
     *
     * @return string|null The AI response text, or null to fall back.
     */
    protected function tryFrameworkAi(string $systemPrompt, string $userMessage, int $maxTokens): ?string
    {
        return null;
    }

    /**
     * Try sending a multi-turn conversation via the platform's native AI integration.
     *
     * Override in platform subclasses to route through the framework's AI layer.
     * Return null to fall back to the built-in AiClient.
     *
     * @param string $systemPrompt The system prompt.
     * @param array $messages Array of message objects with 'role' and 'content' keys.
     * @param int $maxTokens Maximum response tokens.
     *
     * @return string|null The AI response text, or null to fall back.
     */
    protected function tryFrameworkConversation(string $systemPrompt, array $messages, int $maxTokens): ?string
    {
        return null;
    }
}
