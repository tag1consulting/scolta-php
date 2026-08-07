<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Documentation;

use PHPUnit\Framework\TestCase;

/**
 * Structural gate: every named public method in src/ must carry @since and
 * @stability PHPDoc tags (repo CLAUDE.md mandate; UPGRADE.md's 1.0.0 notes
 * promise stability annotations on the whole public API).
 *
 * Source-parse in the HygieneTest pattern so non-compliant methods cannot be
 * reintroduced. Magic methods (__construct etc.) are excluded, matching the
 * existing convention — constructor contracts are documented via @param.
 */
class StabilityAnnotationTest extends TestCase
{
    /**
     * Phantom @since values that predate this guard.
     *
     * Each names a development line that was opened, stamped into @since, and
     * then renamed before it shipped: 0.3.11, 0.3.12 and 0.4.0 were folded
     * into 1.0.0, and 1.0.6 became 1.1.0. None was ever tagged or given a
     * CHANGELOG entry, so every one of them is the same defect this test
     * exists to stop. They are recorded rather than swept because correcting
     * them means deciding, per annotation, which release actually carried the
     * symbol — a judgment call, not a rename. Fixing them retires entries from
     * this list; nothing should ever be added to it.
     */
    private const UNSHIPPED_LINES_PREDATING_THIS_GUARD = [
        '0.3.11',
        '0.3.12',
        '0.4.0',
        '1.0.6',
    ];

    /**
     * A version-numbered @since must name a version that exists.
     *
     * composer.json's `version`, the branch alias and scolta.info.yml are all
     * checked for coherence in CI, but @since is a parallel version surface
     * that nothing validated. That gap is what let the 1.1.1 development line
     * survive in 58 annotations after the line itself was renamed to 1.2 —
     * every one of them promising an API in a release that will never be cut.
     *
     * The oracle is CHANGELOG.md's release headings plus the version currently
     * in development, not `git tag`: CI checks out at depth 1 with no tags, so
     * a tag-based rule would see an empty release list and either pass
     * vacuously or fail on every annotation in the tree.
     */
    public function testEverySinceNamesAVersionThatExists(): void
    {
        $known = $this->knownVersions();
        $violations = [];

        foreach ($this->srcFiles() as $path) {
            $relative = substr($path, strlen(dirname(__DIR__, 2)) + 1);
            foreach ($this->strandedSince(file_get_contents($path), $known) as $line => $version) {
                $violations[] = "{$relative}:{$line} @since {$version}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            '@since names a version that was never released and is not the line '
            . "currently in development.\nEither the annotation is stale, or the "
            . "release it names still needs a CHANGELOG entry:\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /**
     * The rule must actually fire — a guard that cannot fail guards nothing.
     */
    public function testStrandedSinceIsDetected(): void
    {
        $source = "<?php\n/**\n * @since 1.1.1\n * @stability experimental\n */\n";

        $this->assertSame(
            [3 => '1.1.1'],
            $this->strandedSince($source, ['1.2.0']),
            'A @since naming an unreleased line must be reported.',
        );
        $this->assertSame(
            [],
            $this->strandedSince($source, ['1.1.1', '1.2.0']),
            'A @since naming a known version must be accepted.',
        );
    }

    /**
     * Map of line number => version for each @since naming an unknown version.
     *
     * @param string[] $known
     *
     * @return array<int, string>
     */
    private function strandedSince(string $source, array $known): array
    {
        $stranded = [];

        foreach (explode("\n", $source) as $index => $line) {
            if (!preg_match('/@since\s+(\d+\.\d+\.\d+)/', $line, $m)) {
                continue;
            }
            if (in_array($m[1], $known, true)) {
                continue;
            }
            if (in_array($m[1], self::UNSHIPPED_LINES_PREDATING_THIS_GUARD, true)) {
                continue;
            }
            $stranded[$index + 1] = $m[1];
        }

        return $stranded;
    }

    /**
     * Released versions (CHANGELOG headings) plus the version in development.
     *
     * @return string[]
     */
    private function knownVersions(): array
    {
        $root = dirname(__DIR__, 2);

        preg_match_all(
            '/^## \[(\d+\.\d+\.\d+)\]/m',
            file_get_contents($root . '/CHANGELOG.md'),
            $m,
        );
        $versions = $m[1];
        $this->assertNotEmpty($versions, 'CHANGELOG.md lists no releases.');

        // The in-development line, which has no CHANGELOG heading until it is
        // stamped: 1.2.0-dev and 1.2.0 both authorise `@since 1.2.0`.
        $composer = json_decode(file_get_contents($root . '/composer.json'), true);
        $versions[] = preg_replace('/-dev$/', '', $composer['version']);

        return $versions;
    }

    public function testEveryPublicMethodCarriesSinceAndStability(): void
    {
        $violations = [];

        foreach ($this->srcFiles() as $path) {
            $relative = substr($path, strlen(dirname(__DIR__, 2)) + 1);
            $lines = file($path);
            $count = count($lines);

            for ($i = 0; $i < $count; $i++) {
                $name = $this->publicMethodName($lines[$i]);
                if ($name === null || str_starts_with($name, '__')) {
                    continue;
                }

                $docblock = $this->precedingDocblock($lines, $i);
                if ($docblock === null) {
                    $violations[] = "{$relative}:{$name}() has no PHPDoc block";
                    continue;
                }
                if (!str_contains($docblock, '@since')) {
                    $violations[] = "{$relative}:{$name}() docblock lacks @since";
                }
                if (!str_contains($docblock, '@stability')) {
                    $violations[] = "{$relative}:{$name}() docblock lacks @stability";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Public methods missing @since/@stability annotations (CLAUDE.md mandate):\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /** @return string[] */
    private function srcFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 2) . '/src',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            // Vendored generated code (see src/Index/Snowball/PROVENANCE.md)
            // is byte-stable by sha256 manifest and not part of the package's
            // annotated public API — the Stemmer wrapper is the contract.
            if (str_contains($file->getPathname(), '/src/Index/Snowball/')) {
                continue;
            }
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Extract the method name when the line declares a named public method.
     */
    private function publicMethodName(string $line): ?string
    {
        if (preg_match(
            '/^\s*(?:(?:final|abstract)\s+)*public\s+(?:static\s+)?function\s+&?(\w+)/',
            $line,
            $m,
        )) {
            return $m[1];
        }

        return null;
    }

    /**
     * Return the docblock immediately above line $i (skipping PHP attribute
     * lines), or null when none exists.
     *
     * @param string[] $lines
     */
    private function precedingDocblock(array $lines, int $i): ?string
    {
        $j = $i - 1;
        while ($j >= 0 && preg_match('/^\s*#\[/', $lines[$j])) {
            $j--;
        }
        if ($j < 0 || !preg_match('/\*\/\s*$/', rtrim($lines[$j]))) {
            return null;
        }

        $end = $j;
        $start = $end;
        while ($start >= 0 && !preg_match('/^\s*\/\*\*/', $lines[$start])) {
            $start--;
        }
        if ($start < 0) {
            return null;
        }

        return implode('', array_slice($lines, $start, $end - $start + 1));
    }
}
