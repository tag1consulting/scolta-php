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
    public function __construct(
        private readonly AmazeeClient $client,
        private readonly ConfigStorageInterface $storage,
    ) {
    }

    /**
     * Provision a free trial account for the given email address.
     *
     * On success, credentials are stored via ConfigStorageInterface.
     *
     * @throws AmazeeApiException If the API call fails.
     */
    public function provision(string $email): ProvisioningResult
    {
        $result = $this->client->provisionTrial($email);
        $this->storage->store($result->litellmToken, $result->litellmApiUrl, $result->region);
        return $result;
    }
}
