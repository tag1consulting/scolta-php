<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiClient;
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

    // -------------------------------------------------------------------
    // reprovision() — the expired-key recovery entry point.
    // -------------------------------------------------------------------

    public function testReprovisionReplacesStoredCredentials(): void
    {
        // Unlike ensureAiAvailable(), stored credentials must NOT short-circuit:
        // they are known-bad (expired/revoked) when this path runs.
        $stored = [
            'litellm_token' => 'expired-tok',
            'litellm_api_url' => 'https://trial.amazee.ai',
            'region' => 'eu-west',
        ];
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturnCallback(function () use (&$stored) {
            return $stored;
        });
        $storage->expects($this->once())
            ->method('clear')
            ->willReturnCallback(function () use (&$stored): void {
                $stored = null;
            });
        $storage->expects($this->once())
            ->method('store')
            ->with('new-tok', 'https://trial.amazee.ai', 'eu-west');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'key' => [
                    'litellm_token' => 'new-tok',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'eu-west',
                ],
            ])),
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $result = AutoProvisioner::reprovision($storage, client: $client);

        $this->assertTrue($result);
    }

    public function testReprovisionReturnsFalseOnApiError(): void
    {
        $stored = [
            'litellm_token' => 'expired-tok',
            'litellm_api_url' => 'https://trial.amazee.ai',
            'region' => 'eu-west',
        ];
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturnCallback(function () use (&$stored) {
            return $stored;
        });
        $storage->expects($this->once())
            ->method('clear')
            ->willReturnCallback(function () use (&$stored): void {
                $stored = null;
            });
        $storage->expects($this->never())->method('store');

        $client = $this->makeClient([
            new Response(500, [], json_encode(['detail' => 'Server error.'])),
        ]);

        $result = AutoProvisioner::reprovision($storage, client: $client);

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------
    // Self-heal: an incomplete provision (credentials stored, but model
    // resolution failed so no model names were resolved) must not stay broken
    // forever. ensureAiAvailable() used to no-op on any stored credentials, so
    // the caller fell back to the dated config default the Amazee gateway
    // rejects with HTTP 400 and AI broke permanently. Re-resolving against the
    // STORED key (never a fresh trial) heals it.
    // -------------------------------------------------------------------

    public function testHalfProvisionedStateSelfHealsOnNextPass(): void
    {
        // Exercise the real bug sequence end to end: provision succeeds but its
        // /model/info call returns nothing (proxy briefly unreachable), then a
        // later pass re-resolves once models are reachable.
        $stored = null;
        $storage = $this->createMock(ConfigStorageInterface::class);
        // By-reference closure: load() must reflect the post-store() value (an
        // arrow fn would capture $stored by value at definition time).
        $storage->method('load')->willReturnCallback(function () use (&$stored) {
            return $stored;
        });
        // Exactly one trial is ever provisioned — the heal must not burn a new
        // trial key (a server-side-limited resource).
        $storage->expects($this->once())
            ->method('store')
            ->willReturnCallback(function (string $token, string $url, string $region) use (&$stored): void {
                $stored = [
                    'litellm_token' => $token,
                    'litellm_api_url' => $url,
                    'region' => $region,
                ];
            });

        $resolved = [];
        $onModelsResolved = function (string $aiModel, string $aiExpansionModel) use (&$resolved): void {
            $resolved[] = [$aiModel, $aiExpansionModel];
        };

        // Pass 1: trial provisioning succeeds; /model/info returns no models.
        $client1 = $this->makeClient([
            new Response(200, [], json_encode([
                'key' => [
                    'litellm_token' => 'new-tok',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'eu-west',
                ],
            ])),
            new Response(200, [], json_encode(['data' => []])),
        ]);
        $provisioned = AutoProvisioner::ensureAiAvailable(
            $storage,
            onModelsResolved: $onModelsResolved,
            client: $client1,
            hasResolvedModels: fn() => false,
        );
        $this->assertTrue($provisioned);   // a fresh trial WAS provisioned
        $this->assertNotNull($stored);     // credentials persisted
        $this->assertSame([], $resolved);  // but models stayed unresolved — the gap

        // Pass 2: credentials present, models still unresolved → self-heal by
        // re-resolving against the stored key. No second trial is provisioned.
        $client2 = $this->makeClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['model_name' => 'claude-sonnet-4-6'],
                    ['model_name' => 'claude-haiku-4-5'],
                ],
            ])),
        ]);
        $healed = AutoProvisioner::ensureAiAvailable(
            $storage,
            onModelsResolved: $onModelsResolved,
            client: $client2,
            hasResolvedModels: fn() => false,
        );

        $this->assertFalse($healed);  // not a new provision — a model-only heal
        $this->assertSame([['claude-sonnet-4-6', 'claude-haiku-4-5']], $resolved);
        // The resolved model is a real undated alias, never the dated default
        // that the gateway rejects.
        $this->assertNotSame(AiClient::DEFAULT_MODEL, $resolved[0][0]);
    }

    public function testDoesNotReResolveWhenModelsAlreadyResolved(): void
    {
        // Fully provisioned: the predicate reports models present, so no
        // /model/info call is made (re-resolving every request is wasteful).
        $storage = $this->makeStorage([
            'litellm_token' => 'stored-tok',
            'litellm_api_url' => 'https://trial.amazee.ai',
            'region' => 'eu-west',
        ]);
        $storage->expects($this->never())->method('store');

        // Any HTTP call would throw (no queued responses).
        $client = $this->makeClient([]);

        $called = false;
        $result = AutoProvisioner::ensureAiAvailable(
            $storage,
            onModelsResolved: function () use (&$called): void {
                $called = true;
            },
            client: $client,
            hasResolvedModels: fn() => true,
        );

        $this->assertFalse($result);
        $this->assertFalse($called);
    }

    public function testStoredCredentialsWithoutPredicateStayNoOp(): void
    {
        // Back-compat: a caller that does not pass hasResolvedModels keeps the
        // historical "stored credentials are complete" no-op (no HTTP call).
        $storage = $this->makeStorage([
            'litellm_token' => 'stored-tok',
            'litellm_api_url' => 'https://trial.amazee.ai',
            'region' => 'eu-west',
        ]);
        $storage->expects($this->never())->method('store');

        $client = $this->makeClient([]);

        $called = false;
        $result = AutoProvisioner::ensureAiAvailable(
            $storage,
            onModelsResolved: function () use (&$called): void {
                $called = true;
            },
            client: $client,
        );

        $this->assertFalse($result);
        $this->assertFalse($called);
    }
}
