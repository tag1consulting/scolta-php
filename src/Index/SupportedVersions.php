<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Pagefind version tracking and compatibility management.
 *
 * The PHP indexer is tested against specific Pagefind versions.
 * This class tracks which versions are known to work and warns
 * when using untested versions.
 */
class SupportedVersions
{
    /**
     * Pagefind versions the PHP indexer has been tested against.
     *
     * @var string[]
     */
    public const TESTED_VERSIONS = ['1.3.0', '1.4.0', '1.5.0'];

    /**
     * The version of Pagefind JS/WASM bundled with this package.
     * Written into index metadata so pagefind.js knows which version built the index.
     */
    public const BUNDLED_VERSION = '1.5.0';

    /**
     * Minimum Pagefind version known to work.
     */
    public const MIN_VERSION = '1.3.0';

    /**
     * Pagefind versions known to NOT work.
     *
     * @var array<string, string>
     */
    public const INCOMPATIBLE_VERSIONS = [];

    /**
     * Check if a Pagefind version is supported.
     */
    public static function isSupported(string $version): bool
    {
        return in_array($version, self::TESTED_VERSIONS, true);
    }

    /**
     * Check if a version is known to be incompatible.
     */
    public static function isIncompatible(string $version): bool
    {
        return isset(self::INCOMPATIBLE_VERSIONS[$version]);
    }

    /**
     * Generate a warning string if version is unsupported or incompatible.
     *
     * @return string|null Warning message, or null if version is supported.
     */
    public static function warn(string $version): ?string
    {
        if (self::isIncompatible($version)) {
            return sprintf(
                'Pagefind version %s is INCOMPATIBLE: %s',
                $version,
                self::INCOMPATIBLE_VERSIONS[$version]
            );
        }

        if (!self::isSupported($version)) {
            return sprintf(
                'Pagefind version %s has NOT been tested with Scolta\'s PHP indexer. '
                . 'Search may work, but results are not guaranteed. '
                . 'Tested versions: %s.',
                $version,
                implode(', ', self::TESTED_VERSIONS)
            );
        }

        return null;
    }

    /**
     * Get the version that should be written into index metadata.
     */
    public static function getVersionForMetadata(): string
    {
        return self::BUNDLED_VERSION;
    }

    /**
     * Get human-readable version info for status commands.
     */
    public static function getVersionInfo(): string
    {
        return sprintf(
            'Bundled Pagefind: %s | Tested versions: %s | Minimum: %s',
            self::BUNDLED_VERSION,
            implode(', ', self::TESTED_VERSIONS),
            self::MIN_VERSION
        );
    }
}
