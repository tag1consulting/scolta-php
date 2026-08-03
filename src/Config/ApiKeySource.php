<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

/**
 * Where the effective AI API key came from.
 *
 * The set is closed on purpose. Reporting surfaces used to build these
 * strings themselves, next to a separate derivation of the key, and the two
 * disagreed about precedence; a shared enum means a surface can only report
 * a source that {@see ApiKeyResolver} actually produced.
 *
 * Amazee is one case. It briefly had two, `amazee:operator` and
 * `amazee:auto`, meaning a provider somebody chose versus a free trial that
 * provisioned itself. No adapter could tell those apart: both the trial
 * provisioner and the account upgrader write the same three fields through
 * {@see \Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface::store()}, so
 * nothing records which one produced a token. Each adapter substituted a
 * different local fact and ended up pinned to one case — Drupal always
 * reported `amazee:operator`, WordPress always `amazee:auto`, and WordPress
 * therefore called every deliberately connected account a free trial. A
 * distinction that cannot be derived is worse than no distinction, because
 * every surface reports it with total confidence.
 *
 * @since 1.1.0
 * @stability experimental
 */
enum ApiKeySource: string
{
    /** The SCOLTA_API_KEY environment variable. */
    case Env = 'env';

    /** A platform settings file, e.g. Drupal's `$settings['scolta.api_key']`. */
    case Settings = 'settings';

    /** A PHP constant, e.g. WordPress's `SCOLTA_API_KEY` in wp-config.php. */
    case Constant = 'constant';

    /** A value persisted in the site database (legacy WordPress installs). */
    case Database = 'database';

    /** Stored Amazee.ai credentials. */
    case Amazee = 'amazee';

    /** No key is configured anywhere. */
    case None = 'none';

    /**
     * Whether this source is Amazee.ai.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function isAmazee(): bool
    {
        return $this === self::Amazee;
    }

    /**
     * Whether this source is an explicitly configured key.
     *
     * Explicit sources take precedence over stored Amazee credentials, so a
     * site that configured its own provider is never silently rerouted.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function isExplicit(): bool
    {
        return $this === self::Env
            || $this === self::Settings
            || $this === self::Constant
            || $this === self::Database;
    }

    /**
     * A short operator-facing name for this source, in English.
     *
     * Adapters that translate their status strings should switch on the enum
     * rather than translating this, but CLI surfaces can print it directly.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function label(): string
    {
        return match ($this) {
            self::Env => 'SCOLTA_API_KEY environment variable',
            self::Settings => 'platform settings file',
            self::Constant => 'SCOLTA_API_KEY constant',
            self::Database => 'site database (legacy)',
            self::Amazee => 'Amazee.ai',
            self::None => 'not configured',
        };
    }
}
