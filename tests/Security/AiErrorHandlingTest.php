<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Security;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiClient;

/**
 * AI API error-handling tests.
 *
 * Verifies that the AiClient handles all failure modes gracefully:
 * - HTTP 429 rate limiting
 * - HTTP 500 server errors
 * - Malformed / empty JSON responses
 * - Network timeouts
 * - Empty content in a successful response
 *
 * All tests use GuzzleHttp's MockHandler so no real network calls are made.
 */
class AiErrorHandlingTest extends TestCase
{
    // -----------------------------------------------------------------------
    // HTTP 429 — provider rate-limited us
    // -----------------------------------------------------------------------

    public function testAnthropicRateLimitThrowsRuntimeException(): void
    {
        $client = $this->makeClient([
            new Response(429, ['Retry-After' => '30'], json_encode(['error' => ['message' => 'Rate limit exceeded']])),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');

        $client->message('system', 'hello');
    }

    public function testOpenAiRateLimitThrowsRuntimeException(): void
    {
        $client = $this->makeClient(
            [new Response(429, [], json_encode(['error' => ['message' => 'Rate limit exceeded']]))],
            'openai'
        );

        $this->expectException(\RuntimeException::class);

        $client->message('system', 'hello');
    }

    // -----------------------------------------------------------------------
    // HTTP 500 — provider internal server error
    // -----------------------------------------------------------------------

    public function testAnthropicServerErrorThrowsRuntimeException(): void
    {
        $client = $this->makeClient([
            new Response(500, [], json_encode(['error' => 'Internal server error'])),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');

        $client->message('system', 'hello');
    }

    public function testOpenAiServerErrorThrowsRuntimeException(): void
    {
        $client = $this->makeClient(
            [new Response(500, [], '{"error": "Internal error"}')],
            'openai'
        );

        $this->expectException(\RuntimeException::class);

        $client->message('system', 'hello');
    }

    // -----------------------------------------------------------------------
    // HTTP 401 — invalid API key
    // -----------------------------------------------------------------------

    public function testInvalidApiKeyThrowsRuntimeException(): void
    {
        $client = $this->makeClient([
            new Response(401, [], json_encode(['error' => ['type' => 'authentication_error', 'message' => 'Invalid API key']])),
        ]);

        $this->expectException(\RuntimeException::class);

        $client->message('system', 'hello');
    }

    // -----------------------------------------------------------------------
    // Malformed JSON in response
    // -----------------------------------------------------------------------

    public function testMalformedJsonResponseThrowsRuntimeException(): void
    {
        $client = $this->makeClient([
            new Response(200, [], 'this is not valid json {{{'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        $client->message('system', 'hello');
    }

    public function testTruncatedJsonResponseThrowsRuntimeException(): void
    {
        $client = $this->makeClient([
            new Response(200, [], '{"content": [{"type": "text"'), // truncated
        ]);

        $this->expectException(\RuntimeException::class);

        $client->message('system', 'hello');
    }

    // -----------------------------------------------------------------------
    // Empty / missing content in valid response
    // -----------------------------------------------------------------------

    public function testEmptyContentArrayReturnsEmptyString(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['content' => []])),
        ]);

        $result = $client->message('system', 'hello');

        $this->assertSame('', $result, 'Empty content array should yield an empty string, not a crash');
    }

    public function testMissingContentFieldReturnsEmptyString(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['id' => 'msg_123', 'type' => 'message'])),
        ]);

        $result = $client->message('system', 'hello');

        $this->assertSame('', $result, 'Missing content field should yield empty string');
    }

    public function testNullTextInContentReturnsEmptyString(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => null]]])),
        ]);

        $result = $client->message('system', 'hello');

        $this->assertSame('', $result, 'Null text should yield empty string');
    }

    // -----------------------------------------------------------------------
    // OpenAI empty choices
    // -----------------------------------------------------------------------

    public function testOpenAiEmptyChoicesReturnsEmptyString(): void
    {
        $client = $this->makeClient(
            [new Response(200, [], json_encode(['choices' => []]))],
            'openai'
        );

        $result = $client->message('system', 'hello');

        $this->assertSame('', $result, 'Empty choices array should yield empty string');
    }

    public function testOpenAiMissingChoicesFieldReturnsEmptyString(): void
    {
        $client = $this->makeClient(
            [new Response(200, [], json_encode(['id' => 'chatcmpl-123']))],
            'openai'
        );

        $result = $client->message('system', 'hello');

        $this->assertSame('', $result, 'Missing choices field should yield empty string');
    }

    // -----------------------------------------------------------------------
    // Network / connection errors (simulated timeout)
    // -----------------------------------------------------------------------

    public function testConnectionTimeoutThrowsRuntimeException(): void
    {
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $client = $this->makeClient([
            new ConnectException('Connection timed out', $request),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');

        $client->message('system', 'hello');
    }

    public function testConnectionRefusedThrowsRuntimeException(): void
    {
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $client = $this->makeClient([
            new ConnectException('Connection refused', $request),
        ]);

        $this->expectException(\RuntimeException::class);

        $client->message('system', 'hello');
    }

    // -----------------------------------------------------------------------
    // Multiple retryable errors — every attempt fails
    // -----------------------------------------------------------------------

    public function testAllAttemptsFailYieldsRuntimeException(): void
    {
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $client = $this->makeClient([
            new Response(503, [], 'Service Unavailable'),
        ]);

        $this->expectException(\RuntimeException::class);

        $client->message('system', 'hello');
    }

    // -----------------------------------------------------------------------
    // Conversation endpoint errors
    // -----------------------------------------------------------------------

    public function testConversationNetworkFailureThrowsRuntimeException(): void
    {
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $client = $this->makeClient([
            new ConnectException('Network unreachable', $request),
        ]);

        $this->expectException(\RuntimeException::class);

        $client->conversation('system', [
            ['role' => 'user', 'content' => 'What is PHP?'],
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build an AiClient backed by GuzzleHttp's MockHandler.
     *
     * @param array<Response|RequestException|ConnectException> $responses Responses to queue.
     */
    private function makeClient(array $responses, string $provider = 'anthropic'): AiClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        return new AiClient(
            ['provider' => $provider, 'api_key' => 'sk-test-key-12345', 'model' => 'test-model'],
            new Client(['handler' => $stack])
        );
    }
}
