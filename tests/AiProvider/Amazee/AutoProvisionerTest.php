<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

class AutoProvisionerTest extends TestCase
{
    private function makeClient(array $responses): AmazeeClient
    {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        return new AmazeeClient('https://api.amazee.ai', $httpClient);
    }

    private function makeStorage(?array $stored = null): ConfigStorageInterface
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturn($stored);
        return $storage;
    }

    // -------------------------------------------------------------------
    // Guard conditions.
    // -------------------------------------------------------------------

    public function testReturnsFalseWhenExplicitApiKeyConfigured(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->never())->method('load');
        $storage->expects($this->never())->method('store');

        $result = AutoProvisioner::ensureAiAvailable($storage, hasExplicitApiKey: true);

        $this->assertFalse($result);
    }

    public function testReturnsFalseWhenCredentialsAlreadyStored(): void
    {
        $storage = $this->makeStorage([
            'litellm_token' => 'existing-tok',
            'litellm_api_url' => 'https://trial.amazee.ai',
            'region' => 'us-east',
        ]);
        $storage->expects($this->never())->method('store');

        $client = $this->makeClient([]);

        $result = AutoProvisioner::ensureAiAvailable($storage, client: $client);

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------
    // Successful provisioning.
    // -------------------------------------------------------------------

    public function testReturnsTrueOnSuccessfulProvisioning(): void
    {
        $stored = null;
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturnCallback(fn() => $stored);
        $storage->expects($this->once())
            ->method('store')
            ->willReturnCallback(function (string $token, string $url, string $region) use (&$stored): void {
                $stored = compact('token', 'url', 'region');
            });

        $client = $this->makeClient([
            // Trial provisioning response (nested key format).
            new Response(200, [], json_encode([
                'key' => [
                    'litellm_token' => 'new-tok',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'eu-west',
                ],
            ])),
            // Model list (/model/info on the LiteLLM endpoint).
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $result = AutoProvisioner::ensureAiAvailable($storage, client: $client);

        $this->assertTrue($result);
    }

    public function testCallsOnModelsResolvedWhenModelsAvailable(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturn(null);
        $storage->method('store');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'key' => [
                    'litellm_token' => 'new-tok',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'us-east',
                ],
            ])),
            new Response(200, [], json_encode([
                'data' => [
                    ['model_name' => 'claude-sonnet-4-6'],
                    ['model_name' => 'claude-haiku-4-5'],
                ],
            ])),
        ]);

        $captured = [];
        $onModelsResolved = function (string $aiModel, string $aiExpansionModel) use (&$captured): void {
            $captured = compact('aiModel', 'aiExpansionModel');
        };

        AutoProvisioner::ensureAiAvailable($storage, onModelsResolved: $onModelsResolved, client: $client);

        $this->assertSame('claude-sonnet-4-6', $captured['aiModel']);
        $this->assertSame('claude-haiku-4-5', $captured['aiExpansionModel']);
    }

    public function testDoesNotCallOnModelsResolvedWhenNoModelsAvailable(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturn(null);
        $storage->method('store');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'key' => [
                    'litellm_token' => 'new-tok',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'us-east',
                ],
            ])),
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $called = false;
        AutoProvisioner::ensureAiAvailable(
            $storage,
            onModelsResolved: function () use (&$called): void {
                $called = true;
            },
            client: $client,
        );

        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------
    // Failure handling.
    // -------------------------------------------------------------------

    public function testReturnsFalseWithoutThrowingOnApiError(): void
    {
        $storage = $this->makeStorage(null);
        $storage->expects($this->never())->method('store');

        $client = $this->makeClient([
            new Response(500, [], json_encode(['detail' => 'Server error.'])),
        ]);

        $result = AutoProvisioner::ensureAiAvailable($storage, client: $client);

        $this->assertFalse($result);
    }
}
