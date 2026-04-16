<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Documentation;

use PHPUnit\Framework\TestCase;

/**
 * Validates that documentation files do not reference removed components.
 *
 * Scolta migrated from Extism/FFI (server-side WASM) to browser-side WASM
 * via wasm-bindgen. References to the old architecture in non-CHANGELOG docs
 * indicate stale documentation that will mislead readers.
 */
class ArchitectureAccuracyTest extends TestCase
{
    /**
     * Verify no stale architecture references remain in scolta-php docs.
     */
    public function test_documentation_contains_no_stale_architecture_references(): void
    {
        $staleTerms = ['ScoltaWasm', 'ExtismCheck', 'extism-pdk', 'wasm32-wasip1', 'ext-ffi'];
        $root       = dirname(__DIR__, 2);

        $docFiles = glob($root . '/*.md') ?: [];
        $docsDir  = $root . '/docs/';
        if (is_dir($docsDir)) {
            $docFiles = array_merge($docFiles, glob($docsDir . '*.md') ?: []);
        }

        foreach ($docFiles as $file) {
            // Skip CHANGELOG files — they document history.
            if (stripos(basename($file), 'changelog') !== false) {
                continue;
            }
            $content = file_get_contents($file);
            foreach ($staleTerms as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $content,
                    sprintf(
                        'File %s contains stale architecture reference "%s". '
                        . 'ScoltaWasm and ExtismCheck were removed. '
                        . 'Build target is wasm32-unknown-unknown, not wasm32-wasip1.',
                        basename($file),
                        $term
                    )
                );
            }
        }
    }

    /**
     * Verify no CI workflow steps silently swallow failures.
     *
     * continue-on-error: true in CI means failures are hidden, not fixed.
     * If a linting or test step fails, fix the code — don't mute the alarm.
     */
    public function test_ci_workflows_do_not_use_continue_on_error(): void
    {
        $packages   = ['scolta-core', 'scolta-php', 'scolta-drupal', 'scolta-laravel', 'scolta-wp'];
        $violations = [];

        foreach ($packages as $pkg) {
            $ciFile = dirname(__DIR__, 3) . "/$pkg/.github/workflows/ci.yml";
            if (! file_exists($ciFile)) {
                continue;
            }
            $content = file_get_contents($ciFile);
            if (preg_match_all('/continue-on-error:\s*true/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $lineNum      = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $violations[] = "$pkg/ci.yml line $lineNum: continue-on-error: true";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'CI workflows must not use continue-on-error: true. '
            . "Fix the underlying failure instead of muting it.\n"
            . implode("\n", $violations)
        );
    }
}
