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
     *
     * @return bool True if provisioning succeeded; false if skipped or failed.
     *
     * @since 0.4.0
     * @stability experimental
     */
    public static function ensureAiAvailable(
        ConfigStorageInterface $storage,
        bool $hasExplicitApiKey = false,
        ?callable $onModelsResolved = null,
        ?AmazeeClient $client = null,
    ): bool {
        if ($hasExplicitApiKey) {
            return false;
        }

        if ($storage->load() !== null) {
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
