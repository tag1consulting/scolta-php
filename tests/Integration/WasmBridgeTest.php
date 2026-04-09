<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Wasm\ScoltaWasm;

/**
 * WASM bridge tests requiring libextism.
 *
 * Verifies the PHP-to-WASM bridge works correctly for core functions.
 * Skipped when the Extism native runtime is not available.
 */
class WasmBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        // WASM binary must exist — fail (not skip) if missing.
        $wasmPath = dirname(__DIR__, 2) . '/wasm/scolta_core.wasm';
        $this->assertFileExists(
            $wasmPath,
            "WASM binary not found at {$wasmPath}. This is a build error."
        );

        // Extism native runtime may not be installed — skip gracefully.
        try {
            ScoltaWasm::version();
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'FFI')
                || str_contains($e->getMessage(), 'libextism')
                || str_contains($e->getMessage(), 'Extism')
            ) {
                $this->markTestSkipped('Extism native runtime not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    public function testVersionReturnsValidSemver(): void
    {
        $version = ScoltaWasm::version();
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            $version,
            'version() should return a valid semver string'
        );
    }

    public function testCleanHtmlRemovesNavAndFooter(): void
    {
        $html = '<nav>Navigation</nav>'
            . '<div id="main-content"><p>Important content here</p></div>'
            . '<footer>Footer stuff</footer>';

        $cleaned = ScoltaWasm::cleanHtml($html, 'Test Page');

        $this->assertStringContainsString('Important content', $cleaned);
        $this->assertStringNotContainsString('Navigation', $cleaned);
        $this->assertStringNotContainsString('Footer stuff', $cleaned);
    }

    public function testCleanHtmlWithEmptyString(): void
    {
        $cleaned = ScoltaWasm::cleanHtml('', '');
        // Should not crash — may return empty or whitespace.
        $this->assertIsString($cleaned);
    }

    public function testResolvePromptSubstitutesValues(): void
    {
        $resolved = ScoltaWasm::resolvePrompt('expand_query', 'My Site', 'documentation portal');

        $this->assertStringContainsString('My Site', $resolved);
        $this->assertStringContainsString('documentation portal', $resolved);
        $this->assertStringNotContainsString('{SITE_NAME}', $resolved);
        $this->assertStringNotContainsString('{SITE_DESCRIPTION}', $resolved);
    }

    public function testScoreResultsWithEmptyArray(): void
    {
        $scored = ScoltaWasm::scoreResults([], [], 'test');
        $this->assertIsArray($scored);
        $this->assertEmpty($scored);
    }

    public function testDescribeReturnsAllFunctions(): void
    {
        // Verify the WASM module exposes the functions we depend on
        // by calling each one. If any is missing, Extism will throw.
        $functions = [
            'version' => fn () => ScoltaWasm::version(),
            'clean_html' => fn () => ScoltaWasm::cleanHtml('<p>test</p>', ''),
            'get_prompt' => fn () => ScoltaWasm::getPrompt('expand_query'),
            'resolve_prompt' => fn () => ScoltaWasm::resolvePrompt('expand_query', 'S', 'D'),
            'score_results' => fn () => ScoltaWasm::scoreResults([], [], ''),
            'build_pagefind_html' => fn () => ScoltaWasm::buildPagefindHtml('1', 'T', 'B', 'U', '2024-01-01'),
            'to_js_scoring_config' => fn () => ScoltaWasm::toJsScoringConfig([]),
            'parse_expansion' => fn () => ScoltaWasm::parseExpansion('["a","b"]'),
            'merge_results' => fn () => ScoltaWasm::mergeResults([], [], 0.7),
        ];

        foreach ($functions as $name => $callable) {
            try {
                $callable();
            } catch (\Exception $e) {
                $this->fail("WASM function '{$name}' is not available: " . $e->getMessage());
            }
        }

        $this->assertCount(9, $functions, 'All expected WASM functions should be tested');
    }
}
