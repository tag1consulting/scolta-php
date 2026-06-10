<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Orchestrates the account upgrade flow for Amazee.ai.
 *
 * Three-step flow:
 *  1. requestVerificationCode() — send OTP to user's email.
 *  2. signIn()                  — exchange OTP for a session token.
 *  3. upgrade()                 — provision a private key in a region;
 *                                 stores credentials and returns UpgradeResult.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AmazeeAccountUpgrader
{
    public function __construct(
        private readonly AmazeeClient $client,
        private readonly ConfigStorageInterface $storage,
    ) {}

    /**
     * Step 1: Send a verification code to the given email address.
     *
     * @throws AmazeeApiException If the API call fails.
     * @since 1.0.0
     * @stability stable
     */
    public function requestVerificationCode(string $email): void
    {
        $this->client->requestVerificationCode($email);
    }

    /**
     * Step 2: Exchange an email + OTP code for a session token.
     *
     * @throws AmazeeApiException If the API call fails or credentials are invalid.
     * @since 1.0.0
     * @stability stable
     */
    public function signIn(string $email, string $code): string
    {
        return $this->client->signIn($email, $code);
    }

    /**
     * List regions available for the authenticated account.
     *
     * @return array<int, array{id: string, name: string, url: string}>
     *
     * @throws AmazeeApiException If the API call fails.
     * @since 1.0.0
     * @stability stable
     */
    public function listRegions(string $sessionToken): array
    {
        return $this->client->listRegions($sessionToken);
    }

    /**
     * Step 3: Provision a private AI key in the given region.
     *
     * On success, new credentials replace any existing stored credentials.
     *
     * @throws AmazeeApiException If the API call fails or credentials are missing.
     * @since 1.0.0
     * @stability stable
     */
    public function upgrade(string $sessionToken, string $regionId): UpgradeResult
    {
        $result = $this->client->createPrivateKey($sessionToken, $regionId);
        $this->storage->store($result->litellmToken, $result->litellmApiUrl, $result->region);
        return $result;
    }
}
