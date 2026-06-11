<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\BudgetAwareProviderDecorator;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyInvalidException;

/**
 * Tests for expired-key detection and guarded re-provisioning.
 *
 * Regression (django demo, 2026-06-09): an Amazee trial key expired
 * server-side, every LiteLLM call returned 400 expired_key, and nothing
 * detected it — expand silently echoed the query for ~24h while
 * ensureAiAvailable() kept no-opping on the stored dead credentials.
 */
class KeyExpiryRecoveryTest extends TestCase
{
    private const FRESH_TRIAL_RESPONSE = '{"key": {"litellm_token": "sk-fresh-token", "litellm_api_url": "https://llm.test.amazee.ai", "region": "test-region"}}';
    private const MODEL_INFO_RESPONSE = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';

    private function makeAmazeeClient(array $responses, ?MockHandler &$mock = null): AmazeeClient
    {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

        return new AmazeeClient('https://api.amazee.ai', $httpClient);
    }

    private function makeRecovery(
        array $httpResponses,
        ?InMemoryAmazeeStorage &$storage = null,
        ?ArrayCacheDriver &$cache = null,
        ?MockHandler &$mock = null,
        int $failureWindowSeconds = 600,
    ): KeyExpiryRecovery {
        $storage ??= new InMemoryAmazeeStorage([
            'litellm_token' => 'sk-expired-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ]);
        $cache ??= new ArrayCacheDriver();

        return new KeyExpiryRecovery(
            storage: $storage,
            cache: $cache,
            client: $this->makeAmazeeClient($httpResponses, $mock),
            failureWindowSeconds: $failureWindowSeconds,
        );
    }

    // -------------------------------------------------------------------
    // isAuthFailure() classification
    // -------------------------------------------------------------------

    public function testApiKeyInvalidExceptionIsAuthFailure(): void
    {
        $e = new ApiKeyInvalidException('Scolta AI API key is invalid or expired.');

        $this->assertTrue(KeyExpiryRecovery::isAuthFailure($e));
    }

    public function testExpiredKeyMessageIsAuthFailure(): void
    {
        // LiteLLM returns the expired-key error inside an HTTP 400 body, which
        // AiClient wraps in a generic RuntimeException with the body in the message.
        $e = new \RuntimeException('Scolta AI API request failed: Client error: 400 {"error": {"message": "Authentication Error - Expired Key. Key Expired. code: expired_key"}}');

        $this->assertTrue(KeyExpiryRecovery::isAuthFailure($e));
    }

    public function testAuthFailureDetectedAnywhereInExceptionChain(): void
    {
        $inner = new \RuntimeException('code: invalid_api_key');
        $outer = new \RuntimeException('Scolta AI API request failed', 0, $inner);

        $this->assertTrue(KeyExpiryRecovery::isAuthFailure($outer));
    }

    public function testBudgetExceededIsNotAuthFailure(): void
    {
        // Budget exhaustion belongs to BudgetAwareProviderDecorator and must
        // never trigger re-provisioning (a fresh trial key would reset the
        // spend ceiling — that is the upgrade flow's job).
        $byMessage = new \RuntimeException(BudgetAwareProviderDecorator::BUDGET_MESSAGE);
        $byType = new AmazeeBudgetExceededException(new \RuntimeException('429'));

        $this->assertFalse(KeyExpiryRecovery::isAuthFailure($byMessage));
        $this->assertFalse(KeyExpiryRecovery::isAuthFailure($byType));
    }

    public function testGenericErrorIsNotAuthFailure(): void
    {
        $e = new \RuntimeException('Scolta AI API request failed: network timeout');

        $this->assertFalse(KeyExpiryRecovery::isAuthFailure($e));
    }

    // -------------------------------------------------------------------
    // handleAuthFailure() — detection, recovery, fresh credentials
    // -------------------------------------------------------------------

    public function testExpiredKeyTriggersOneReprovisionAndStoresFreshCreds(): void
    {
        $recovery = $this->makeRecovery(
            [new Response(200, [], self::FRESH_TRIAL_RESPONSE), new Response(200, [], self::MODEL_INFO_RESPONSE)],
            $storage,
            $cache,
            $mock,
        );

        $result = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertTrue($result);
        $this->assertSame('sk-fresh-token', $recovery->credentials()['litellm_token']);
        $this->assertSame(0, $mock->count(), 'Both provisioning calls (trial + model info) should have run');
        $this->assertFalse($recovery->isAuthFailing(), 'Successful recovery must clear the auth-failure marker');
    }

    public function testSecondFailureInWindowDoesNotReprovisionAgain(): void
    {
        $recovery = $this->makeRecovery(
            [new Response(200, [], self::FRESH_TRIAL_RESPONSE), new Response(200, [], self::MODEL_INFO_RESPONSE)],
            $storage,
            $cache,
            $mock,
        );

        $this->assertTrue($recovery->handleAuthFailure(new \RuntimeException('code: expired_key')));

        // A second auth failure inside the window must not hit the API again —
        // the MockHandler queue is empty, so any HTTP call would throw.
        $result = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertFalse($result);
    }

    public function testFailedReprovisionLeavesAuthFailureMarkerAndWaitsOutWindow(): void
    {
        $recovery = $this->makeRecovery(
            [new Response(500, [], '{"detail": "server error"}')],
            $storage,
            $cache,
            $mock,
        );

        $first = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));
        $second = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertFalse($first, 'Provisioning failure must report unrecovered');
        $this->assertFalse($second, 'Second failure must wait out the window, not retry the API');
        $this->assertTrue($recovery->isAuthFailing(), 'Health must keep seeing the failure');
        $this->assertSame(0, $mock->count(), 'Exactly one provisioning attempt');
    }

    public function testNonAuthFailureIsIgnored(): void
    {
        $recovery = $this->makeRecovery([], $storage, $cache, $mock);

        $result = $recovery->handleAuthFailure(new \RuntimeException(BudgetAwareProviderDecorator::BUDGET_MESSAGE));

        $this->assertFalse($result);
        $this->assertFalse($recovery->isAuthFailing(), 'Budget errors must not mark auth as failing');
        $this->assertSame('sk-expired-token', $storage->load()['litellm_token'], 'Storage untouched');
    }

    public function testModelsResolvedCallbackForwardedOnRecovery(): void
    {
        $recovery = $this->makeRecovery(
            [new Response(200, [], self::FRESH_TRIAL_RESPONSE), new Response(200, [], self::MODEL_INFO_RESPONSE)],
        );

        $resolved = null;
        $recovery->handleAuthFailure(
            new \RuntimeException('code: expired_key'),
            function (string $model, string $expansionModel) use (&$resolved): void {
                $resolved = [$model, $expansionModel];
            },
        );

        $this->assertSame(['claude-sonnet-4-5', 'claude-haiku-4-5'], $resolved);
    }

    // -------------------------------------------------------------------
    // Markers
    // -------------------------------------------------------------------

    public function testRecordAuthFailureIsVisibleToIsAuthFailing(): void
    {
        $recovery = $this->makeRecovery([], $storage, $cache);

        $this->assertFalse($recovery->isAuthFailing());

        $recovery->recordAuthFailure();

        $this->assertTrue($recovery->isAuthFailing());
        $this->assertNotNull($cache->get(KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE));
    }
}

/**
 * Minimal in-memory credential store.
 */
class InMemoryAmazeeStorage implements ConfigStorageInterface
{
    public function __construct(private ?array $stored = null) {}

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
}

/**
 * Minimal in-memory cache driver (TTL ignored — tests run within any window).
 */
class ArrayCacheDriver implements CacheDriverInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store[$key] = $value;
    }
}
