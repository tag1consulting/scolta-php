<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyInvalidException;

/**
 * Detects Amazee trial-key auth failures at call time and recovers by
 * re-provisioning through the existing provisioner path.
 *
 * Amazee.ai trial keys are revoked server-side when the trial lifecycle ends.
 * The expiry is NOT announced at provisioning time (verified against the live
 * API: `/auth/generate-trial-access` returns only `created_at`, and the
 * LiteLLM key's own `expires` is a year out while observed trial revocation
 * is on the order of a day) — so the only reliable signal is the auth
 * failure the LiteLLM proxy returns on the next inference call. Without this
 * class that failure was swallowed by the expand/summarize graceful-degrade
 * path while `AutoProvisioner::ensureAiAvailable()` kept no-opping on the
 * stored dead credentials: AI stayed down fleet-wide with health reporting
 * `ai_configured: true` (django demo outage, 2026-06-09).
 *
 * Two cache-backed markers (any {@see CacheDriverInterface}) coordinate the
 * recovery across requests:
 *  - an auth-failure marker, recorded on every detected failure and read by
 *    health checks so "AI configured" stops implying "AI usable";
 *  - a re-provision-attempt marker with a TTL window, so a fleet of failing
 *    requests triggers exactly one re-provision attempt per window instead of
 *    hammering the provisioning API in a loop.
 *
 * Budget-exhaustion errors are explicitly excluded — those belong to
 * {@see BudgetAwareProviderDecorator} and must not trigger re-provisioning
 * (a re-provisioned trial key would reset the spend ceiling, which is the
 * upgrade flow's job, not an error-recovery side effect).
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
     * Cache key for the one-attempt-per-window re-provision guard.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public const CACHE_KEY_REPROVISION_ATTEMPT = 'scolta_amazee_reprovision_attempt';

    /**
     * How long a recorded auth failure keeps health reporting AI unusable
     * before a fresh failing call must re-confirm it, in seconds.
     */
    private const AUTH_FAILURE_TTL = 3600;

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
     * @param ConfigStorageInterface $storage              Adapter credential store (same instance the provisioner uses).
     * @param CacheDriverInterface   $cache                Cache for the failure/attempt markers.
     * @param AmazeeClient|null      $client               Optional pre-configured client (testing / base-URL override).
     * @param int                    $failureWindowSeconds Minimum spacing between re-provision attempts.
     * @param LoggerInterface        $logger               PSR-3 logger (defaults to NullLogger).
     */
    public function __construct(
        private readonly ConfigStorageInterface $storage,
        private readonly CacheDriverInterface $cache,
        private readonly ?AmazeeClient $client = null,
        private readonly int $failureWindowSeconds = 600,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Whether an exception (anywhere in its chain) is an auth-class failure
     * for which re-provisioning is the correct recovery.
     *
     * Budget-exhaustion errors return false even though they also surface as
     * 4xx responses — they route to the budget path, never to re-provisioning.
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
     * Record an auth failure and attempt a one-shot re-provision.
     *
     * Returns true only when the exception is an auth failure AND a
     * re-provision was attempted in this call AND it succeeded — i.e. fresh
     * credentials are now in storage and a retry makes sense. Returns false
     * for non-auth errors, when another attempt already ran inside the
     * current failure window, or when re-provisioning failed.
     *
     * @param \Throwable $e The AI call failure.
     * @param callable(string, string): void|null $onModelsResolved Forwarded
     *   to the provisioner so adapters can persist resolved model names.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function handleAuthFailure(\Throwable $e, ?callable $onModelsResolved = null): bool
    {
        if (!self::isAuthFailure($e)) {
            return false;
        }

        $this->recordAuthFailure();

        return $this->attemptReprovision($onModelsResolved);
    }

    /**
     * Mark the stored credentials as auth-failing so health reports AI as
     * unusable until recovery succeeds or the marker ages out.
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
     * The currently stored credentials, or null when none are stored.
     *
     * After a successful {@see handleAuthFailure()} these are the fresh
     * post-re-provision credentials callers rebuild their client from.
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

    /**
     * Attempt one re-provision through the existing provisioner path,
     * guarded to a single attempt per failure window.
     *
     * @param callable(string, string): void|null $onModelsResolved
     */
    private function attemptReprovision(?callable $onModelsResolved): bool
    {
        if ($this->cache->get(self::CACHE_KEY_REPROVISION_ATTEMPT)) {
            return false;
        }

        // Set the guard before attempting: a failed attempt must also wait
        // out the window, otherwise every failing request retries provisioning.
        $this->cache->set(self::CACHE_KEY_REPROVISION_ATTEMPT, time(), $this->failureWindowSeconds);

        $this->logger->warning('Scolta: stored Amazee credentials failed authentication, attempting re-provision');

        $provisioned = AutoProvisioner::reprovision($this->storage, $onModelsResolved, $this->client);

        if ($provisioned) {
            // AI is usable again — stop health from reporting the old failure.
            $this->cache->set(self::CACHE_KEY_AUTH_FAILURE, false, 1);
            $this->logger->info('Scolta: Amazee re-provisioning succeeded, fresh credentials stored');
        } else {
            $this->logger->error('Scolta: Amazee re-provisioning failed, AI remains unavailable');
        }

        return $provisioned;
    }
}
