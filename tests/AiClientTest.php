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

class AiClientTest extends TestCase
{
    // -------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------

    public function testDefaultProviderIsAnthropic(): void
    {
        $mock = new MockHandler([]);
        $client = new AiClient(
            ['api_key' => 'test'],
            new Client(['handler' => HandlerStack::create($mock)])
        );
        // If we could inspect the private field, we'd check. Instead we verify
        // indirectly through the request format (tested below).
        $this->assertInstanceOf(AiClient::class, $client);
    }

    public function testThrowsWhenNoApiKey(): void
    {
        $client = new AiClient(['api_key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key not configured');
        $client->message('system', 'hello');
    }

    public function testThrowsWhenApiKeyMissing(): void
    {
        $client = new AiClient([]);

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
            new Client(['handler' => $stack])
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
            ['api_key' => 'key'],
            new Client(['handler' => $stack])
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
            ['api_key' => 'key', 'model' => 'default-model'],
            new Client(['handler' => $stack])
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
            ['api_key' => 'key'],
            new Client(['handler' => $stack])
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
            new Client(['handler' => $stack])
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
            new Client(['handler' => $stack])
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
            new Client(['handler' => $stack])
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
            ['api_key' => 'key', 'base_url' => 'https://proxy.example.com/v1/messages'],
            new Client(['handler' => $stack])
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
            ['api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)])
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API request failed');
        $client->message('sys', 'msg');
    }

    public function testEmptyResponseReturnsEmptyString(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['content' => []])),
        ]);

        $client = new AiClient(
            ['api_key' => 'key'],
            new Client(['handler' => HandlerStack::create($mock)])
        );

        $result = $client->message('sys', 'msg');
        $this->assertEquals('', $result);
    }
}
