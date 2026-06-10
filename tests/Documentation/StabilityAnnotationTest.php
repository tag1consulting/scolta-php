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
