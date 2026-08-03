<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

/**
 * The one place an AI API key and its source are decided.
 *
 * Canonical precedence, highest first:
 *
 *   1. Explicit keys, in the order the platform supplies them. Every adapter
 *      puts the environment variable first; the rest is platform shape
 *      (Drupal: settings.php, WordPress: wp-config.php constant then the
 *      legacy database option).
 *   2. Stored Amazee.ai credentials, when Amazee is eligible for the
 *      configured provider.
 *   3. Nothing.
 *
 * Rule 1 before rule 2 is the behavior the effective-config path in every
 * adapter already had: a site that configured its own key is never silently
 * rerouted through the managed gateway. What is new is that the reporting
 * surfaces now read the same resolution instead of computing a second,
 * differently ordered answer.
 *
 * See docs/API_KEY_PRECEDENCE.md.
 *
 * @since 1.1.0
 * @stability experimental
 */
final class ApiKeyResolver
{
    /**
     * The provider an Amazee.ai resolution implies.
     *
     * The Amazee gateway is LiteLLM, which speaks the OpenAI wire protocol.
     */
    public const AMAZEE_GATEWAY_PROVIDER = 'openai';

    /**
     * Resolve the effective key, its source and the provider that goes with it.
     *
     * @param array<string, string> $explicitKeys Candidate keys in platform
     *   precedence order, keyed by the {@see ApiKeySource} backing value.
     *   Empty and whitespace-only values are skipped, so an adapter can pass
     *   every candidate it knows about without pre-filtering.
     * @param AmazeeCredentials|null $amazee Stored Amazee credentials, if any.
     * @param string $configuredProvider The provider configured for explicit
     *   keys. Defaults to 'anthropic', matching ScoltaConfig.
     * @param bool $amazeeEligible FALSE when the platform routes AI somewhere
     *   that must not receive Amazee credentials — Drupal's `drupal_ai`
     *   provider manages its own key, model and provider. Credentials are
     *   still reported as stored so the state is not hidden.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public static function resolve(
        array $explicitKeys,
        ?AmazeeCredentials $amazee = null,
        string $configuredProvider = 'anthropic',
        bool $amazeeEligible = true,
    ): ResolvedApiKey {
        $provider = $configuredProvider !== '' ? $configuredProvider : 'anthropic';

        foreach ($explicitKeys as $source => $key) {
            if (trim($key) === '') {
                continue;
            }

            return new ResolvedApiKey(
                key: $key,
                source: ApiKeySource::from($source),
                provider: $provider,
                amazeeCredentialsStored: $amazee !== null,
            );
        }

        if ($amazee !== null && $amazeeEligible) {
            return new ResolvedApiKey(
                // A half-provisioned install reports Amazee as its source but
                // hands back no key: the gateway rejects the shipped dated
                // default with HTTP 400, whereas a key-less client degrades to
                // an unexpanded, unsummarized HTTP 200. The state self-heals
                // when model resolution next succeeds.
                key: $amazee->modelResolved ? $amazee->token : '',
                source: ApiKeySource::Amazee,
                provider: self::AMAZEE_GATEWAY_PROVIDER,
                baseUrl: $amazee->baseUrl,
                amazeeCredentialsStored: true,
                awaitingAmazeeModelResolution: !$amazee->modelResolved,
            );
        }

        return new ResolvedApiKey(
            key: '',
            source: ApiKeySource::None,
            provider: $provider,
            amazeeCredentialsStored: $amazee !== null,
        );
    }
}
