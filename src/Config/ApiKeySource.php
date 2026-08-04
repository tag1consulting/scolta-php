<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;

/**
 * Where the effective AI API key came from.
 *
 * The set is closed on purpose. Reporting surfaces used to build these
 * strings themselves, next to a separate derivation of the key, and the two
 * disagreed about precedence; a shared enum means a surface can only report
 * a source that {@see ApiKeyResolver} actually produced.
 *
 * Amazee has three cases, and the history behind that is the point. 1.1.0
 * split it into `amazee:operator` and `amazee:auto` — a provider somebody
 * chose versus a free trial that provisioned itself — and 1.1.1 collapsed
 * both back to a single `amazee`, because nothing in the credential store
 * recorded which one produced a token and each adapter was substituting a
 * different local fact. A distinction that cannot be derived is worse than no
 * distinction, because every surface reports it with total confidence.
 *
 * The fix is to record the fact rather than to keep guessing at it or to give
 * up on it. {@see AmazeeConnectionSource} is written at the moment a
 * connection is established, so {@see self::AmazeeDemo} and
 * {@see self::AmazeeAccount} now report something the store actually knows.
 * {@see self::Amazee} remains for credentials whose origin was never recorded
 * — every connection made before 1.2.0, and any store that does not implement
 * {@see \Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface}.
 * That case claims nothing.
 *
 * No case means "provisioned itself". All three are reached only after an
 * operator selected Amazee.ai and took an explicit action.
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

    /**
     * Stored Amazee.ai credentials whose origin was never recorded.
     *
     * Pre-1.2.0 connections and provenance-unaware stores land here. It is the
     * honest answer, not a fallback with a hidden meaning: the store does not
     * know which action created these credentials, so neither does any surface
     * reading this.
     */
    case Amazee = 'amazee';

    /** Stored credentials from the free Amazee.ai demo the operator started. */
    case AmazeeDemo = 'amazee:demo';

    /** Stored credentials from the amazee.ai account the operator signed in to. */
    case AmazeeAccount = 'amazee:account';

    /** No key is configured anywhere. */
    case None = 'none';

    /**
     * The Amazee source case for a recorded connection source.
     *
     * NULL — provenance was never recorded — maps to {@see self::Amazee},
     * which claims nothing about the origin.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public static function forAmazeeConnection(?AmazeeConnectionSource $connection): self
    {
        return match ($connection) {
            AmazeeConnectionSource::Demo => self::AmazeeDemo,
            AmazeeConnectionSource::Account => self::AmazeeAccount,
            null => self::Amazee,
        };
    }

    /**
     * Whether this source is Amazee.ai, however the connection was made.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function isAmazee(): bool
    {
        return $this === self::Amazee
            || $this === self::AmazeeDemo
            || $this === self::AmazeeAccount;
    }

    /**
     * Whether this source is the free Amazee.ai demo.
     *
     * FALSE for {@see self::Amazee}, whose origin is unknown — an unrecorded
     * connection must not be reported as a demo, which is the guess this enum
     * exists to stop.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function isAmazeeDemo(): bool
    {
        return $this === self::AmazeeDemo;
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
     * No label describes a connection as automatic or as provisioned on the
     * operator's behalf, because none of them are.
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
            self::AmazeeDemo => 'Amazee.ai demo',
            self::AmazeeAccount => 'Amazee.ai account',
            self::None => 'not configured',
        };
    }
}
