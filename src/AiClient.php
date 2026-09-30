<?php

declare(strict_types=1);

namespace Tag1\Scolta;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Tag1\Scolta\AiProvider\ModelIdentity;
use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Exception\ModelProviderMismatchException;
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

    /**
     * Total time a streamed answer may take, in seconds.
     *
     * A 700 token answer streams in well under a minute; the configured
     * timeout still bounds the wait for each read.
     */
    private const STREAM_TIMEOUT = 120;

    private ClientInterface $httpClient;
    private string $provider;
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private string $apiVersion;
    private int $timeout;

    /**
     * Whether requests go to the provider's own API rather than a proxy.
     *
     * A configured `base_url` means the traffic is aimed at a gateway (the
     * Amazee.ai LiteLLM proxy, a self-hosted OpenAI-compatible server, an
     * enterprise egress proxy). Those endpoints define their own model
     * namespaces, so the provider-native model check must not run against
     * them — only against the vendor's own API, where "is this one of your
     * model IDs" is a question with a knowable answer.
     */
    private bool $usesProviderEndpoint;

    /**
     * @param array $config Configuration array with keys:
     *   - provider: 'anthropic' or 'openai' (required; there is no default)
     *   - api_key: API key (required)
     *   - model: Model identifier (default: 'claude-sonnet-4-5-20250929')
     *   - base_url: Override API base URL (optional)
     *   - api_version: Anthropic API version (default: '2023-06-01')
     *   - timeout: HTTP request timeout in seconds (default: 30)
     * @param ClientInterface|null $httpClient Optional Guzzle client override.
     */
    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        // No default. An absent or empty provider is "nobody selected one", and
        // the check below turns that into an error rather than into Anthropic:
        // a client must never be built on an assumption about which vendor the
        // site meant, and callers are expected to keep AI off instead of
        // constructing one.
        $this->provider = $config['provider'] ?? '';
        if (trim($this->provider) === '') {
            throw new \InvalidArgumentException(sprintf(
                'No AI provider selected. Set one of: %s.',
                implode(', ', self::SUPPORTED_PROVIDERS),
            ));
        }
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
        // Recorded before the OpenAI path rewrites $baseUrl below, which would
        // otherwise make an unset base_url indistinguishable from a configured
        // one.
        $this->usesProviderEndpoint = ($config['base_url'] ?? '') === '';

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
     * @param float|null $temperature Sampling temperature for this call. When
     *   null (the default) no temperature field is sent and the provider
     *   default applies. Pass 0.0 for deterministic output.
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
        ?float $temperature = null,
    ): string {
        return $this->sendRequest($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], $maxTokens, $model, $temperature);
    }

    /**
     * Send a multi-turn conversation and return the response text.
     *
     * @param string $systemPrompt System prompt providing context.
     * @param array $messages Array of message objects with 'role' and 'content' keys.
     * @param int $maxTokens Maximum response tokens.
     * @param string|null $model Model override for this call.
     * @param float|null $temperature Sampling temperature for this call. When
     *   null (the default) no temperature field is sent and the provider
     *   default applies. Pass 0.0 for deterministic output.
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
        ?float $temperature = null,
    ): string {
        return $this->sendRequest($systemPrompt, $messages, $maxTokens, $model, $temperature);
    }

    /**
     * Stream a multi-turn conversation, yielding the answer as it arrives.
     *
     * The request is the one conversation() sends plus `stream: true`, and
     * HTTP failures map to the same exceptions before the first piece is
     * yielded. With $cacheSystem on Anthropic the system prompt is sent as one
     * block marked for prompt caching; OpenAI caches long prefixes by itself,
     * so the flag changes nothing there.
     *
     * @param string $systemPrompt System prompt providing context.
     * @param array<int, array<string, mixed>> $messages Message objects with 'role' and 'content' keys.
     * @param int $maxTokens Maximum response tokens.
     * @param string|null $model Model override for this call.
     * @param float|null $temperature Sampling temperature, or null for the provider default.
     * @param bool $cacheSystem Mark the system prompt for provider prompt caching.
     *
     * @return \Generator<int, string> Text deltas, in order.
     *
     * @throws \RuntimeException If the API key is missing or the request fails.
     * @since 2.0.0
     * @stability experimental
     */
    public function conversationStream(
        string $systemPrompt,
        array $messages,
        int $maxTokens = 1024,
        ?string $model = null,
        ?float $temperature = null,
        bool $cacheSystem = false,
    ): \Generator {
        $response = $this->send($systemPrompt, $messages, $maxTokens, $model ?? $this->model, $temperature, true, $cacheSystem);
        yield from $this->readStream($response->getBody());
    }

    /**
     * Send a request to the configured AI provider.
     */
    private function sendRequest(
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        ?string $model,
        ?float $temperature = null,
    ): string {
        $response = $this->send($systemPrompt, $messages, $maxTokens, $model ?? $this->model, $temperature, false, false);

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Scolta AI API returned malformed JSON: ' . $e->getMessage(), 0, $e);
        }

        if ($this->provider === 'openai') {
            return $data['choices'][0]['message']['content'] ?? '';
        }

        return $data['content'][0]['text'] ?? '';
    }

    /**
     * Whether the model just rejected could not have belonged to this provider.
     *
     * Scoped to requests aimed at the provider's own API: a configured
     * `base_url` means a gateway with its own model namespace, where a
     * non-vendor model name is expected rather than wrong.
     */
    private function modelIsForeignToProvider(string $model): bool
    {
        return $this->usesProviderEndpoint
            && !ModelIdentity::looksNativeFor($this->provider, $model);
    }

    /**
     * Send one request and map every failure to Scolta's exceptions.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function send(
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        string $model,
        ?float $temperature,
        bool $stream,
        bool $cacheSystem,
    ): ResponseInterface {
        if (empty($this->apiKey)) {
            throw new ApiKeyMissingException(
                'Scolta AI API key not configured. Set the api_key in your platform\'s Scolta configuration.',
            );
        }

        $options = [
            'headers' => $this->requestHeaders(),
            'json' => $this->requestBody($systemPrompt, $messages, $maxTokens, $model, $temperature, $cacheSystem),
            'timeout' => $this->timeout,
        ];
        if ($stream) {
            $options['json']['stream'] = true;
            $options['stream'] = true;
            $options['timeout'] = max(self::STREAM_TIMEOUT, $this->timeout);
            $options['read_timeout'] = $this->timeout;
        }

        try {
            return $this->httpClient->request('POST', $this->baseUrl, $options);
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
            // The provider rejected the request for some reason other than auth
            // or rate limiting. If the model we sent is not one this provider
            // could have recognised, say so: an unknown-model rejection is
            // otherwise indistinguishable from any other 4xx, and the operator
            // gets nothing actionable. Classifying an already-failed request is
            // the only safe use of this check — see ModelIdentity.
            if ($this->modelIsForeignToProvider($model)) {
                throw new ModelProviderMismatchException(
                    $model,
                    $this->provider,
                    ModelIdentity::describeMismatch($this->provider, $model),
                    $e,
                );
            }
            throw new \RuntimeException('Scolta AI API request failed: ' . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Scolta AI API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        if ($this->provider === 'openai') {
            return [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ];
        }

        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function requestBody(
        string $systemPrompt,
        array $messages,
        int $maxTokens,
        string $model,
        ?float $temperature,
        bool $cacheSystem,
    ): array {
        if ($this->provider === 'openai') {
            // Prepend system message in OpenAI format.
            $body = [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages),
            ];
        } else {
            $body = [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $cacheSystem
                    ? [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]]
                    : $systemPrompt,
                'messages' => $messages,
            ];
        }
        // Omit temperature entirely when null so the provider default applies.
        // The Amazee path proxies through the OpenAI-compatible endpoint and
        // inherits the same body handling.
        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }

        return $body;
    }

    /**
     * Read a server-sent event stream and yield its text deltas.
     *
     * Anthropic sends `content_block_delta` events carrying `text_delta`;
     * OpenAI-compatible endpoints send `choices[0].delta.content` until
     * `[DONE]`. Everything else (pings, message metadata) is skipped.
     *
     * Read a line at a time: a read of N bytes from a network stream waits
     * until N bytes arrive, and reading 8 KB at once held the first words
     * back by seconds. Single byte reads come from the stream's own buffer,
     * so each event is passed on as soon as its line is complete.
     *
     * @return \Generator<int, string>
     */
    private function readStream(StreamInterface $body): \Generator
    {
        $finished = false;
        while (!$finished) {
            try {
                $line = '';
                while (!str_ends_with($line, "\n") && ($byte = $body->read(1)) !== '') {
                    $line .= $byte;
                }
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Scolta AI API stream failed: ' . $e->getMessage(), 0, $e);
            }
            if ($line === '') {
                // A connection that closes before the provider's end event
                // cut the answer off; it must not be kept as a whole one.
                throw new \RuntimeException('Scolta AI API stream ended early');
            }
            $text = $this->streamLineText(rtrim($line, "\r\n"), $finished);
            if ($text !== '') {
                yield $text;
            }
        }
    }

    /**
     * The text one stream line carries, if any.
     *
     * Sets $done when the line ends the answer.
     */
    private function streamLineText(string $line, bool &$done): string
    {
        if (!str_starts_with($line, 'data:')) {
            return '';
        }
        $payload = trim(substr($line, 5));
        if ($payload === '[DONE]') {
            $done = true;
            return '';
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return '';
        }
        if (isset($event['error'])) {
            $error = is_array($event['error']) ? ($event['error']['type'] ?? $event['error']['message'] ?? 'error') : $event['error'];
            throw new \RuntimeException('Scolta AI API stream failed: ' . (is_string($error) ? $error : 'error'));
        }
        if ($this->provider === 'openai') {
            // Some gateways end on a finish_reason and never send [DONE].
            if (($event['choices'][0]['finish_reason'] ?? null) !== null) {
                $done = true;
            }
            $text = $event['choices'][0]['delta']['content'] ?? '';
            return is_string($text) ? $text : '';
        }
        if (($event['type'] ?? '') === 'message_stop') {
            $done = true;
            return '';
        }
        if (($event['type'] ?? '') === 'content_block_delta' && ($event['delta']['type'] ?? '') === 'text_delta') {
            $text = $event['delta']['text'] ?? '';
            return is_string($text) ? $text : '';
        }

        return '';
    }
}
