<?php

declare(strict_types=1);

namespace Tag1\Scolta;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Exception\RateLimitException;

/**
 * Provider-agnostic AI client for LLM API calls.
 *
 * Supports Anthropic and OpenAI-compatible APIs. Platform adapters
 * (Drupal, WordPress, Laravel) inject configuration; this class
 * handles the HTTP calls and response parsing.
 *
 * Generalized to support multiple providers and accept config as a
 * plain array instead of framework-specific settings.
 */
class AiClient
{
    /**
     * Default model when none is configured.
     *
     * Single source of truth — ScoltaConfig::$aiModel uses this constant,
     * so the two defaults can never drift apart.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public const DEFAULT_MODEL = 'claude-sonnet-4-5-20250929';

    private const SUPPORTED_PROVIDERS = ['anthropic', 'openai'];

    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_API_VERSION = '2023-06-01';

    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    private ClientInterface $httpClient;
    private string $provider;
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private string $apiVersion;
    private int $timeout;

    /**
     * @param array $config Configuration array with keys:
     *   - provider: 'anthropic' or 'openai' (default: 'anthropic')
     *   - api_key: API key (required)
     *   - model: Model identifier (default: 'claude-sonnet-4-5-20250929')
     *   - base_url: Override API base URL (optional)
     *   - api_version: Anthropic API version (default: '2023-06-01')
     *   - timeout: HTTP request timeout in seconds (default: 30)
     * @param ClientInterface|null $httpClient Optional Guzzle client override.
     */
    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        $this->provider = $config['provider'] ?? 'anthropic';
        // Fail closed: an unrecognized provider must not silently fall through
        // to the Anthropic request path (wrong endpoint, wrong auth header).
        if (!in_array($this->provider, self::SUPPORTED_PROVIDERS, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Unsupported AI provider '%s'. Supported providers: %s.",
                $this->provider,
                implode(', ', self::SUPPORTED_PROVIDERS),
            ));
        }
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? self::DEFAULT_MODEL;
        $this->apiVersion = $config['api_version'] ?? self::ANTHROPIC_API_VERSION;
        $this->timeout = (int) ($config['timeout'] ?? 30);

        if ($this->provider === 'openai') {
            $baseUrl = $config['base_url'] ?? self::OPENAI_API_URL;
            // If only a domain/origin is provided (no path), append the standard
            // OpenAI chat completions path. This supports LiteLLM and other proxies
            // that return a base URL without a trailing API path.
            $path = parse_url($baseUrl, PHP_URL_PATH) ?? '/';
            if ($path === '' || $path === '/') {
                $baseUrl = rtrim($baseUrl, '/') . '/v1/chat/completions';
            }
            $this->baseUrl = $baseUrl;
        } else {
            $this->baseUrl = $config['base_url'] ?? self::ANTHROPIC_API_URL;
        }

        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * Send a single-turn message and return the response text.
     *
     * @param string $systemPrompt System prompt providing context.
     * @param string $userMessage The user's message/query.
     * @param int $maxTokens Maximum response tokens.
     * @param string|null $model Model override for this call.
     *
     * @return string Response text.
     *
     * @throws \RuntimeException If the API key is missing or the request fails.
     * @since 1.0.0
     * @stability stable
     */
    public function message(
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 1024,
        ?string $model = null,
    ): string {
        return $this->sendRequest($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], $maxTokens, $model);
    }

    /**
     * Send a multi-turn conversation and return the response text.
     *
     * @param string $systemPrompt System prompt providing context.
     * @param array $messages Array of message objects with 'role' and 'content' keys.
     * @param int $maxTokens Maximum response tokens.
     * @param string|null $model Model override for this call.
     *
     * @return string Response text.
     *
     * @throws \RuntimeException If the API key is missing or the request fails.
     * @since 1.0.0
     * @stability stable
     */
    public function conversation(
        string $systemPrompt,
        array $messages,
        int $maxTokens = 1024,
        ?string $model = null,
    ): string {
        return $this->sendRequest($systemPrompt, $messages, $maxTokens, $model);
    }

    /**
     * Send a request to the configured AI provider.
     */
    private function sendRequest(
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        ?string $model,
    ): string {
        if (empty($this->apiKey)) {
            throw new ApiKeyMissingException(
                'Scolta AI API key not configured. Set the api_key in your platform\'s Scolta configuration.'
            );
        }

        $useModel = $model ?? $this->model;

        try {
            if ($this->provider === 'openai') {
                return $this->sendOpenAiRequest($systemPrompt, $messages, $maxTokens, $useModel);
            }

            return $this->sendAnthropicRequest($systemPrompt, $messages, $maxTokens, $useModel);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 401) {
                throw new ApiKeyInvalidException(
                    'Scolta AI API key is invalid or expired. Verify the key in your Scolta configuration.',
                    0,
                    $e,
                );
            }
            if ($status === 429) {
                $retryAfter = $e->getResponse()->getHeaderLine('Retry-After') ?: null;
                throw new RateLimitException(
                    'Scolta AI API rate limit reached.',
                    $retryAfter,
                    0,
                    $e,
                );
            }
            throw new \RuntimeException('Scolta AI API request failed: ' . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Scolta AI API request failed: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Scolta AI API returned malformed JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    private function sendAnthropicRequest(
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        string $model,
    ): string {
        $response = $this->httpClient->request('POST', $this->baseUrl, [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => $messages,
            ],
            'timeout' => $this->timeout,
        ]);

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $data['content'][0]['text'] ?? '';
    }

    private function sendOpenAiRequest(
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        string $model,
    ): string {
        // Prepend system message in OpenAI format.
        $allMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );

        $response = $this->httpClient->request('POST', $this->baseUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => $allMessages,
            ],
            'timeout' => $this->timeout,
        ]);

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $data['choices'][0]['message']['content'] ?? '';
    }
}
