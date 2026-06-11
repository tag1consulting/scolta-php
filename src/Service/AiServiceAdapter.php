<?php

declare(strict_types=1);

namespace Tag1\Scolta\Service;

use Tag1\Scolta\AiClient;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
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

    private ?KeyExpiryRecovery $keyRecovery = null;

    public function __construct(ScoltaConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Wire Amazee key-expiry recovery into the AI call path.
     *
     * When set, an auth-class failure (expired/revoked trial key) on any AI
     * call triggers a one-shot re-provision through the recovery's guarded
     * path and, on success, a single retry with the fresh credentials.
     * Without it (an explicit user-configured key, or a platform that has not
     * adopted recovery yet) behavior is unchanged: the failure propagates.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function setKeyExpiryRecovery(KeyExpiryRecovery $recovery): void
    {
        $this->keyRecovery = $recovery;
    }

    /**
     * Get the Scolta configuration.
     *
     * @since 1.0.0
     * @stability stable
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
     * @since 1.0.0
     * @stability stable
     */
    public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string
    {
        try {
            $result = $this->tryFrameworkAi($systemPrompt, $userMessage, $maxTokens);
            if ($result !== null) {
                return $result;
            }

            return $this->getClient()->message($systemPrompt, $userMessage, $maxTokens);
        } catch (\RuntimeException $e) {
            $this->handlePossibleBudgetException($e);
            if ($this->recoverFromAuthFailure($e)) {
                return $this->getClient()->message($systemPrompt, $userMessage, $maxTokens);
            }
            throw $e;
        }
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
     * @since 1.0.0
     * @stability stable
     */
    public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string
    {
        try {
            $result = $this->tryFrameworkConversation($systemPrompt, $messages, $maxTokens);
            if ($result !== null) {
                return $result;
            }

            return $this->getClient()->conversation($systemPrompt, $messages, $maxTokens);
        } catch (\RuntimeException $e) {
            $this->handlePossibleBudgetException($e);
            if ($this->recoverFromAuthFailure($e)) {
                return $this->getClient()->conversation($systemPrompt, $messages, $maxTokens);
            }
            throw $e;
        }
    }

    /**
     * Send a single-turn message with operation-specific model routing.
     *
     * Uses the expansion model for 'expand_query' when configured, falling
     * back to the primary model for all other operations. Framework AI
     * integrations (tryFrameworkAi) take precedence over the model override.
     *
     * @param string $operation   The operation: 'expand_query', 'summarize', or 'follow_up'.
     * @param string $systemPrompt The system prompt.
     * @param string $userMessage  The user message.
     * @param int    $maxTokens    Maximum response tokens.
     *
     * @return string The AI response text.
     *
     * @since 0.3.6
     * @stability experimental
     */
    public function messageForOperation(string $operation, string $systemPrompt, string $userMessage, int $maxTokens = 512): string
    {
        try {
            $result = $this->tryFrameworkAi($systemPrompt, $userMessage, $maxTokens);
            if ($result !== null) {
                return $result;
            }

            $model = ($operation === 'expand_query' && $this->config->aiExpansionModel !== '')
                ? $this->config->aiExpansionModel
                : null;

            return $this->getClient()->message($systemPrompt, $userMessage, $maxTokens, $model);
        } catch (\RuntimeException $e) {
            $this->handlePossibleBudgetException($e);
            if ($this->recoverFromAuthFailure($e)) {
                $model = ($operation === 'expand_query' && $this->config->aiExpansionModel !== '')
                    ? $this->config->aiExpansionModel
                    : null;
                return $this->getClient()->message($systemPrompt, $userMessage, $maxTokens, $model);
            }
            throw $e;
        }
    }

    /**
     * Get the expand-query system prompt (custom override or default).
     *
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
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
     *
     * @since 1.0.0
     * @stability stable
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

    /**
     * Hook invoked when an AI call throws a RuntimeException.
     *
     * No-op by default. Platform adapters override this to convert or notify
     * on budget-exhaustion errors before the original exception propagates.
     * The base message(), conversation(), and messageForOperation() methods
     * call this from a catch block, then re-throw the original exception.
     *
     * @param \RuntimeException $e The exception thrown by the AI call.
     *
     * @since 1.0.3
     * @stability experimental
     */
    protected function handlePossibleBudgetException(\RuntimeException $e): void
    {
        // No-op by default. Platform adapters override to convert/notify on
        // budget-exhaustion errors before the exception propagates.
    }

    /**
     * Attempt expired-key recovery and prepare a fresh client for one retry.
     *
     * Returns true only when recovery is wired, the failure is auth-class
     * (never budget-exhaustion — KeyExpiryRecovery excludes it), the guarded
     * re-provision succeeded, and fresh credentials are available. The caller
     * then retries the original request exactly once; a failure of that retry
     * propagates normally (the recovery's window guard prevents another
     * re-provision attempt).
     */
    private function recoverFromAuthFailure(\RuntimeException $e): bool
    {
        if ($this->keyRecovery === null) {
            return false;
        }

        if (!$this->keyRecovery->handleAuthFailure($e)) {
            return false;
        }

        $credentials = $this->keyRecovery->credentials();
        if ($credentials === null) {
            return false;
        }

        $this->client = $this->createRecoveredClient($credentials);

        return true;
    }

    /**
     * Build an AiClient from freshly re-provisioned Amazee credentials.
     *
     * Recovered credentials are by definition Amazee LiteLLM ones, so the
     * provider is the OpenAI-compatible path regardless of what the (stale)
     * config says. Override in platform subclasses to inject a custom HTTP
     * client, mirroring createClient().
     *
     * @param array{litellm_token: string, litellm_api_url: string, region: string} $credentials
     */
    protected function createRecoveredClient(array $credentials): AiClient
    {
        $config = $this->config->toAiClientConfig();
        $config['provider'] = 'openai';
        $config['api_key'] = $credentials['litellm_token'];
        $config['base_url'] = $credentials['litellm_api_url'];

        return new AiClient($config);
    }
}
