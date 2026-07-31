<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Keeps already-stored managed-gateway credentials usable.
 *
 * This helper never establishes a managed gateway connection. Establishing one
 * is an explicit caller action: an operator-initiated enable path calls
 * {@see AmazeeTrialProvisioner::provision()} directly. Nothing here does it on
 * the caller's behalf, from an install hook, from a request path, or behind a
 * flag.
 *
 * What remains is `ensureAiAvailable()`: a self-heal for credentials that are
 * already stored but whose model names were never resolved.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AutoProvisioner
{
    /**
     * Re-resolve model names for credentials that are already stored.
     *
     * This method never establishes a managed gateway connection, and it makes
     * no outbound call at all unless credentials are already stored. It is a
     * no-op when:
     *   - `$hasExplicitApiKey` is true (the caller has their own provider),
     *   - no credentials are stored — nothing to heal, and nothing is
     *     established here; that is {@see AmazeeTrialProvisioner::provision()},
     *     reached only from an explicit operator action, or
     *   - credentials are stored and `$hasResolvedModels` is absent or reports
     *     that model names are already resolved.
     *
     * The stored-credentials path deliberately does NOT validate that the
     * stored key still works — credentials are revoked server-side when their
     * lifecycle ends, and that is not announced at issue time, so a cheap
     * lazy-init guard cannot know. Call-time auth failures are the reliable
     * signal: {@see KeyExpiryRecovery} detects them, records the failure for
     * health, and flags the site for admin re-authentication without requesting
     * replacement credentials.
     *
     * Stored credentials are, however, usable only once their model names have
     * been resolved. Credentials stored while `/model/info` was unreachable
     * carry no resolved models, leaving the caller to fall back to the dated
     * config default — which the Amazee gateway rejects with HTTP 400, breaking
     * AI permanently because this guard kept no-opping on the half-configured
     * credentials. When the caller can confirm models are still unresolved (via
     * `$hasResolvedModels`), model resolution is re-attempted against the
     * ALREADY-STORED key, so that state self-heals. Without that callback the
     * historical no-op stands: the caller cannot tell us, and we must not
     * re-resolve blindly on every request.
     *
     * @param ConfigStorageInterface $storage            CMS-specific credential store.
     * @param bool                   $hasExplicitApiKey  True when the user has configured
     *                                                   their own API key or base URL.
     * @param callable(string $aiModel, string $aiExpansionModel): void|null $onModelsResolved
     *   Called with the resolved model names when a self-heal resolves them.
     *   Use this to persist model choices in your CMS config system (e.g.
     *   Drupal CMI, WP options, Laravel DB).
     *
     *   **The resolved names are gateway-scoped and MUST NOT be written to the
     *   operator-facing model key.** They are Amazee LiteLLM aliases (e.g.
     *   `claude-4-5-sonnet`), valid only against the Amazee gateway; the key an
     *   operator uses to name a model holds provider-native IDs (e.g.
     *   `claude-sonnet-4-5-20250929`). Persist these to a dedicated
     *   gateway-scoped key (`amazee_model` / `amazee_expansion_model` in the
     *   adapters that ship with Scolta, or `storeModels()` on a storage that
     *   has one) and read it only while Amazee is the effective provider.
     *   Writing them to the shared key breaks AI permanently the moment the
     *   trial expires or an operator configures a direct provider key: the
     *   provider is then `anthropic`, the stored model is still a gateway
     *   alias, and nothing invalidates it. See
     *   {@see \Tag1\Scolta\AiProvider\ModelIdentity} for the tripwire that
     *   names that failure when it happens anyway.
     * @param AmazeeClient|null $client  Optionally inject a pre-configured
     *   client (useful for testing or custom base-URL overrides).
     * @param callable(): bool|null $hasResolvedModels  Optional predicate the
     *   caller supplies to report whether model names are already resolved (the
     *   adapter knows: it persisted them via `$onModelsResolved`). When stored
     *   credentials exist and this returns false, models are re-resolved against
     *   the stored key and `$onModelsResolved` is fired — self-healing
     *   credentials stored without resolved models. Omit it to keep the
     *   "stored credentials are complete" no-op.
     *
     * @return bool Always false. The return value is retained for callers
     *   compiled against the previous signature; nothing is established here,
     *   so there is no success to report.
     *
     * @since 0.4.0
     * @stability experimental
     */
    public static function ensureAiAvailable(
        ConfigStorageInterface $storage,
        bool $hasExplicitApiKey = false,
        ?callable $onModelsResolved = null,
        ?AmazeeClient $client = null,
        ?callable $hasResolvedModels = null,
    ): bool {
        if ($hasExplicitApiKey) {
            return false;
        }

        $credentials = $storage->load();
        if ($credentials === null) {
            // POLICY: nothing is established here. Automatic enrollment was
            // removed outright — there is no automatic path and no flag-gated
            // one. A managed gateway connection is established only by an
            // explicit operator action that calls
            // AmazeeTrialProvisioner::provision(). With no stored credentials
            // this is a no-op that makes no outbound call.
            return false;
        }

        // Credentials are stored. Self-heal only the incomplete case — model
        // resolution never completed, leaving credentials with no models — and
        // only when the caller can confirm that state. Re-resolve against the
        // stored key and persist the result.
        if ($hasResolvedModels === null || $hasResolvedModels()) {
            return false;
        }

        $models = (new AmazeeModelResolver($client ?? new AmazeeClient()))->resolve(
            $credentials['litellm_api_url'],
            $credentials['litellm_token'],
        );
        if ($onModelsResolved !== null
            && ($models['ai_model'] !== null || $models['ai_expansion_model'] !== null)) {
            $onModelsResolved($models['ai_model'] ?? '', $models['ai_expansion_model'] ?? '');
        }

        return false;
    }
}
