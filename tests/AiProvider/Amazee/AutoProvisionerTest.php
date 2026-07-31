<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
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

    /**
     * A client that answers nothing and records every outbound attempt.
     *
     * `$attempts` collects "METHOD uri" for anything the code under test tries
     * to send, so a test can assert on the exact set of endpoints reached
     * rather than on a thrown transport error.
     *
     * @param list<string> $attempts Filled by reference.
     */
    private function makeSpyClient(array &$attempts): AmazeeClient
    {
        $handler = function (RequestInterface $request, array $options) use (&$attempts) {
            $attempts[] = $request->getMethod() . ' ' . (string) $request->getUri();
            return Create::rejectionFor(
                new RequestException('No outbound call was expected here.', $request),
            );
        };

        return new AmazeeClient('https://api.amazee.ai', new Client(['handler' => $handler]));
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
    // No stored credentials: the helper does not establish a connection.
    //
    // Establishing a managed gateway connection is an explicit caller action
    // (AmazeeTrialProvisioner::provision(), reached only from an operator-
    // initiated enable path). This helper never does it on the caller's
    // behalf, so with nothing stored it must return false having sent
    // nothing and stored nothing.
    // -------------------------------------------------------------------

    public function testMakesNoOutboundCallWhenNothingIsStored(): void
    {
        $storage = $this->makeStorage(null);
        // Nothing is established, so nothing is ever persisted.
        $storage->expects($this->never())->method('store');

        $attempts = [];
        $client = $this->makeSpyClient($attempts);

        $result = AutoProvisioner::ensureAiAvailable($storage, client: $client);

        $this->assertFalse($result);
        $this->assertSame([], $attempts, 'The helper must send nothing when no credentials are stored.');
    }

    public function testMakesNoOutboundCallWhenNothingIsStoredAndCallbacksAreSupplied(): void
    {
        // Same contract with the full callback set an adapter passes: supplying
        // callbacks does not re-enable anything. There is no flag that does.
        $storage = $this->makeStorage(null);
        $storage->expects($this->never())->method('store');

        $attempts = [];
        $client = $this->makeSpyClient($attempts);

        $called = false;
        $result = AutoProvisioner::ensureAiAvailable(
            $storage,
            hasExplicitApiKey: false,
            onModelsResolved: function () use (&$called): void {
                $called = true;
            },
            client: $client,
            hasResolvedModels: fn() => false,
        );

        $this->assertFalse($result);
        $this->assertFalse($called);
        $this->assertSame([], $attempts);
    }

    // -------------------------------------------------------------------
    // Self-heal: credentials are stored, but their model names were never
    // resolved. That state must not stay broken forever — the caller would
    // fall back to the dated config default the Amazee gateway rejects with
    // HTTP 400 and AI would break permanently. Re-resolving against the
    // STORED key heals it, and reaches only the model endpoint.
    // -------------------------------------------------------------------

    public function testStoredCredentialsWithoutModelsSelfHeal(): void
    {
        $storage = $this->makeStorage([
            'litellm_token' => 'stored-tok',
            'litellm_api_url' => 'https://gateway.amazee.ai',
            'region' => 'eu-west',
        ]);
        // The heal reads the stored credentials; it never writes new ones.
        $storage->expects($this->never())->method('store');

        $resolved = [];
        $onModelsResolved = function (string $aiModel, string $aiExpansionModel) use (&$resolved): void {
            $resolved[] = [$aiModel, $aiExpansionModel];
        };

        // Exactly one queued response: the model list. Any second call would
        // throw on the empty mock queue.
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['model_name' => 'claude-sonnet-4-6'],
                    ['model_name' => 'claude-haiku-4-5'],
                ],
            ])),
        ]);

        $result = AutoProvisioner::ensureAiAvailable(
            $storage,
            onModelsResolved: $onModelsResolved,
            client: $client,
            hasResolvedModels: fn() => false,
        );

        $this->assertFalse($result);
        $this->assertSame([['claude-sonnet-4-6', 'claude-haiku-4-5']], $resolved);
        // The resolved model is a real undated alias, never the dated default
        // that the gateway rejects.
        $this->assertNotSame(AiClient::DEFAULT_MODEL, $resolved[0][0]);
    }

    public function testSelfHealReachesOnlyTheModelEndpoint(): void
    {
        // The heal must resolve models against the stored key and touch
        // nothing else — no second endpoint, no second connection.
        $storage = $this->makeStorage([
            'litellm_token' => 'stored-tok',
            'litellm_api_url' => 'https://gateway.amazee.ai',
            'region' => 'eu-west',
        ]);
        $storage->expects($this->never())->method('store');

        $attempts = [];
        $client = $this->makeSpyClient($attempts);

        try {
            AutoProvisioner::ensureAiAvailable(
                $storage,
                client: $client,
                hasResolvedModels: fn() => false,
            );
        } catch (\Throwable) {
            // The spy answers nothing; only the attempted endpoints matter.
        }

        $this->assertCount(1, $attempts);
        $this->assertStringContainsString('gateway.amazee.ai', $attempts[0]);
        $this->assertStringContainsString('/model/info', $attempts[0]);
    }

    public function testDoesNotCallOnModelsResolvedWhenNoModelsAreAvailable(): void
    {
        $storage = $this->makeStorage([
            'litellm_token' => 'stored-tok',
            'litellm_api_url' => 'https://gateway.amazee.ai',
            'region' => 'eu-west',
        ]);
        $storage->expects($this->never())->method('store');

        $client = $this->makeClient([
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $called = false;
        $result = AutoProvisioner::ensureAiAvailable(
            $storage,
            onModelsResolved: function () use (&$called): void {
                $called = true;
            },
            client: $client,
            hasResolvedModels: fn() => false,
        );

        $this->assertFalse($result);
        $this->assertFalse($called);
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
