<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Stored Amazee.ai credentials, as input to {@see ApiKeyResolver}.
 *
 * This is the resolver's view of the credential store: enough to decide
 * whether Amazee can serve the request and to report honestly when it is
 * stored but overridden. Provisioning, budget handling and key-expiry
 * recovery stay in `Tag1\Scolta\AiProvider\Amazee`.
 *
 * @since 1.1.0
 * @stability experimental
 */
final class AmazeeCredentials
{
    /**
     * @param string $token          The LiteLLM bearer token.
     * @param string $baseUrl        The LiteLLM API base URL.
     * @param bool   $modelResolved  Whether model resolution has succeeded.
     *   A half-provisioned install (credentials stored, `/model/info` never
     *   answered) must not send the shipped dated default to the gateway,
     *   which rejects it with HTTP 400. The resolver reports Amazee as the
     *   source but withholds the key, so a key-less client degrades to an
     *   unexpanded/no-summary response instead.
     */
    public function __construct(
        public readonly string $token,
        public readonly string $baseUrl = '',
        public readonly bool $modelResolved = true,
    ) {}

    /**
     * Build from a {@see ConfigStorageInterface}, or NULL when nothing is stored.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public static function fromStorage(
        ConfigStorageInterface $storage,
        bool $modelResolved = true,
    ): ?self {
        $stored = $storage->load();
        if ($stored === null || $stored['litellm_token'] === '') {
            return null;
        }

        return new self(
            token: $stored['litellm_token'],
            baseUrl: $stored['litellm_api_url'],
            modelResolved: $modelResolved,
        );
    }

    /**
     * Build from a raw credentials array, or NULL when it holds no token.
     *
     * Drupal keeps credentials in state rather than behind the storage
     * interface, so it needs the array form.
     *
     * @param array<string, mixed>|null $stored
     * @since 1.1.0
     * @stability experimental
     */
    public static function fromArray(
        ?array $stored,
        bool $modelResolved = true,
    ): ?self {
        if (!is_array($stored) || empty($stored['litellm_token']) || !is_string($stored['litellm_token'])) {
            return null;
        }

        $baseUrl = $stored['litellm_api_url'] ?? '';

        return new self(
            token: $stored['litellm_token'],
            baseUrl: is_string($baseUrl) ? $baseUrl : '',
            modelResolved: $modelResolved,
        );
    }
}
