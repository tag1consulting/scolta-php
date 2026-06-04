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
            'assets/ASSETS.sha256 is missing. Run: composer update-asset-manifest'
        );
    }

    public function testAllListedAssetsExist(): void
    {
        foreach (self::ASSETS as $rel) {
            $this->assertFileExists(
                $this->assetsDir . '/' . $rel,
                "Canonical asset assets/{$rel} is missing."
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
            'assets/ASSETS.sha256 is stale. Run: composer update-asset-manifest'
        );
    }
}
