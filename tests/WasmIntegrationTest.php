<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;
use Tag1\Scolta\Wasm\ScoltaWasm;

/**
 * Integration tests for WASM and pure-PHP components.
 *
 * WASM tests (version, cleanHtml, buildPagefindHtml) are skipped if
 * libextism is not installed. Pure-PHP tests (DefaultPrompts,
 * ScoltaConfig) always run.
 */
class WasmIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // WASM binary must exist — this is a developer error if missing.
        $wasmPath = dirname(__DIR__) . '/wasm/scolta_core.wasm';
        $this->assertFileExists(
            $wasmPath,
            "WASM binary not found at {$wasmPath}. Run 'composer build-wasm' or copy from scolta-core build."
        );

        // Extism native runtime may not be installed — skip gracefully.
        try {
            ScoltaWasm::version();
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'FFI') || str_contains($e->getMessage(), 'libextism') || str_contains($e->getMessage(), 'Extism') || str_contains($e->getMessage(), 'shared object')) {
                $this->markTestSkipped('Extism native runtime not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------
    // ScoltaWasm core functions
    // -------------------------------------------------------------------

    public function testVersion(): void
    {
        $version = ScoltaWasm::version();
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-\w+)?$/', $version);
    }

    public function testCleanHtml(): void
    {
        $html = '<nav>Skip</nav><div id="main-content"><p>Good content</p></div><footer>Skip</footer>';
        $cleaned = ScoltaWasm::cleanHtml($html, '');
        $this->assertStringContainsString('Good content', $cleaned);
        $this->assertStringNotContainsString('Skip', $cleaned);
    }

    public function testBuildPagefindHtml(): void
    {
        $html = ScoltaWasm::buildPagefindHtml(
            'doc-1',
            'Test Title',
            'Body text',
            'https://example.com',
            '2024-01-01',
            'Site',
        );
        $this->assertStringContainsString('data-pagefind-body', $html);
        $this->assertStringContainsString('Test Title', $html);
        $this->assertStringContainsString('Body text', $html);
    }

    // -------------------------------------------------------------------
    // DefaultPrompts (pure PHP, no WASM)
    // -------------------------------------------------------------------

    public function testDefaultPromptsResolveKnownTemplate(): void
    {
        $result = DefaultPrompts::resolve(DefaultPrompts::EXPAND_QUERY, 'Test Site', 'docs');
        $this->assertStringContainsString('Test Site', $result);
        $this->assertStringContainsString('docs', $result);
        $this->assertStringNotContainsString('{SITE_NAME}', $result);
    }

    public function testDefaultPromptsGetTemplate(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);
        $this->assertStringContainsString('{SITE_NAME}', $template);
    }

    // -------------------------------------------------------------------
    // ScoltaConfig → JS config (pure PHP)
    // -------------------------------------------------------------------

    public function testScoltaConfigToJsScoringConfig(): void
    {
        $config = ScoltaConfig::fromArray([
            'title_match_boost' => 2.0,
            'results_per_page' => 25,
            'ai_expand_query' => false,
        ]);

        $jsConfig = $config->toJsScoringConfig();
        $this->assertIsArray($jsConfig);
        $this->assertEquals(2.0, $jsConfig['TITLE_MATCH_BOOST']);
        $this->assertEquals(25, $jsConfig['RESULTS_PER_PAGE']);
        $this->assertFalse($jsConfig['AI_EXPAND_QUERY']);
    }

}
