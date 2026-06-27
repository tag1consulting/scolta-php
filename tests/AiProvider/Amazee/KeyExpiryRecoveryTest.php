<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\BudgetAwareProviderDecorator;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyInvalidException;

/**
 * Tests for expired/revoked-credential detection and graceful degradation.
 *
 * Regression (django demo, 2026-06-09): Amazee credentials were revoked
 * server-side, every LiteLLM call returned 400 expired_key, and nothing
 * detected it — expand silently echoed the query for ~24h while
 * ensureAiAvailable() kept no-opping on the stored dead credentials. When the
 * stored credentials stop being accepted, AI must turn off and the site must be
 * flagged for an admin to re-authenticate; the stored credentials are left in
 * place and no replacement credentials are requested on this path.
 */
class KeyExpiryRecoveryTest extends TestCase
{
    private function makeRecovery(
        ?InMemoryAmazeeStorage &$storage = null,
        ?ArrayCacheDriver &$cache = null,
    ): KeyExpiryRecovery {
        $storage ??= new InMemoryAmazeeStorage([
            'litellm_token' => 'sk-stored-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ]);
        $cache ??= new ArrayCacheDriver();

        return new KeyExpiryRecovery(storage: $storage, cache: $cache);
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
        // Budget exhaustion belongs to BudgetAwareProviderDecorator and follows
        // the budget path, never this credential-handling path.
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
    // handleAuthFailure() — degrade, record health, flag for re-auth
    // -------------------------------------------------------------------

    public function testExpiredCredentialsDegradeAndFlagForUpgrade(): void
    {
        $storage = new InMemoryAmazeeStorage([
            'litellm_token' => 'sk-stored-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ]);
        $recovery = $this->makeRecovery($storage, $cache);

        $result = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertFalse($result, 'There is nothing to retry; the caller must degrade gracefully');
        $this->assertSame(
            'sk-stored-token',
            $storage->load()['litellm_token'],
            'Stored credentials must be left intact',
        );
        $this->assertTrue($recovery->isAuthFailing(), 'Health must report AI as degraded');
        $this->assertTrue($recovery->isUpgradeNeeded(), 'The site must be flagged for admin re-authentication');
    }

    public function testStoredCredentialsAreNeverDiscardedOnAuthFailure(): void
    {
        // The credential store must not be touched: leaving it in place is what
        // keeps the failure path from requesting any replacement credentials.
        $storage = new TripwireStorage([
            'litellm_token' => 'sk-stored-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ]);
        $recovery = new KeyExpiryRecovery(storage: $storage, cache: new ArrayCacheDriver());

        $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertFalse($storage->wasCleared, 'clear() must never be called on an auth failure');
        $this->assertFalse($storage->wasStored, 'store() must never be called on an auth failure');
    }

    public function testRepeatedFailuresKeepFlagsSetWithoutTouchingStorage(): void
    {
        $storage = new TripwireStorage([
            'litellm_token' => 'sk-stored-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ]);
        $recovery = new KeyExpiryRecovery(storage: $storage, cache: new ArrayCacheDriver());

        $this->assertFalse($recovery->handleAuthFailure(new \RuntimeException('code: expired_key')));
        $this->assertFalse($recovery->handleAuthFailure(new \RuntimeException('code: expired_key')));

        $this->assertTrue($recovery->isAuthFailing());
        $this->assertTrue($recovery->isUpgradeNeeded());
        $this->assertFalse($storage->wasCleared);
        $this->assertFalse($storage->wasStored);
    }

    public function testNonAuthFailureIsIgnored(): void
    {
        $recovery = $this->makeRecovery($storage, $cache);

        $result = $recovery->handleAuthFailure(new \RuntimeException(BudgetAwareProviderDecorator::BUDGET_MESSAGE));

        $this->assertFalse($result);
        $this->assertFalse($recovery->isAuthFailing(), 'Budget errors must not mark auth as failing');
        $this->assertFalse($recovery->isUpgradeNeeded(), 'Budget errors must not flag for re-authentication');
        $this->assertSame('sk-stored-token', $storage->load()['litellm_token'], 'Storage untouched');
    }

    // -------------------------------------------------------------------
    // Markers
    // -------------------------------------------------------------------

    public function testRecordAuthFailureIsVisibleToIsAuthFailing(): void
    {
        $recovery = $this->makeRecovery($storage, $cache);

        $this->assertFalse($recovery->isAuthFailing());

        $recovery->recordAuthFailure();

        $this->assertTrue($recovery->isAuthFailing());
        $this->assertNotNull($cache->get(KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE));
    }

    public function testUpgradeNeededMarkerCanBeSetAndCleared(): void
    {
        $recovery = $this->makeRecovery($storage, $cache);

        $this->assertFalse($recovery->isUpgradeNeeded());

        $recovery->flagUpgradeNeeded();
        $this->assertTrue($recovery->isUpgradeNeeded());

        $recovery->clearUpgradeNeeded();
        $this->assertFalse($recovery->isUpgradeNeeded(), 'A completed re-authentication must clear the prompt');
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
 * Credential store that records whether its mutators were invoked, so a test
 * can assert the stored credentials were left untouched.
 */
class TripwireStorage implements ConfigStorageInterface
{
    public bool $wasCleared = false;

    public bool $wasStored = false;

    public function __construct(private ?array $stored = null) {}

    public function store(string $litellmToken, string $litellmApiUrl, string $region): void
    {
        $this->wasStored = true;
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
        $this->wasCleared = true;
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
