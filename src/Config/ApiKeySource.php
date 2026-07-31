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
 * Amazee has two cases because they mean different things to an operator
 * reading a status line: `amazee:operator` is a provider somebody chose,
 * `amazee:auto` is a free trial that provisioned itself on first use.
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

    /** Amazee.ai credentials for a provider the operator selected. */
    case AmazeeOperator = 'amazee:operator';

    /** Amazee.ai credentials from automatic free-trial provisioning. */
    case AmazeeAuto = 'amazee:auto';

    /** No key is configured anywhere. */
    case None = 'none';

    /**
     * Whether this source is one of the Amazee.ai cases.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function isAmazee(): bool
    {
        return $this === self::AmazeeOperator || $this === self::AmazeeAuto;
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
            self::AmazeeOperator => 'Amazee.ai',
            self::AmazeeAuto => 'Amazee.ai (auto-provisioned free trial)',
            self::None => 'not configured',
        };
    }
}
