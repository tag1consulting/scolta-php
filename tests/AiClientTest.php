<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Exception\ModelProviderMismatchException;
use Tag1\Scolta\Exception\RateLimitException;

class AiClientTest extends TestCase
{
    // -------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------

    public function testDefaultProviderIsAnthropic(): void
    {
        $mock = new MockHandler([]);
        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'test'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );
        // If we could inspect the private field, we'd check. Instead we verify
        // indirectly through the request format (tested below).
        $this->assertInstanceOf(AiClient::class, $client);
    }

    public function testUnknownProviderThrows(): void
    {
        // Fail closed: 'claude', 'azure', a typo'd 'anthorpic' — none of these
        // may silently fall through to the Anthropic request path.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported AI provider 'claude'");
        new AiClient(['provider' => 'claude', 'api_key' => 'test']);
    }

    public function testDefaultModelConstantMatchesConfigDefault(): void
    {
        // One shared constant — AiClient and ScoltaConfig must not drift.
        $config = new \Tag1\Scolta\Config\ScoltaConfig();
        $this->assertSame(AiClient::DEFAULT_MODEL, $config->aiModel);
    }

    public function testThrowsWhenNoApiKey(): void
    {
        $client = new AiClient([
            'provider' => 'anthropic','api_key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key not configured');
        $client->message('system', 'hello');
    }

    public function testThrowsApiKeyMissingExceptionWhenNoApiKey(): void
    {
        $client = new AiClient([
            'provider' => 'anthropic','api_key' => '']);

        $this->expectException(ApiKeyMissingException::class);
        $client->message('system', 'hello');
    }

    public function testThrowsWhenApiKeyMissing(): void
    {
        $client = new AiClient([
            'provider' => 'anthropic',
        ]);

        $this->expectException(\RuntimeException::class);
        $client->message('system', 'hello');
    }

    // -------------------------------------------------------------------
    // Anthropic provider
    // -------------------------------------------------------------------

    public function testAnthropicRequestFormat(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'Response text']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'anthropic', 'api_key' => 'sk-ant-test', 'model' => 'claude-test'],
            new Client(['handler' => $stack]),
        );

        $result = $client->message('You are helpful.', 'Hello', 256);

        $this->assertEquals('Response text', $result);

        // Verify request.
        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('sk-ant-test', $request->getHeader('x-api-key')[0]);
        $this->assertEquals('2023-06-01', $request->getHeader('anthropic-version')[0]);

        $body = json_decode((string) $request->getBody(), true);
        $this->assertEquals('claude-test', $body['model']);
        $this->assertEquals(256, $body['max_tokens']);
        $this->assertEquals('You are helpful.', $body['system']);
        $this->assertCount(1, $body['messages']);
        $this->assertEquals('user', $body['messages'][0]['role']);
        $this->assertEquals('Hello', $body['messages'][0]['content']);
    }

    public function testAnthropicConversation(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'Follow-up answer']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $messages = [
            ['role' => 'user', 'content' => 'What is Drupal?'],
            ['role' => 'assistant', 'content' => 'A CMS.'],
            ['role' => 'user', 'content' => 'Tell me more.'],
        ];

        $result = $client->conversation('Be helpful.', $messages);
        $this->assertEquals('Follow-up answer', $result);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertCount(3, $body['messages']);
        $this->assertEquals('Be helpful.', $body['system']);
    }

    public function testAnthropicModelOverride(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key', 'model' => 'default-model'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg', 100, 'override-model');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertEquals('override-model', $body['model']);
    }

    public function testAnthropicDefaultUrl(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg');
        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('api.anthropic.com', $uri);
    }

    // -------------------------------------------------------------------
    // OpenAI provider
    // -------------------------------------------------------------------

    public function testOpenAiRequestFormat(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'OpenAI response']]],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'openai', 'api_key' => 'sk-openai', 'model' => 'gpt-4'],
            new Client(['handler' => $stack]),
        );

        $result = $client->message('You are helpful.', 'Hello', 512);

        $this->assertEquals('OpenAI response', $result);

        $request = $history[0]['request'];
        $this->assertEquals('Bearer sk-openai', $request->getHeader('Authorization')[0]);

        $body = json_decode((string) $request->getBody(), true);
        $this->assertEquals('gpt-4', $body['model']);
        // OpenAI prepends system message.
        $this->assertCount(2, $body['messages']);
        $this->assertEquals('system', $body['messages'][0]['role']);
        $this->assertEquals('You are helpful.', $body['messages'][0]['content']);
        $this->assertEquals('user', $body['messages'][1]['role']);
        $this->assertEquals('Hello', $body['messages'][1]['content']);
    }

    public function testOpenAiDefaultUrl(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'openai', 'api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg');
        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('api.openai.com', $uri);
    }

    public function testOpenAiConversation(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'conv response']]],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'openai', 'api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $messages = [
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user', 'content' => 'Q2'],
        ];

        $client->conversation('system prompt', $messages);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        // system + 3 conversation messages = 4 total.
        $this->assertCount(4, $body['messages']);
        $this->assertEquals('system', $body['messages'][0]['role']);
    }

    // -------------------------------------------------------------------
    // Temperature — included when provided, omitted (provider default) when null
    // -------------------------------------------------------------------

    public function testAnthropicIncludesTemperatureWhenProvided(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'anthropic', 'api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg', 512, null, 0.0);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayHasKey('temperature', $body);
        $this->assertEquals(0.0, $body['temperature']);
    }

    public function testAnthropicOmitsTemperatureWhenNull(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'anthropic', 'api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        // Default call — no temperature argument.
        $client->message('sys', 'msg');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    public function testOpenAiIncludesTemperatureWhenProvided(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'openai', 'api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg', 512, null, 0.0);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayHasKey('temperature', $body);
        $this->assertEquals(0.0, $body['temperature']);
    }

    public function testOpenAiOmitsTemperatureWhenNull(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            ['provider' => 'openai', 'api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    public function testConversationIncludesTemperatureWhenProvided(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => $stack]),
        );

        $client->conversation('sys', [['role' => 'user', 'content' => 'hi']], 512, null, 0.0);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayHasKey('temperature', $body);
        $this->assertEquals(0.0, $body['temperature']);
    }

    // -------------------------------------------------------------------
    // Custom base URL
    // -------------------------------------------------------------------

    public function testCustomBaseUrl(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key', 'base_url' => 'https://proxy.example.com/v1/messages'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg');
        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('proxy.example.com', $uri);
    }

    // -------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------

    public function testHttpErrorWrappedInRuntimeException(): void
    {
        $mock = new MockHandler([
            new \GuzzleHttp\Exception\ConnectException('Connection refused', new \GuzzleHttp\Psr7\Request('POST', 'https://api.anthropic.com')),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');
        $client->message('sys', 'msg');
    }

    public function testHttp401ThrowsApiKeyInvalidException(): void
    {
        $mock = new MockHandler([
            new Response(401, [], json_encode(['error' => ['type' => 'authentication_error']])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'bad-key'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(ApiKeyInvalidException::class);
        $client->message('sys', 'msg');
    }

    public function testHttp429ThrowsRateLimitException(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '30'], json_encode(['error' => ['type' => 'rate_limit_error']])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(RateLimitException::class);
        $client->message('sys', 'msg');
    }

    public function testHttp429RetryAfterHeaderIsPreserved(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '60'], json_encode(['error' => ['type' => 'rate_limit_error']])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->message('sys', 'msg');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertEquals('60', $e->retryAfter);
        }
    }

    public function testHttp429WithoutRetryAfterHasNullRetryAfter(): void
    {
        $mock = new MockHandler([
            new Response(429, [], json_encode(['error' => ['type' => 'rate_limit_error']])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->message('sys', 'msg');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    public function testHttp500ThrowsRuntimeException(): void
    {
        $mock = new MockHandler([
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');
        $client->message('sys', 'msg');
    }

    // -------------------------------------------------------------------
    // Provider/model mismatch tripwire
    // -------------------------------------------------------------------

    public function testGatewayAliasRejectedByAnthropicNamesModelAndProvider(): void
    {
        // The Athenaeum failure, reproduced at the client boundary: a site
        // switched from the Amazee gateway to a direct Anthropic key while an
        // Amazee LiteLLM alias was still the configured model. Anthropic
        // rejects it as an unknown model, which used to surface as a generic
        // request failure with nothing in it to act on.
        $mock = new MockHandler([
            new Response(404, [], json_encode([
                'error' => ['type' => 'not_found_error', 'message' => 'model: claude-4-5-sonnet'],
            ])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key', 'model' => 'claude-4-5-sonnet'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->message('sys', 'msg');
            $this->fail('Expected ModelProviderMismatchException');
        } catch (ModelProviderMismatchException $e) {
            $this->assertSame('claude-4-5-sonnet', $e->getModel());
            $this->assertSame('anthropic', $e->getProvider());
            $this->assertStringContainsString('claude-4-5-sonnet', $e->getMessage());
            $this->assertStringContainsString('anthropic', $e->getMessage());
        }
    }

    public function testNativeModelRejectionStaysAGenericFailure(): void
    {
        // A provider-native model can be rejected for any number of reasons
        // that have nothing to do with the model name (deprecated snapshot,
        // account not entitled, malformed request). Naming a mismatch there
        // would send the operator after the wrong thing.
        $mock = new MockHandler([
            new Response(400, [], json_encode(['error' => ['type' => 'invalid_request_error']])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key', 'model' => 'claude-sonnet-4-5-20250929'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');
        $client->message('sys', 'msg');
    }

    public function testGatewayEndpointIsNeverReportedAsAMismatch(): void
    {
        // Traffic aimed at a gateway (a configured base_url) is *supposed* to
        // carry that gateway's aliases. Flagging them would fire the tripwire
        // on exactly the working configuration it exists to protect — a
        // healthy Amazee trial — on any unrelated 4xx.
        $mock = new MockHandler([
            new Response(400, [], json_encode(['error' => 'bad request'])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'openai',
                'api_key' => 'litellm-token',
                'model' => 'claude-4-5-sonnet',
                'base_url' => 'https://gateway.amazee.ai',
            ],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');
        $client->message('sys', 'msg');
    }

    public function testAuthAndRateLimitFailuresOutrankTheMismatchCheck(): void
    {
        // An expired key on a poisoned site is still an expired key: the 401
        // path must keep its own exception so the reauth flow still triggers.
        $mock = new MockHandler([
            new Response(401, [], json_encode(['error' => ['type' => 'authentication_error']])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'bad-key', 'model' => 'claude-4-5-sonnet'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $this->expectException(ApiKeyInvalidException::class);
        $client->message('sys', 'msg');
    }

    public function testPerCallModelOverrideIsTheOneChecked(): void
    {
        // messageForOperation() routes expansion through a separate model, so
        // the per-call override is what actually reaches the provider — and
        // therefore what the error has to name.
        $mock = new MockHandler([
            new Response(404, [], json_encode(['error' => ['type' => 'not_found_error']])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key', 'model' => 'claude-sonnet-4-5-20250929'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        try {
            $client->message('sys', 'msg', 1024, 'claude-4-5-haiku');
            $this->fail('Expected ModelProviderMismatchException');
        } catch (ModelProviderMismatchException $e) {
            $this->assertSame('claude-4-5-haiku', $e->getModel());
        }
    }

    public function testEmptyResponseReturnsEmptyString(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['content' => []])),
        ]);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)]),
        );

        $result = $client->message('sys', 'msg');
        $this->assertEquals('', $result);
    }

    // -------------------------------------------------------------------
    // Configurable timeout and api_version
    // -------------------------------------------------------------------

    public function testCustomTimeoutUsedInRequest(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['text' => 'response']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'test', 'timeout' => 60],
            new Client(['handler' => $stack]),
        );

        $client->message('system prompt', 'user message');

        $this->assertCount(1, $container);
        $options = $container[0]['options'];
        $this->assertEquals(60, $options['timeout']);
    }

    public function testDefaultTimeoutIs30(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['text' => 'response']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'test'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg');

        $this->assertEquals(30, $container[0]['options']['timeout']);
    }

    public function testCustomApiVersionUsedInAnthropicHeader(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['text' => 'response']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'test', 'api_version' => '2024-10-01'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg');

        $request = $container[0]['request'];
        $this->assertEquals('2024-10-01', $request->getHeaderLine('anthropic-version'));
    }

    public function testDefaultApiVersionIs20230601(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['text' => 'response']],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $client = new AiClient(
            [
                'provider' => 'anthropic','api_key' => 'test'],
            new Client(['handler' => $stack]),
        );

        $client->message('sys', 'msg');

        $request = $container[0]['request'];
        $this->assertEquals('2023-06-01', $request->getHeaderLine('anthropic-version'));
    }

    // -------------------------------------------------------------------
    // Streaming (conversationStream)
    // -------------------------------------------------------------------

    /**
     * @param list<Response|\Throwable> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function streamingClient(string $provider, array $responses, array &$history, array $extra = []): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new AiClient(['provider' => $provider, 'api_key' => 'test'] + $extra, new Client(['handler' => $stack]));
    }

    private static function anthropicStream(string ...$texts): string
    {
        $out = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n";
        $out .= "event: ping\ndata: {\"type\":\"ping\"}\n\n";
        foreach ($texts as $text) {
            $out .= "event: content_block_delta\ndata: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]) . "\n\n";
        }

        return $out . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
    }

    public function testAnthropicStreamYieldsTextDeltasInOrder(): void
    {
        $history = [];
        $client = $this->streamingClient('anthropic', [new Response(200, [], self::anthropicStream('The page ', "on breach\nnotification", ' says 72 hours.'))], $history);

        $pieces = iterator_to_array($client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']], 700), false);

        $this->assertSame(['The page ', "on breach\nnotification", ' says 72 hours.'], $pieces);
    }

    public function testOpenAiStreamYieldsDeltasUntilDone(): void
    {
        $history = [];
        $body = 'data: ' . json_encode(['choices' => [['delta' => ['role' => 'assistant']]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hello']]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['delta' => ['content' => ' there']]]]) . "\n\n"
            . "data: [DONE]\n\n"
            . 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'after done']]]]) . "\n\n";
        $client = $this->streamingClient('openai', [new Response(200, [], $body)], $history);

        $this->assertSame(['Hello', ' there'], iterator_to_array($client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']]), false));
    }

    public function testStreamReadsALastLineWithoutANewline(): void
    {
        $history = [];
        $body = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'only line'], 'finish_reason' => 'stop']]]);
        $client = $this->streamingClient('openai', [new Response(200, [], $body)], $history);

        $this->assertSame(['only line'], iterator_to_array($client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']]), false));
    }

    public function testAStreamThatClosesBeforeItsEndEventThrows(): void
    {
        $history = [];
        $cut = substr(self::anthropicStream('The page ', 'says 72 hours.'), 0, -strlen("event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n"));
        $client = $this->streamingClient('anthropic', [new Response(200, [], $cut)], $history);

        $yielded = [];
        try {
            foreach ($client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']]) as $piece) {
                $yielded[] = $piece;
            }
            $this->fail('Expected the stream to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame(['The page ', 'says 72 hours.'], $yielded);
            $this->assertStringContainsString('ended early', $e->getMessage());
        }
    }

    public function testStreamRequestIsTheConversationRequestPlusStream(): void
    {
        $history = [];
        $client = $this->streamingClient('anthropic', [
            new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => 'x']]])),
            new Response(200, [], self::anthropicStream('x')),
        ], $history, ['timeout' => 12]);
        $messages = [['role' => 'user', 'content' => 'hi']];

        $client->conversation('sys', $messages, 700, null, 0.5);
        iterator_to_array($client->conversationStream('sys', $messages, 700, null, 0.5));

        $plain = json_decode((string) $history[0]['request']->getBody(), true);
        $streamed = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertTrue($streamed['stream']);
        unset($streamed['stream']);
        $this->assertSame($plain, $streamed);
        $headers = static fn($request): array => array_diff_key($request->getHeaders(), ['Content-Length' => true]);
        $this->assertSame($headers($history[0]['request']), $headers($history[1]['request']));
        $this->assertTrue($history[1]['options']['stream']);
        $this->assertSame(12, $history[1]['options']['read_timeout']);
        $this->assertGreaterThanOrEqual(60, $history[1]['options']['timeout']);
    }

    public function testCacheSystemMarksTheAnthropicSystemPromptOnly(): void
    {
        $history = [];
        $client = $this->streamingClient('anthropic', [new Response(200, [], self::anthropicStream('x'))], $history);
        iterator_to_array($client->conversationStream('the system', [['role' => 'user', 'content' => 'hi']], 700, null, null, true));
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame([['type' => 'text', 'text' => 'the system', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);

        $history = [];
        $client = $this->streamingClient('openai', [new Response(200, [], "data: [DONE]\n\n")], $history);
        iterator_to_array($client->conversationStream('the system', [['role' => 'user', 'content' => 'hi']], 700, null, null, true));
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['role' => 'system', 'content' => 'the system'], $body['messages'][0]);
        $this->assertStringNotContainsString('cache_control', (string) $history[0]['request']->getBody());
    }

    public function testStreamMapsHttpErrorsBeforeTheFirstDelta(): void
    {
        $cases = [
            [new Response(401, [], '{}'), ApiKeyInvalidException::class],
            [new Response(429, ['Retry-After' => '7'], '{}'), RateLimitException::class],
            [new Response(500, [], '{}'), \RuntimeException::class],
        ];
        foreach ($cases as [$response, $expected]) {
            $history = [];
            $client = $this->streamingClient('anthropic', [$response], $history);
            $yielded = [];
            try {
                foreach ($client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']]) as $piece) {
                    $yielded[] = $piece;
                }
                $this->fail('Expected ' . $expected);
            } catch (\RuntimeException $e) {
                $this->assertInstanceOf($expected, $e);
                $this->assertSame([], $yielded);
                if ($e instanceof RateLimitException) {
                    $this->assertSame('7', $e->retryAfter);
                }
            }
        }
    }

    public function testTheFirstPieceIsYieldedBeforeTheRestOfTheStreamIsRead(): void
    {
        // A read of N bytes waits until N bytes arrive, so reading 8 KB at a
        // time held the first words back until 8 KB of events had streamed:
        // 4.7 s instead of 1.4 s on a real answer. At the first piece only
        // the first event may have been consumed.
        $events = self::anthropicStream(...array_fill(0, 200, 'word '));
        $body = \GuzzleHttp\Psr7\Utils::streamFor($events);
        $history = [];
        $client = $this->streamingClient('anthropic', [new Response(200, [], $body)], $history);

        $stream = $client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('word ', $stream->current());

        $this->assertGreaterThan(8192, strlen($events));
        $this->assertLessThan(400, $body->tell(), 'Only the events up to the first delta were read');
    }

    public function testStreamWithoutAKeyThrowsApiKeyMissing(): void
    {
        $client = new AiClient(['provider' => 'anthropic', 'api_key' => '']);
        $this->expectException(ApiKeyMissingException::class);
        iterator_to_array($client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']]));
    }

    public function testAnErrorEventMidStreamThrows(): void
    {
        $history = [];
        $body = "event: content_block_delta\ndata: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Part']]) . "\n\n"
            . "event: error\ndata: " . json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]) . "\n\n";
        $client = $this->streamingClient('anthropic', [new Response(200, [], $body)], $history);

        $yielded = [];
        try {
            foreach ($client->conversationStream('sys', [['role' => 'user', 'content' => 'hi']]) as $piece) {
                $yielded[] = $piece;
            }
            $this->fail('Expected the stream to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame(['Part'], $yielded);
            $this->assertStringContainsString('overloaded_error', $e->getMessage());
        }
    }
}
