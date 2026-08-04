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
use Tag1\Scolta\AiProvider\Amazee\AmazeeAccountUpgrader;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface;
use Tag1\Scolta\Config\AmazeeCredentials;
use Tag1\Scolta\Config\ApiKeyResolver;
use Tag1\Scolta\Config\ApiKeySource;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * The two policy invariants, asserted where they are decided.
 *
 * **A — no default provider.** Nothing ships with an AI provider selected.
 * `ai_provider` is empty until somebody chooses one, and while it is empty AI
 * is off: search still works, no provider is assumed, and Anthropic in
 * particular is not silently assumed.
 *
 * **B — Amazee is never auto-enabled.** No Amazee credential is provisioned
 * and no outbound Amazee call is made on any request, cron, install or
 * activation path for a site that did not opt in. The one automatic activity
 * permitted is re-resolving gateway model names against the key already on
 * disk, which only a site that already connected Amazee can reach.
 *
 * Every Amazee client here refuses to answer and records what was attempted,
 * so an unexpected outbound call is a hard failure naming the endpoint rather
 * than a silently swallowed error.
 */
class ManualProviderAndOptInTest extends TestCase
{
    /**
     * A client that answers nothing and records every outbound attempt.
     *
     * @param list<string> $attempts Filled by reference.
     */
    private function failOnCallClient(array &$attempts): AmazeeClient
    {
        $handler = function (RequestInterface $request, array $options) use (&$attempts) {
            $attempts[] = $request->getMethod() . ' ' . (string) $request->getUri();
            return Create::rejectionFor(
                new RequestException('No outbound Amazee call was expected here.', $request),
            );
        };

        return new AmazeeClient('https://api.amazee.ai', new Client(['handler' => $handler]));
    }

    /**
     * A real client (the class is final) answering a scripted response set.
     *
     * @param list<Response> $responses
     */
    private function scriptedClient(array $responses): AmazeeClient
    {
        return new AmazeeClient(
            'https://api.amazee.ai',
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    }

    /**
     * A scripted client that also records each outbound request body.
     *
     * @param list<string> $bodies Filled by reference.
     * @param list<Response> $responses
     */
    private function recordingClient(array &$bodies, array $responses): AmazeeClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::mapRequest(
            function (RequestInterface $request) use (&$bodies): RequestInterface {
                $bodies[] = (string) $request->getBody();

                return $request;
            },
        ));

        return new AmazeeClient('https://api.amazee.ai', new Client(['handler' => $stack]));
    }

    /** An in-memory store that records provenance, like a real adapter's. */
    private function provenanceStore(): ProvenanceAwareConfigStorageInterface
    {
        return new class implements ProvenanceAwareConfigStorageInterface {
            /** @var array{litellm_token: string, litellm_api_url: string, region: string}|null */
            public ?array $stored = null;
            public ?AmazeeConnectionSource $source = null;
            public int $storeCalls = 0;

            public function store(string $litellmToken, string $litellmApiUrl, string $region): void
            {
                $this->storeCalls++;
                $this->stored = [
                    'litellm_token' => $litellmToken,
                    'litellm_api_url' => $litellmApiUrl,
                    'region' => $region,
                ];
            }

            public function load(): ?array
            {
                return $this->stored;
            }

            public function clear(): void
            {
                $this->stored = null;
                $this->source = null;
            }

            public function storeConnectionSource(AmazeeConnectionSource $source): void
            {
                $this->source = $source;
            }

            public function loadConnectionSource(): ?AmazeeConnectionSource
            {
                return $this->source;
            }
        };
    }

    // -------------------------------------------------------------------
    // Invariant A — no default provider
    // -------------------------------------------------------------------

    /**
     * An untouched install runs a full AI cycle without making an AI call.
     *
     * With no provider selected and no key, `message()` must degrade rather
     * than construct a client against an assumed vendor. The adapter's client
     * factory is the assertion: reaching it at all is the failure.
     */
    public function testUnconfiguredInstallMakesNoAiCallOnAnyOperation(): void
    {
        $config = ScoltaConfig::fromArray([]);
        $this->assertSame('', $config->aiProvider);

        $adapter = new class ($config) extends AiServiceAdapter {
            public int $clientBuilds = 0;

            protected function createClient(): \Tag1\Scolta\AiClient
            {
                $this->clientBuilds++;

                throw new \LogicException('An AI client was built with no provider selected.');
            }
        };

        foreach (['expand', 'summarize', 'follow_up'] as $operation) {
            try {
                $adapter->messageForOperation($operation, 'system', 'user');
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(
                    \LogicException::class,
                    $e,
                    'An AI client must not be constructed when no provider is selected.',
                );
            }
        }

        $this->assertSame(
            0,
            $adapter->clientBuilds,
            'No AI client may be built while no provider is selected.',
        );
    }

    /**
     * Health reports AI off, and reports no provider — not "anthropic".
     */
    public function testHealthReportsAiOffRatherThanAssumingAnthropic(): void
    {
        $checker = new HealthChecker(ScoltaConfig::fromArray([]), sys_get_temp_dir(), null, null);
        $result = $checker->check();

        $this->assertSame('', $result['ai_provider']);
        $this->assertFalse($result['ai_provider_selected']);
        $this->assertFalse($result['ai_configured']);
        $this->assertFalse($result['ai_usable']);
    }

    /**
     * A key present with no provider selected still leaves AI off.
     *
     * This is the case a coalescing default used to hide: an environment
     * variable set before anybody chose a provider looked like a working
     * Anthropic install.
     */
    public function testKeyWithoutAProviderIsStillAiOff(): void
    {
        $resolved = ApiKeyResolver::resolve(['env' => 'sk-env']);
        $checker = new HealthChecker(
            ScoltaConfig::fromArray(['ai_api_key' => 'sk-env']),
            sys_get_temp_dir(),
            null,
            null,
            resolvedKey: $resolved,
        );
        $result = $checker->check();

        $this->assertSame('', $result['ai_provider']);
        $this->assertFalse($result['ai_provider_selected']);
        $this->assertFalse($result['ai_usable']);
    }

    // -------------------------------------------------------------------
    // Invariant B — Amazee is never auto-enabled
    // -------------------------------------------------------------------

    /**
     * The self-heal guard makes no outbound call when nothing is stored.
     *
     * This is the contract the Python and Node cores are held to as well: with
     * no stored credentials there is nothing to heal, and establishing a
     * connection is somebody else's explicit call.
     */
    public function testEnsureAiAvailableNeverMintsAndNeverCallsOut(): void
    {
        $attempts = [];
        $client = $this->failOnCallClient($attempts);

        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturn(null);
        $storage->expects($this->never())->method('store');

        $modelsResolved = false;
        $result = AutoProvisioner::ensureAiAvailable(
            $storage,
            hasExplicitApiKey: false,
            onModelsResolved: function () use (&$modelsResolved): void {
                $modelsResolved = true;
            },
            client: $client,
            hasResolvedModels: static fn(): bool => false,
        );

        $this->assertFalse($result);
        $this->assertSame([], $attempts, 'No outbound Amazee call may be made with nothing stored.');
        $this->assertFalse($modelsResolved);
    }

    /**
     * An explicit key short-circuits before the store is even read.
     */
    public function testEnsureAiAvailableWithAnExplicitKeyTouchesNothing(): void
    {
        $attempts = [];
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->never())->method('load');
        $storage->expects($this->never())->method('store');

        $this->assertFalse(AutoProvisioner::ensureAiAvailable(
            $storage,
            hasExplicitApiKey: true,
            client: $this->failOnCallClient($attempts),
        ));
        $this->assertSame([], $attempts);
    }

    /**
     * The self-heal path re-resolves against the stored key and mints nothing.
     *
     * The only automatic Amazee activity the policy permits, and it is reachable
     * only for a site whose operator already connected Amazee.
     */
    public function testSelfHealUsesTheStoredKeyAndDoesNotMint(): void
    {
        $attempts = [];
        $client = $this->failOnCallClient($attempts);

        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->method('load')->willReturn([
            'litellm_token' => 'stored-tok',
            'litellm_api_url' => 'https://gateway.amazee.ai',
            'region' => 'us-east',
        ]);
        $storage->expects($this->never())->method('store');

        AutoProvisioner::ensureAiAvailable(
            $storage,
            client: $client,
            hasResolvedModels: static fn(): bool => false,
        );

        // Exactly one endpoint is reached, and it is the model catalogue on the
        // stored gateway — never a provisioning endpoint.
        $this->assertCount(1, $attempts);
        $this->assertStringContainsString('/model/info', $attempts[0]);
        foreach ($attempts as $attempt) {
            $this->assertStringNotContainsString('trial', $attempt);
            $this->assertStringNotContainsString('generate', $attempt);
        }
    }

    // -------------------------------------------------------------------
    // Provenance — recorded at connect time, never guessed
    // -------------------------------------------------------------------

    /**
     * The demo records itself as the demo, and needs no email.
     */
    public function testDemoProvisionRecordsDemoProvenanceWithNoEmail(): void
    {
        $storage = $this->provenanceStore();
        $requests = [];
        $client = $this->recordingClient($requests, [
            new Response(200, [], (string) json_encode([
                'litellm_token' => 'demo-tok',
                'litellm_api_url' => 'https://gateway.amazee.ai',
                'region' => 'us-east',
            ])),
        ]);

        (new AmazeeTrialProvisioner($client, $storage))->provision();

        // No email is sent: trying the demo costs the operator no input.
        $this->assertSame(['{"email":""}'], $requests);

        $this->assertSame(AmazeeConnectionSource::Demo, $storage->loadConnectionSource());
        $this->assertSame(
            ApiKeySource::AmazeeDemo,
            ApiKeyResolver::resolve([], AmazeeCredentials::fromStorage($storage))->source,
        );
    }

    /**
     * Signing in to an account records the account, replacing a demo's mark.
     */
    public function testAccountSignInRecordsAccountProvenance(): void
    {
        $storage = $this->provenanceStore();
        $storage->store('demo-tok', 'https://gateway.amazee.ai', 'us-east');
        $storage->storeConnectionSource(AmazeeConnectionSource::Demo);

        $client = $this->scriptedClient([
            new Response(200, [], (string) json_encode([
                'litellm_token' => 'account-tok',
                'litellm_api_url' => 'https://ch.amazee.ai',
                'region' => 'ch',
            ])),
        ]);

        (new AmazeeAccountUpgrader($client, $storage))->upgrade('session', 'ch');

        $this->assertSame(AmazeeConnectionSource::Account, $storage->loadConnectionSource());
        $this->assertSame('account-tok', $storage->load()['litellm_token']);
        $this->assertSame(
            ApiKeySource::AmazeeAccount,
            ApiKeyResolver::resolve([], AmazeeCredentials::fromStorage($storage))->source,
        );
    }

    /**
     * A store that cannot record provenance still works, and claims nothing.
     */
    public function testProvenanceUnawareStoreConnectsAndReportsNoOrigin(): void
    {
        $storage = new class implements ConfigStorageInterface {
            /** @var array{litellm_token: string, litellm_api_url: string, region: string}|null */
            public ?array $stored = null;

            public function store(string $litellmToken, string $litellmApiUrl, string $region): void
            {
                $this->stored = [
                    'litellm_token' => $litellmToken,
                    'litellm_api_url' => $litellmApiUrl,
                    'region' => $region,
                ];
            }

            public function load(): ?array
            {
                return $this->stored;
            }

            public function clear(): void
            {
                $this->stored = null;
            }
        };

        $client = $this->scriptedClient([
            new Response(200, [], (string) json_encode([
                'litellm_token' => 'tok',
                'litellm_api_url' => 'https://gateway.amazee.ai',
                'region' => 'us-east',
            ])),
        ]);

        (new AmazeeTrialProvisioner($client, $storage))->provision();

        $resolved = ApiKeyResolver::resolve([], AmazeeCredentials::fromStorage($storage));
        $this->assertSame(ApiKeySource::Amazee, $resolved->source);
        $this->assertStringNotContainsString('demo', $resolved->describe());
    }

    /**
     * Clearing a connection drops its provenance with it.
     *
     * A stale mark left behind would be paired with the next connection, which
     * is a guess wearing a recorded fact's clothes.
     */
    public function testClearingCredentialsAlsoClearsProvenance(): void
    {
        $storage = $this->provenanceStore();
        $storage->store('tok', 'https://gateway.amazee.ai', 'us-east');
        $storage->storeConnectionSource(AmazeeConnectionSource::Demo);

        $storage->clear();

        $this->assertNull($storage->loadConnectionSource());
        $this->assertNull(AmazeeCredentials::fromStorage($storage));
    }
}
