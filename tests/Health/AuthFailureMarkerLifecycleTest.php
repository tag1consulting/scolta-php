<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Health;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Exception\ModelProviderMismatchException;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * The auth-failure marker's full lifecycle, read through /health.
 *
 * Regression (Athenaeum Drupal demo): `/health` reported
 * `ai_configured: true`, `ai_usable: false`, `ai_auth_failing: true` on a site
 * whose credentials authenticated perfectly well. Two defects produced that
 * one line. The marker had a write path and no clear path, so it survived the
 * fix and kept health reporting a failure that was over; and it was set by a
 * provider/model mismatch, which is not an authentication failure at all, so
 * it was also lying about its own subject.
 *
 * These tests drive the real call path — an AiServiceAdapter with recovery
 * wired — and then assert against the health payload rather than against the
 * cache, because the payload is the surface an operator actually reads and the
 * only one whose contract matters here.
 */
class AuthFailureMarkerLifecycleTest extends TestCase
{
    private string $tempDir;

    private LifecycleCache $cache;

    private LifecycleStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scolta_marker_lifecycle_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        $this->cache = new LifecycleCache();
        $this->storage = new LifecycleStorage([
            'litellm_token' => 'sk-stored-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        @rmdir($this->tempDir);
    }

    // -------------------------------------------------------------------
    // Clear on success
    // -------------------------------------------------------------------

    public function testSuccessfulCallAfterAnAuthFailureClearsTheMarker(): void
    {
        $client = new ScriptedAiClient();
        $adapter = $this->makeAdapter($client);

        $client->throwNext(new \RuntimeException('Scolta AI API request failed: 400 code: expired_key'));

        try {
            $adapter->message('sys', 'user');
            $this->fail('The auth failure must propagate so the caller degrades gracefully');
        } catch (\RuntimeException $e) {
            // Expected: recovery records, it never retries.
        }

        $failing = $this->health();
        $this->assertTrue($failing['ai_auth_failing'], 'The failed call must be reported');
        $this->assertFalse($failing['ai_usable']);
        $this->assertSame('degraded', $failing['status']);

        // The operator fixes the credentials; the next call goes through. No
        // cache flush, no admin action, no waiting out the TTL.
        $adapter->message('sys', 'user');

        $recovered = $this->health();
        $this->assertFalse($recovered['ai_auth_failing'], 'A successful call must clear the marker');
        $this->assertNull($recovered['ai_auth_failing_since']);
        $this->assertNull($recovered['ai_auth_failing_ttl']);
        $this->assertTrue($recovered['ai_usable']);
        $this->assertSame('ok', $recovered['status']);
    }

    public function testSuccessOnTheConversationPathClearsTheMarker(): void
    {
        $client = new ScriptedAiClient();
        $adapter = $this->makeAdapter($client);

        $client->throwNext(new \RuntimeException('Scolta AI API request failed: 401 invalid_api_key'));

        try {
            $adapter->conversation('sys', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('The auth failure must propagate');
        } catch (\RuntimeException $e) {
        }

        $this->assertTrue($this->health()['ai_auth_failing']);

        $adapter->conversation('sys', [['role' => 'user', 'content' => 'hi']]);

        $this->assertFalse($this->health()['ai_auth_failing']);
    }

    public function testSuccessOnTheOperationPathClearsTheMarker(): void
    {
        // messageForOperation() is its own call path. The clear lives in the
        // shared wrapper precisely so no path can be left out of it.
        $client = new ScriptedAiClient();
        $adapter = $this->makeAdapter($client);

        $client->throwNext(new \RuntimeException('Scolta AI API request failed: 400 code: expired_key'));

        try {
            $adapter->messageForOperation('expand_query', 'sys', 'user');
            $this->fail('The auth failure must propagate');
        } catch (\RuntimeException $e) {
        }

        $this->assertTrue($this->health()['ai_auth_failing']);

        $adapter->messageForOperation('expand_query', 'sys', 'user');

        $this->assertFalse($this->health()['ai_auth_failing']);
    }

    public function testClearingTheAuthFailureLeavesTheUpgradePromptStanding(): void
    {
        // The two markers answer different questions. "The last call failed
        // authentication" is answered by a successful call; "this install's
        // credentials were rejected and an admin must re-authenticate" is not,
        // and its prompt must survive.
        $recovery = $this->makeRecovery();
        $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertTrue($recovery->isUpgradeNeeded());

        $recovery->noteCallSucceeded();

        $this->assertFalse($recovery->isAuthFailing());
        $this->assertTrue($recovery->isUpgradeNeeded(), 'Re-authentication is still outstanding');
    }

    // -------------------------------------------------------------------
    // Age the marker
    // -------------------------------------------------------------------

    public function testHealthReportsWhenTheAuthFailureWasRecorded(): void
    {
        $before = time();
        $this->makeRecovery()->recordAuthFailure();
        $after = time();

        $result = $this->health();

        $this->assertTrue($result['ai_auth_failing']);
        $this->assertIsInt($result['ai_auth_failing_since']);
        $this->assertGreaterThanOrEqual($before, $result['ai_auth_failing_since']);
        $this->assertLessThanOrEqual($after, $result['ai_auth_failing_since']);
        $this->assertSame(
            KeyExpiryRecovery::AUTH_FAILURE_TTL,
            $result['ai_auth_failing_ttl'],
            'The window the marker survives without a further failing call is part of the report',
        );
    }

    public function testAStaleMarkerIsVisiblyStale(): void
    {
        // The whole point of the field: an hour-old marker and a marker from a
        // second ago used to be the same `true`.
        $recordedAt = time() - 3000;
        $this->cache->set(KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE, $recordedAt, 3600);

        $result = $this->health();

        $this->assertSame($recordedAt, $result['ai_auth_failing_since']);
        $this->assertGreaterThan(
            2000,
            time() - $result['ai_auth_failing_since'],
            'The payload must let a reader compute the age of the marker',
        );
    }

    public function testAgeFieldsAreNullWhenNoFailureIsRecorded(): void
    {
        $result = $this->health();

        $this->assertFalse($result['ai_auth_failing']);
        $this->assertNull($result['ai_auth_failing_since']);
        $this->assertNull($result['ai_auth_failing_ttl']);
    }

    public function testATruthyMarkerWithoutATimestampReportsFailingWithUnknownAge(): void
    {
        // A marker written before the timestamp was read back. Reporting no
        // age is correct; inventing "now" would be a fresh lie in the field
        // added to stop the old one.
        $this->cache->set(KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE, true, 3600);

        $result = $this->health();

        $this->assertTrue($result['ai_auth_failing']);
        $this->assertNull($result['ai_auth_failing_since']);
        $this->assertSame(KeyExpiryRecovery::AUTH_FAILURE_TTL, $result['ai_auth_failing_ttl']);
    }

    // -------------------------------------------------------------------
    // Make the name true
    // -------------------------------------------------------------------

    public function testProviderModelMismatchDoesNotReportAnAuthFailure(): void
    {
        // The Athenaeum case. The mismatch carries the provider's original 4xx
        // as its previous exception, and a gateway that words an unknown-model
        // rejection as an authentication error used to set the marker through
        // the message chain.
        $client = new ScriptedAiClient();
        $adapter = $this->makeAdapter($client);

        $client->throwNext(new ModelProviderMismatchException(
            'scolta-fast',
            'anthropic',
            'Scolta AI model "scolta-fast" is not a recognised anthropic model ID.',
            new \RuntimeException(
                'Client error: `POST https://llm.test.amazee.ai/chat/completions` resulted in a '
                . '`400 Bad Request` response: {"error":{"message":"Authentication Error, '
                . 'key not allowed to access model scolta-fast"}}',
            ),
        ));

        try {
            $adapter->message('sys', 'user');
            $this->fail('The mismatch must propagate to its own handler');
        } catch (ModelProviderMismatchException $e) {
            $this->assertSame('scolta-fast', $e->getModel());
        }

        $result = $this->health();

        $this->assertFalse(
            $result['ai_auth_failing'],
            'A model the provider does not recognise is a configuration fault, not an auth rejection',
        );
        $this->assertNull($result['ai_auth_failing_since']);
    }

    public function testProviderModelMismatchDoesNotFlagTheSiteForReauthentication(): void
    {
        $recovery = $this->makeRecovery();

        $recovery->handleAuthFailure(new ModelProviderMismatchException(
            'scolta-fast',
            'anthropic',
            'Scolta AI model "scolta-fast" is not a recognised anthropic model ID.',
            new \RuntimeException('400: authentication error'),
        ));

        $this->assertFalse($recovery->isAuthFailing());
        $this->assertFalse(
            $recovery->isUpgradeNeeded(),
            'Asking an admin to re-authenticate would send them to fix the wrong thing',
        );
    }

    public function testTransportFailureDoesNotReportAnAuthFailure(): void
    {
        $client = new ScriptedAiClient();
        $adapter = $this->makeAdapter($client);

        $client->throwNext(new \RuntimeException('Scolta AI API request failed: cURL error 28: timeout'));

        try {
            $adapter->message('sys', 'user');
            $this->fail('The transport failure must propagate');
        } catch (\RuntimeException $e) {
        }

        $this->assertFalse($this->health()['ai_auth_failing']);
    }

    public function testGenericProviderRejectionDoesNotReportAnAuthFailure(): void
    {
        $client = new ScriptedAiClient();
        $adapter = $this->makeAdapter($client);

        $client->throwNext(new \RuntimeException(
            'Scolta AI API request failed: Client error: `POST /v1/messages` resulted in a '
            . '`400 Bad Request` response: {"type":"error","error":{"type":"invalid_request_error",'
            . '"message":"max_tokens: must be greater than 0"}}',
        ));

        try {
            $adapter->message('sys', 'user');
            $this->fail('The provider rejection must propagate');
        } catch (\RuntimeException $e) {
        }

        $this->assertFalse($this->health()['ai_auth_failing']);
    }

    public function testAGenuineAuthRejectionStillSetsTheMarker(): void
    {
        // The detection is not what was wrong. Narrowing the classification
        // must not cost the signal the marker exists for.
        $client = new ScriptedAiClient();
        $adapter = $this->makeAdapter($client);

        $client->throwNext(new \Tag1\Scolta\Exception\ApiKeyInvalidException(
            'Scolta AI API key is invalid or expired.',
        ));

        try {
            $adapter->message('sys', 'user');
            $this->fail('The auth failure must propagate');
        } catch (\RuntimeException $e) {
        }

        $result = $this->health();

        $this->assertTrue($result['ai_auth_failing']);
        $this->assertFalse($result['ai_usable']);
        $this->assertSame('degraded', $result['status']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * The health payload, built over the same cache the call path writes to.
     *
     * @return array<string, mixed>
     */
    private function health(): array
    {
        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-configured']);

        return (new HealthChecker($config, $this->tempDir, null, null, $this->cache))->check();
    }

    private function makeRecovery(): KeyExpiryRecovery
    {
        return new KeyExpiryRecovery(storage: $this->storage, cache: $this->cache);
    }

    private function makeAdapter(ScriptedAiClient $client): AiServiceAdapter
    {
        $adapter = new class (ScoltaConfig::fromArray([]), $client) extends AiServiceAdapter {
            private AiClient $scripted;

            public function __construct(ScoltaConfig $config, AiClient $scripted)
            {
                parent::__construct($config);
                $this->scripted = $scripted;
            }

            protected function createClient(): AiClient
            {
                return $this->scripted;
            }
        };

        $adapter->setKeyExpiryRecovery($this->makeRecovery());

        return $adapter;
    }
}

/**
 * An AiClient that throws what the test tells it to, once, then succeeds.
 */
class ScriptedAiClient extends AiClient
{
    private ?\RuntimeException $next = null;

    public function __construct()
    {
        parent::__construct([]);
    }

    public function throwNext(\RuntimeException $e): void
    {
        $this->next = $e;
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens = 1024, ?string $model = null, ?float $temperature = null): string
    {
        return $this->respond();
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens = 1024, ?string $model = null, ?float $temperature = null): string
    {
        return $this->respond();
    }

    private function respond(): string
    {
        if ($this->next !== null) {
            $toThrow = $this->next;
            $this->next = null;

            throw $toThrow;
        }

        return 'ok';
    }
}

class LifecycleCache implements CacheDriverInterface
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

class LifecycleStorage implements ConfigStorageInterface
{
    /** @var array{litellm_token: string, litellm_api_url: string, region: string}|null */
    private ?array $credentials;

    /**
     * @param array{litellm_token: string, litellm_api_url: string, region: string}|null $credentials
     */
    public function __construct(?array $credentials = null)
    {
        $this->credentials = $credentials;
    }

    public function store(string $litellmToken, string $litellmApiUrl, string $region): void
    {
        $this->credentials = [
            'litellm_token' => $litellmToken,
            'litellm_api_url' => $litellmApiUrl,
            'region' => $region,
        ];
    }

    public function load(): ?array
    {
        return $this->credentials;
    }

    public function clear(): void
    {
        $this->credentials = null;
    }
}
