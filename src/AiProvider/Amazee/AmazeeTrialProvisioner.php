<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Establishes the free Amazee.ai demo connection, on an explicit request.
 *
 * Calls AmazeeClient to provision the demo, then stores the returned
 * credentials via ConfigStorageInterface so platform adapters can
 * immediately configure the LiteLLM endpoint.
 *
 * **Nothing calls this on its own.** It is reached only from an operator
 * action — the "Try the demo" button in an admin UI, a provisioning CLI
 * command, or a first-use path in a headless framework where a developer set
 * `ai_provider` to `amazee` in code. {@see AutoProvisioner} deliberately does
 * not call it: that class self-heals credentials that are already stored and
 * establishes nothing.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AmazeeTrialProvisioner
{
    private readonly ?\Closure $hasExistingProvider;

    public function __construct(
        private readonly AmazeeClient $client,
        private readonly ConfigStorageInterface $storage,
        ?callable $hasExistingProvider = null,
        private readonly ?AmazeeModelResolver $modelResolver = null,
    ) {
        $this->hasExistingProvider = $hasExistingProvider !== null
            ? \Closure::fromCallable($hasExistingProvider)
            : null;
    }

    /**
     * Provision the free demo, optionally bound to an email address.
     *
     * If a `$hasExistingProvider` callable was supplied and returns true,
     * provisioning is skipped and a SKIPPED_EXISTING_PROVIDER result is
     * returned without making any API calls.
     *
     * On success, credentials are stored via ConfigStorageInterface, the
     * connection source is recorded as {@see AmazeeConnectionSource::Demo}
     * when the store implements {@see ProvenanceAwareConfigStorageInterface},
     * and the best available models are resolved from the provisioned
     * endpoint.
     *
     * @param string $email Optional email for the demo account. Pass an empty
     *   string (the default) for anonymous provisioning — this is what the
     *   "Try the demo" action in the admin UIs does, so that trying Scolta's
     *   AI costs an operator no input at all.
     *
     * @throws AmazeeApiException If the API call fails.
     * @since 1.0.0
     * @stability stable
     */
    public function provision(string $email = ''): ProvisioningResult
    {
        if ($this->hasExistingProvider !== null && ($this->hasExistingProvider)()) {
            return ProvisioningResult::skippedExistingProvider();
        }

        $result = $this->client->provisionTrial($email);
        $this->storage->store($result->litellmToken, $result->litellmApiUrl, $result->region);
        if ($this->storage instanceof ProvenanceAwareConfigStorageInterface) {
            $this->storage->storeConnectionSource(AmazeeConnectionSource::Demo);
        }

        if ($this->modelResolver !== null) {
            $models = $this->modelResolver->resolve($result->litellmApiUrl, $result->litellmToken);
            return ProvisioningResult::success(
                $result->litellmToken,
                $result->litellmApiUrl,
                $result->region,
                $models['ai_model'],
                $models['ai_expansion_model'],
            );
        }

        return $result;
    }
}
