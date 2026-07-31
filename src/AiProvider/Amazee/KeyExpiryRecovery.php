<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ModelProviderMismatchException;

/**
 * Detects Amazee credential auth failures at call time and degrades cleanly.
 *
 * Amazee.ai credentials are revoked server-side when their lifecycle
 * ends. The expiry is NOT announced at issue time (verified against the
 * live API: `/auth/generate-trial-access` returns only `created_at`, and the
 * LiteLLM key's own `expires` is a year out while observed revocation is on the
 * order of a day) — so the only reliable signal is the auth failure the LiteLLM
 * proxy returns on the next inference call. Without this class that failure was
 * swallowed by the expand/summarize graceful-degrade path while
 * {@see AutoProvisioner::ensureAiAvailable()} kept no-opping on the stored dead
 * credentials: AI stayed down fleet-wide with health reporting
 * `ai_configured: true` (django demo outage, 2026-06-09).
 *
 * On an auth-class failure this class leaves AI off and records two
 * cache-backed markers (any {@see CacheDriverInterface}) so the rest of the
 * system reflects the real state across requests:
 *  - an auth-failure marker, recorded on every detected failure and read by
 *    {@see \Tag1\Scolta\Health\HealthChecker} so "AI configured" stops implying
 *    "AI usable"; it ages out so a transient blip self-clears once calls
 *    succeed again;
 *  - an upgrade-needed marker, set when the stored credentials are no longer
 *    accepted, that persists until the admin re-authenticates. Adapter admin
 *    UIs read {@see isUpgradeNeeded()} to prompt the admin to continue by
 *    entering an email, which runs the verification flow
 *    ({@see AmazeeClient::requestVerificationCode()} +
 *    {@see AmazeeClient::signIn()}, used by {@see AmazeeAccountUpgrader}). On a
 *    successful upgrade the adapter calls {@see clearUpgradeNeeded()}.
 *
 * The stored credentials are never cleared and no new credentials are requested
 * on this path; recovery is a deliberate, admin-initiated step. Budget-
 * exhaustion errors are excluded — those belong to
 * {@see BudgetAwareProviderDecorator} and follow the budget path, not this one.
 *
 * @since 1.0.4
 * @stability experimental
 */
final class KeyExpiryRecovery
{
    /**
     * Cache key for the "last AI call failed authentication" marker.
     *
     * Health checks read this (see HealthChecker) to report AI as unusable
     * while the stored credentials are known-bad. Public so adapters and
     * health wiring reference one definition.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public const CACHE_KEY_AUTH_FAILURE = 'scolta_amazee_auth_failure';

    /**
     * Cache key for the persistent "credentials no longer accepted, admin
     * must re-authenticate" marker.
     *
     * Unlike the auth-failure marker this does NOT age out on its own: once the
     * stored credentials stop being accepted, AI stays off until the admin
     * completes the email re-authentication flow and the adapter clears it via
     * {@see clearUpgradeNeeded()}. Public so adapter admin UIs reference one
     * definition.
     *
     * @since 1.0.5
     * @stability experimental
     */
    public const CACHE_KEY_UPGRADE_NEEDED = 'scolta_amazee_upgrade_needed';

    /**
     * How long a recorded auth failure keeps health reporting AI unusable
     * before a fresh failing call must re-confirm it, in seconds.
     *
     * Public so {@see \Tag1\Scolta\Health\HealthChecker} can state the window
     * in the health payload. A marker with no stated lifetime is a marker an
     * operator has to guess at: paired with `ai_auth_failing_since` this says
     * how long the report can outlive the condition when no AI call has
     * succeeded in the meantime.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public const AUTH_FAILURE_TTL = 3600;

    /**
     * How long the upgrade-needed marker is retained, in seconds.
     *
     * Long enough to outlast any cache backend's practical eviction window so
     * the prompt does not disappear on its own; the marker is meant to be
     * cleared explicitly by {@see clearUpgradeNeeded()} once the admin
     * re-authenticates, not to expire.
     */
    private const UPGRADE_NEEDED_TTL = 31536000;

    /**
     * Message substrings that identify an auth-class failure from the LiteLLM
     * proxy. The proxy returns the expired/invalid-key error inside an HTTP
     * 400/401 body, which AiClient preserves in the exception message chain
     * (a 401 additionally becomes ApiKeyInvalidException, matched by type).
     */
    private const AUTH_FAILURE_MARKERS = [
        'expired_key',
        'invalid_api_key',
        'authentication error',
        'invalid proxy server token',
    ];

    /**
     * @param ConfigStorageInterface $storage Adapter credential store (same instance the provisioner uses).
     * @param CacheDriverInterface   $cache   Cache for the failure/upgrade markers.
     * @param LoggerInterface        $logger  PSR-3 logger (defaults to NullLogger).
     */
    public function __construct(
        private readonly ConfigStorageInterface $storage,
        private readonly CacheDriverInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Whether an exception (anywhere in its chain) is an auth-class failure of
     * the stored Amazee credentials.
     *
     * Two error classes return false even though they also surface as 4xx
     * responses, because each already has a signal of its own and the marker
     * this feeds is named for authentication specifically:
     *  - budget exhaustion, which routes to {@see BudgetAwareProviderDecorator};
     *  - a provider/model mismatch, which routes to
     *    {@see \Tag1\Scolta\Http\AiEndpointHandler::REASON_MODEL_MISMATCH}.
     *
     * The mismatch exclusion is by exception type and is checked before the
     * message chain is walked, because the chain is exactly what mis-classified
     * it: {@see ModelProviderMismatchException} carries the provider's original
     * 4xx as its previous, and Guzzle puts a summary of the response body into
     * that exception's message. A gateway that words an unknown-model rejection
     * as an authentication error therefore matched
     * {@see self::AUTH_FAILURE_MARKERS} and set the auth-failure marker for a
     * failure that had nothing to do with credentials (Athenaeum Drupal demo:
     * health reported `ai_auth_failing: true` while the real fault was a
     * configured model the effective provider does not recognise).
     *
     * The marker list itself is deliberately unchanged. Its narrowness is what
     * keeps the marker meaning what its name says, and widening or trimming it
     * would trade one kind of wrong report for another.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public static function isAuthFailure(\Throwable $e): bool
    {
        if (BudgetAwareProviderDecorator::isBudgetError($e)) {
            return false;
        }

        if ($e instanceof ModelProviderMismatchException) {
            return false;
        }

        $cause = $e;
        while ($cause !== null) {
            if ($cause instanceof ApiKeyInvalidException) {
                return true;
            }
            $message = strtolower($cause->getMessage());
            foreach (self::AUTH_FAILURE_MARKERS as $marker) {
                if (str_contains($message, $marker)) {
                    return true;
                }
            }
            $cause = $cause->getPrevious();
        }

        return false;
    }

    /**
     * Handle an AI call failure on the auto-provisioned Amazee path.
     *
     * For an auth-class failure (the stored credentials are no longer accepted)
     * this records the auth-failure marker so health reports AI as degraded,
     * sets the persistent upgrade-needed marker so admin UIs can prompt the
     * admin to re-authenticate, and leaves the stored credentials untouched.
     * It always returns false: there is nothing to retry, so the caller
     * degrades gracefully (unexpanded query / no summary). Non-auth errors are
     * ignored and also return false.
     *
     * @param \Throwable $e The AI call failure.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function handleAuthFailure(\Throwable $e): bool
    {
        if (!self::isAuthFailure($e)) {
            return false;
        }

        $this->recordAuthFailure();
        $this->flagUpgradeNeeded();

        $this->logger->warning('Scolta: stored Amazee credentials were not accepted; AI is off until re-authentication.');

        return false;
    }

    /**
     * Mark the stored credentials as auth-failing so health reports AI as
     * unusable until calls succeed again or the marker ages out.
     *
     * Both halves of that sentence are implemented: {@see noteCallSucceeded()}
     * clears the marker on the first successful call, and it otherwise expires
     * after {@see self::AUTH_FAILURE_TTL} seconds. The stored `time()` is what
     * health reports as `ai_auth_failing_since`.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function recordAuthFailure(): void
    {
        $this->cache->set(self::CACHE_KEY_AUTH_FAILURE, time(), self::AUTH_FAILURE_TTL);
    }

    /**
     * Whether the stored credentials are known to be auth-failing.
     *
     * Cache-marker read only — never a live API call, so health checks can
     * call this on every request.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function isAuthFailing(): bool
    {
        return (bool) $this->cache->get(self::CACHE_KEY_AUTH_FAILURE);
    }

    /**
     * When the recorded auth failure was observed, as a Unix timestamp, or
     * NULL when no failure is recorded.
     *
     * {@see recordAuthFailure()} has always stored `time()`, and both readers
     * then cast it to bool and threw the age away. Health reports the age so a
     * marker that outlived its cause is visibly stale to whoever is reading
     * the payload, rather than looking exactly like a failure from a second
     * ago.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function authFailingSince(): ?int
    {
        return self::readFailureTimestamp($this->cache);
    }

    /**
     * Read the auth-failure timestamp from a cache, or NULL when unset.
     *
     * Static and cache-taking so {@see \Tag1\Scolta\Health\HealthChecker},
     * which is constructed with a bare cache and no credential storage, reads
     * the marker through the class that owns it instead of decoding the raw
     * value itself.
     *
     * A truthy value that is not an integer counts as failing with an unknown
     * age: the marker predates the timestamp being read back, and reporting an
     * invented timestamp would be worse than reporting none.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public static function readFailureTimestamp(CacheDriverInterface $cache): ?int
    {
        $value = $cache->get(self::CACHE_KEY_AUTH_FAILURE);

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Clear the auth-failure marker.
     *
     * The counterpart {@see recordAuthFailure()} never had. The marker's own
     * docblock promised health would report AI unusable "until calls succeed
     * again or the marker ages out", but only the ageing half existed: nothing
     * in the repository unset it, so a site that recovered kept reporting
     * `ai_auth_failing: true`, `ai_usable: false` and `status: degraded` for up
     * to {@see self::AUTH_FAILURE_TTL} seconds after the cause was fixed.
     *
     * This deliberately leaves {@see self::CACHE_KEY_UPGRADE_NEEDED} alone.
     * That marker means an admin still has to re-authenticate and is cleared
     * only by {@see clearUpgradeNeeded()} once they have.
     *
     * Called on every successful AI call via {@see noteCallSucceeded()}, and
     * available directly for an operator who has to force the marker down on a
     * site that cannot make a successful call at all.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function clearAuthFailure(): void
    {
        $this->cache->set(self::CACHE_KEY_AUTH_FAILURE, false, 1);
    }

    /**
     * Record that an AI call succeeded, clearing any auth-failure marker.
     *
     * A call that the provider accepted is proof the stored credentials
     * authenticate, which is the whole of what the marker claims. Wired into
     * {@see \Tag1\Scolta\Service\AiServiceAdapter} so recovery needs no
     * operator action.
     *
     * Reads before writing so the overwhelmingly common case — a healthy site
     * whose marker is already clear — costs a cache read rather than a cache
     * write on every AI call.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function noteCallSucceeded(): void
    {
        if (!$this->isAuthFailing()) {
            return;
        }

        $this->clearAuthFailure();

        $this->logger->info('Scolta: an AI call succeeded; clearing the recorded Amazee auth failure.');
    }

    /**
     * Set the persistent upgrade-needed marker.
     *
     * @since 1.0.5
     * @stability experimental
     */
    public function flagUpgradeNeeded(): void
    {
        $this->cache->set(self::CACHE_KEY_UPGRADE_NEEDED, time(), self::UPGRADE_NEEDED_TTL);
    }

    /**
     * Whether the stored credentials need an admin re-authentication.
     *
     * Adapter admin UIs read this to show the "enter your email to continue"
     * prompt. Cache-marker read only — never a live API call.
     *
     * @since 1.0.5
     * @stability experimental
     */
    public function isUpgradeNeeded(): bool
    {
        return (bool) $this->cache->get(self::CACHE_KEY_UPGRADE_NEEDED);
    }

    /**
     * Clear the upgrade-needed marker after a successful re-authentication.
     *
     * Adapters call this once the admin has completed the email verification
     * flow and fresh credentials are in storage.
     *
     * @since 1.0.5
     * @stability experimental
     */
    public function clearUpgradeNeeded(): void
    {
        $this->cache->set(self::CACHE_KEY_UPGRADE_NEEDED, false, 1);
    }

    /**
     * The currently stored credentials, or null when none are stored.
     *
     * @return array{litellm_token: string, litellm_api_url: string, region: string}|null
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function credentials(): ?array
    {
        return $this->storage->load();
    }
}
