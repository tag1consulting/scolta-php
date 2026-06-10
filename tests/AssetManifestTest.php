<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the committed asset manifest (assets/ASSETS.sha256).
 *
 * The four canonical front-end assets are duplicated into scolta-drupal and
 * scolta-wp because Composer does not run a dependency's scripts on install.
 * Those adapters verify their committed copies against this manifest in CI, so
 * a stale manifest here would silently let drift through downstream. This test
 * regenerates the manifest in memory and fails if the committed file is stale
 * or if any listed asset is missing.
 */
class AssetManifestTest extends TestCase
{
    /**
     * The canonical front-end assets, as paths relative to assets/.
     * This list MUST stay in sync with the `update-asset-manifest` composer
     * script (the single source of truth for "what files are duplicated").
     */
    private const ASSETS = [
        'js/scolta.js',
        'css/scolta.css',
        'wasm/scolta_core.js',
        'wasm/scolta_core_bg.wasm',
    ];

    private string $assetsDir;

    protected function setUp(): void
    {
        $this->assetsDir = dirname(__DIR__) . '/assets';
    }

    public function testManifestFileExists(): void
    {
        $this->assertFileExists(
            $this->assetsDir . '/ASSETS.sha256',
            'assets/ASSETS.sha256 is missing. Run: composer update-asset-manifest',
        );
    }

    public function testAllListedAssetsExist(): void
    {
        foreach (self::ASSETS as $rel) {
            $this->assertFileExists(
                $this->assetsDir . '/' . $rel,
                "Canonical asset assets/{$rel} is missing.",
            );
        }
    }

    public function testManifestIsUpToDate(): void
    {
        $expected = '';
        foreach (self::ASSETS as $rel) {
            $path = $this->assetsDir . '/' . $rel;
            $this->assertFileExists($path, "Canonical asset assets/{$rel} is missing.");
            $expected .= hash_file('sha256', $path) . '  ' . $rel . "\n";
        }

        $committed = file_get_contents($this->assetsDir . '/ASSETS.sha256');

        $this->assertSame(
            $expected,
            $committed,
            'assets/ASSETS.sha256 is stale. Run: composer update-asset-manifest',
        );
    }

    /**
     * The standalone scolta.js.sha256 is a projection of the manifest, not an
     * independent hash. It MUST equal the manifest's js/scolta.js line so the
     * JS hash can only ever come from one computation.
     */
    public function testStandaloneJsChecksumMatchesManifest(): void
    {
        $standalonePath = $this->assetsDir . '/js/scolta.js.sha256';
        $this->assertFileExists(
            $standalonePath,
            'assets/js/scolta.js.sha256 is missing. Run: composer update-js-checksum',
        );

        $manifestHash = $this->manifestHashFor('js/scolta.js');
        $this->assertNotNull(
            $manifestHash,
            'assets/ASSETS.sha256 has no js/scolta.js line to derive the standalone checksum from.',
        );

        $standalone = trim(file_get_contents($standalonePath));
        $this->assertSame(
            $manifestHash,
            $standalone,
            'assets/js/scolta.js.sha256 has drifted from the manifest. Run: composer update-js-checksum',
        );
    }

    /**
     * scolta-laravel's HealthController and StatusCommand trim() this file and
     * compare it to hash_file('sha256', ...), so it must stay a bare 64-hex
     * hash with no path or sha256sum two-column format. This contract assertion
     * makes a switch to a different format fail loudly here.
     */
    public function testStandaloneJsChecksumIsBareHash(): void
    {
        $raw = file_get_contents($this->assetsDir . '/js/scolta.js.sha256');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}\n$/',
            $raw,
            'assets/js/scolta.js.sha256 must be a bare 64-hex-char hash followed by a single newline (the format scolta-laravel reads).',
        );
    }

    /**
     * Returns the hash on the manifest line for the given relative path, or
     * null if no such line exists. Parses the standard sha256sum format:
     * "<hash><space><space><relative-path>".
     */
    private function manifestHashFor(string $relativePath): ?string
    {
        $lines = file($this->assetsDir . '/ASSETS.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2 && $parts[1] === $relativePath) {
                return $parts[0];
            }
        }
        return null;
    }
}
