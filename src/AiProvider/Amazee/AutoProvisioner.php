<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Guards CMS install hooks and lazy-init paths against unnecessary provisioning.
 *
 * Call `ensureAiAvailable()` from a module/plugin install hook or from the
 * first AI request path. It provisions a free Amazee.ai trial and stores the
 * returned credentials so subsequent service constructions pick them up
 * automatically.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AutoProvisioner
{
    /**
     * Provision a free Amazee.ai trial unless AI is already configured.
     *
     * This method is idempotent: it is a no-op when:
     *   - `$hasExplicitApiKey` is true (user has their own provider), or
     *   - credentials are already stored in `$storage` (already provisioned).
     *
     * The stored-credentials no-op deliberately does NOT validate that the
     * stored key still works — trial keys are revoked server-side when the
     * trial ends, and that expiry is not announced at provisioning time, so a
     * cheap install-hook/lazy-init guard cannot know. Call-time auth failures
     * are the reliable signal: {@see KeyExpiryRecovery} detects them, records
     * the failure for health, and flags the site for admin re-authentication
     * without requesting replacement credentials.
     *
     * Stored credentials are, however, treated as a *complete* provision only
     * once their model names have been resolved. A provision whose `/model/info`
     * call failed stores the token+url with no resolved models, leaving the
     * caller to fall back to the dated config default — which the Amazee gateway
     * rejects with HTTP 400, breaking AI permanently because this guard kept
     * no-opping on the half-provisioned credentials. When the caller can confirm
     * models are still unresolved (via `$hasResolvedModels`), model resolution
     * is re-attempted against the ALREADY-STORED key — never a fresh trial,
     * which would waste a server-side-limited trial allocation — so the
     * incomplete-provision state self-heals. Without that callback the historical
     * no-op stands: the caller cannot tell us, and we must not re-resolve blindly
     * on every request.
     *
     * On a successful first provisioning, credentials are stored via
     * `$storage` and `$onModelsResolved` is called (when provided) with the
     * best available model names resolved from the provisioned endpoint.
     *
     * Provisioning failures are caught internally and returned as `false` so
     * the caller degrades gracefully without crashing the install or request.
     *
     * @param ConfigStorageInterface $storage            CMS-specific credential store.
     * @param bool                   $hasExplicitApiKey  True when the user has configured
     *                                                   their own API key or base URL.
     * @param callable(string $aiModel, string $aiExpansionModel): void|null $onModelsResolved
     *   Called with the resolved model names when provisioning succeeds and
     *   models are available. Use this to persist model choices in your CMS
     *   config system (e.g. Drupal CMI, WP options, Laravel DB).
     * @param AmazeeClient|null $client  Optionally inject a pre-configured
     *   client (useful for testing or custom base-URL overrides).
     * @param callable(): bool|null $hasResolvedModels  Optional predicate the
     *   caller supplies to report whether model names are already resolved (the
     *   adapter knows: it persisted them via `$onModelsResolved`). When stored
     *   credentials exist and this returns false, models are re-resolved against
     *   the stored key and `$onModelsResolved` is fired — self-healing a
     *   provision that stored credentials without resolved models. Omit it to
     *   keep the legacy "stored credentials are complete" no-op.
     *
     * @return bool True only when a fresh trial was provisioned; false when
     *   skipped, already provisioned (including a model-only self-heal), or
     *   failed.
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
        if ($credentials !== null) {
            // Already provisioned. Self-heal only an incomplete provision — one
            // whose model resolution failed, leaving credentials with no models
            // — and only when the caller can confirm that state. Re-resolve
            // against the stored key (not a new trial) and persist the result.
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

        $amazeeClient = $client ?? new AmazeeClient();
        $modelResolver = new AmazeeModelResolver($amazeeClient);
        $provisioner = new AmazeeTrialProvisioner($amazeeClient, $storage, null, $modelResolver);

        try {
            $result = $provisioner->provision();
        } catch (AmazeeApiException) {
            return false;
        }

        if (!$result->success || $result->status !== ProvisioningResult::STATUS_PROVISIONED) {
            return false;
        }

        if ($onModelsResolved !== null
            && ($result->aiModel !== null || $result->aiExpansionModel !== null)) {
            $onModelsResolved($result->aiModel ?? '', $result->aiExpansionModel ?? '');
        }

        return true;
    }
}
