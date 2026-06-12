<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;

/**
 * Vendored Snowball backend drift guard.
 *
 * The PHP stemmers in src/Index/Snowball/ are generated code pinned to the
 * exact snowball revision pagefind_stem 1.0.0 was generated from (see
 * src/Index/Snowball/PROVENANCE.md). This test pins every vendored file to
 * the sha256 manifest recorded there, so a silent regeneration — wrong
 * snowball revision, modified transform, stray hand edit — fails CI until
 * the manifest is consciously re-baselined in the same commit. The corpus
 * parity tests prove the code behaves like Pagefind; this one proves the
 * code itself has not moved without a paper trail.
 */
class SnowballProvenanceTest extends TestCase
{
    private const SNOWBALL_DIR = __DIR__ . '/../../src/Index/Snowball';

    /** @return array<string, string> file => sha256 */
    private static function manifest(): array
    {
        $provenance = file_get_contents(self::SNOWBALL_DIR . '/PROVENANCE.md');
        self::assertNotFalse($provenance, 'PROVENANCE.md missing from src/Index/Snowball');

        $rows = [];
        foreach (explode("\n", $provenance) as $line) {
            if (preg_match('/^\|\s*([A-Za-z]+\.php|LICENSE)\s*\|\s*`([0-9a-f]{64})`\s*\|/', $line, $m)) {
                $rows[$m[1]] = $m[2];
            }
        }

        return $rows;
    }

    public function testManifestCoversEveryVendoredFile(): void
    {
        $manifest = self::manifest();
        $this->assertNotEmpty($manifest, 'No sha256 manifest rows found in PROVENANCE.md');

        $onDisk = array_map(
            'basename',
            array_merge(
                glob(self::SNOWBALL_DIR . '/*.php') ?: [],
                glob(self::SNOWBALL_DIR . '/LICENSE') ?: [],
            ),
        );
        sort($onDisk);
        $listed = array_keys($manifest);
        sort($listed);

        $this->assertSame(
            $listed,
            $onDisk,
            'src/Index/Snowball contents must match the PROVENANCE.md manifest exactly',
        );
    }

    /**
     * @dataProvider vendoredFileProvider
     */
    public function testVendoredFileHashesMatchProvenance(string $file): void
    {
        $expected = self::manifest()[$file] ?? null;
        $this->assertNotNull($expected, "No manifest row for {$file} in PROVENANCE.md");

        $this->assertSame(
            $expected,
            hash_file('sha256', self::SNOWBALL_DIR . "/{$file}"),
            "{$file} changed without re-baselining src/Index/Snowball/PROVENANCE.md — "
            . 'vendored stemmers must only change via scripts/generate-stemmers.sh',
        );
    }

    /** @return array<string, string[]> */
    public static function vendoredFileProvider(): array
    {
        $cases = [];
        foreach (glob(self::SNOWBALL_DIR . '/*.php') ?: [] as $path) {
            $cases[basename($path)] = [basename($path)];
        }
        $cases['LICENSE'] = ['LICENSE'];

        return $cases;
    }
}
