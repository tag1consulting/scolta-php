<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Source-parse hygiene tests that prevent reintroduction of suppressed-error patterns.
 */
class HygieneTest extends TestCase
{
    public function testNoSuppressedMkdirInProductionCode(): void
    {
        $files = [
            __DIR__ . '/../src/Index/IndexMerger.php',
            __DIR__ . '/../src/Index/PagefindFormatWriter.php',
            __DIR__ . '/../src/Index/StreamingFormatWriter.php',
        ];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/@mkdir\s*\(/',
                $source,
                basename($file) . ' must not use @mkdir — use is_dir() fallback instead.'
            );
        }
    }

    public function testAtUnlinkOnlyInBuildState(): void
    {
        $srcDir = __DIR__ . '/../src/Index/';
        foreach (glob($srcDir . '*.php') as $file) {
            if (basename($file) === 'BuildState.php') {
                continue;
            }
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/@unlink\s*\(/',
                $source,
                basename($file) . ' must not use @unlink — only BuildState.php is exempted for lock cleanup.'
            );
        }
    }

    public function testIndexMergerDoesNotUseUniqidMoreEntropy(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Index/IndexMerger.php');
        $this->assertDoesNotMatchRegularExpression(
            '/uniqid\s*\([^)]*,\s*true\s*\)/i',
            $source,
            'IndexMerger must not use uniqid(..., true) — periods in directory names can confuse cleanup scripts.'
        );
    }

    public function testUnserializeAlwaysRestrictsClasses(): void
    {
        $srcDir = __DIR__ . '/../src/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (strpos($source, 'unserialize(') === false) {
                continue;
            }
            preg_match_all('/unserialize\s*\([^;]+\)/s', $source, $matches);
            foreach ($matches[0] as $call) {
                $this->assertStringContainsString(
                    'allowed_classes',
                    $call,
                    basename($file->getPathname()) . ': unserialize() must specify [\'allowed_classes\' => false]'
                );
            }
        }
    }

    public function testFilePutContentsAlwaysChecked(): void
    {
        $srcDir = __DIR__ . '/../src/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        $scanned = 0;
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            $source = file_get_contents($file->getPathname());
            preg_match_all('/^\s*file_put_contents\s*\(/m', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$match, $offset]) {
                $preceding = substr($source, max(0, $offset - 100), 100);
                $this->assertMatchesRegularExpression(
                    '/(?:if\s*\(|return\s)/',
                    $preceding,
                    basename($file->getPathname()) . ': file_put_contents at offset ' . $offset . ' must be wrapped in an error check.'
                );
            }
        }
        $this->assertGreaterThan(0, $scanned, 'Expected to scan at least one PHP source file.');
    }
}
