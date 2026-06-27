<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyInvalidException;

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
     */
    private const AUTH_FAILURE_TTL = 3600;

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
     * Budget-exhaustion errors return false even though they also surface as
     * 4xx responses — they route to the budget path, never here.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public static function isAuthFailure(\Throwable $e): bool
    {
        if (BudgetAwareProviderDecorator::isBudgetError($e)) {
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
