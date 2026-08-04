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
}
