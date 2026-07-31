<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

/**
 * The effective AI API key together with where it came from.
 *
 * The key and its source travel as one value because they used to be derived
 * independently — the effective-config path preferred an explicit key while
 * every reporting surface asked a separate method that preferred Amazee — and
 * the two disagreed. A caller that receives both from one resolution has
 * nothing left to re-derive, so they cannot drift apart again.
 *
 * @since 1.1.0
 * @stability experimental
 */
final class ResolvedApiKey
{
    /**
     * @param string         $key      The effective key, or '' when none applies.
     * @param ApiKeySource   $source   Where that key came from.
     * @param string         $provider The effective AI provider for this key.
     *   Amazee resolves to the OpenAI-compatible LiteLLM gateway, so adapters
     *   take the provider from here instead of setting it beside the key.
     * @param string         $baseUrl  Provider base URL, or '' for the default.
     * @param bool           $amazeeCredentialsStored Whether Amazee.ai
     *   credentials exist at all, whichever source won. This is what lets a
     *   surface say "stored but overridden" rather than staying silent about
     *   credentials the operator knows they created.
     * @param bool           $awaitingAmazeeModelResolution TRUE for a
     *   half-provisioned Amazee install whose key is deliberately withheld.
     */
    public function __construct(
        public readonly string $key,
        public readonly ApiKeySource $source,
        public readonly string $provider,
        public readonly string $baseUrl = '',
        public readonly bool $amazeeCredentialsStored = false,
        public readonly bool $awaitingAmazeeModelResolution = false,
    ) {}

    /**
     * Whether a usable key was resolved.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function isConfigured(): bool
    {
        return trim($this->key) !== '';
    }

    /**
     * Whether Amazee.ai is the effective source.
     *
     * Adapters expose this as their `isAmazeeActive()` equivalent. It must
     * never be answered by checking the credential store directly: stored
     * credentials that lost to an explicit key are not active.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function isAmazee(): bool
    {
        return $this->source->isAmazee();
    }

    /**
     * Whether Amazee.ai credentials are stored but lost to an explicit key.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function amazeeOverridden(): bool
    {
        return $this->amazeeCredentialsStored && !$this->isAmazee();
    }

    /**
     * How prominently a surface should render this state.
     *
     * 'ok' or 'warning'. An overridden Amazee credential is deliberately not
     * 'ok': rendering it in success green is what let an operator read the
     * status line as proof that Amazee was serving traffic when it was not.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function severity(): string
    {
        if (!$this->isConfigured() || $this->amazeeOverridden() || $this->awaitingAmazeeModelResolution) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * A complete operator-facing description of the resolution, in English.
     *
     * CLI surfaces print this verbatim. Adapters whose surfaces are
     * translated switch on {@see self::$source} and {@see self::amazeeOverridden()}
     * instead, which is still deriving from this resolution rather than
     * re-deriving the fact.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function describe(): string
    {
        if ($this->isAmazee()) {
            $text = $this->source === ApiKeySource::AmazeeAuto
                ? 'Connected to Amazee.ai (auto-provisioned free trial).'
                : 'Connected to Amazee.ai.';

            if ($this->awaitingAmazeeModelResolution) {
                $text .= ' Model resolution has not completed yet, so AI features stay degraded until it does.';
            }

            return $text;
        }

        if ($this->source === ApiKeySource::None) {
            $text = 'No API key configured; AI features are disabled.';
            if ($this->amazeeCredentialsStored) {
                $text .= ' Amazee.ai credentials are stored but not eligible for this provider.';
            }

            return $text;
        }

        $text = sprintf('API key configured via the %s.', $this->source->label());
        if ($this->amazeeOverridden()) {
            $text .= sprintf(' Amazee.ai credentials stored but overridden by the %s.', $this->source->label());
        }

        return $text;
    }
}
