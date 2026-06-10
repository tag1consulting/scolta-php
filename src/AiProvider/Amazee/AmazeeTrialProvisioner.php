<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Orchestrates trial account provisioning for Amazee.ai.
 *
 * Calls AmazeeClient to provision the trial, then stores the returned
 * credentials via ConfigStorageInterface so platform adapters can
 * immediately configure the LiteLLM endpoint.
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
     * Provision a free trial account for the given email address.
     *
     * If a `$hasExistingProvider` callable was supplied and returns true,
     * provisioning is skipped and a SKIPPED_EXISTING_PROVIDER result is
     * returned without making any API calls.
     *
     * On success, credentials are stored via ConfigStorageInterface and
     * the best available models are resolved from the provisioned endpoint.
     *
     * @param string $email Optional email for the trial account. Pass an empty
     *   string (the default) for anonymous provisioning.
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
