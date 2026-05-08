<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Abstract storage backend for Amazee.ai credentials.
 *
 * Platform adapters (Drupal, WordPress, Laravel) implement this interface
 * to persist and retrieve the LiteLLM token and API URL in their native
 * config systems.
 *
 * @since 0.4.0
 * @stability experimental
 */
interface ConfigStorageInterface
{
    /**
     * Persist Amazee.ai credentials.
     *
     * @param string $litellmToken   The LiteLLM bearer token.
     * @param string $litellmApiUrl  The LiteLLM API base URL.
     * @param string $region         The selected region identifier.
     */
    public function store(string $litellmToken, string $litellmApiUrl, string $region): void;

    /**
     * Retrieve stored credentials, or null if none are stored.
     *
     * @return array{litellm_token: string, litellm_api_url: string, region: string}|null
     */
    public function load(): ?array;

    /**
     * Remove stored credentials.
     */
    public function clear(): void;
}
