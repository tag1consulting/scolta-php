<?php

declare(strict_types=1);

namespace Tag1\Scolta\Wasm;

use Extism\Manifest;
use Extism\Manifest\PathWasmSource;
use Extism\Plugin;

/**
 * Build-time bridge between PHP and the scolta-core WebAssembly module.
 *
 * Loads scolta_core.wasm via Extism and provides typed PHP methods for
 * build-time WASM functions: HTML cleaning and Pagefind document generation.
 *
 * Runtime scoring, merging, and expansion parsing now run in the browser
 * via the browser WASM module (wasm-bindgen). This class is only needed
 * for content indexing CLI commands (build, export, rebuild-index).
 *
 * Debug mode: Call ScoltaWasm::enableDebug() to log all WASM calls
 * with input/output sizes and timing. Retrieve with getDebugLog().
 */
class ScoltaWasm
{
    private static ?Plugin $plugin = null;
    private static bool $debug = false;
    private static array $debugLog = [];
    private static ?string $wasmPath = null;

    /**
     * Set a custom path to the WASM binary.
     *
     * By default, looks for wasm/scolta_core.wasm relative to this package.
     */
    public static function setWasmPath(string $path): void
    {
        self::$wasmPath = $path;
        self::$plugin = null; // Force reload
    }

    /**
     * Enable debug logging for all WASM calls.
     */
    public static function enableDebug(): void
    {
        self::$debug = true;
    }

    /**
     * Disable debug logging.
     */
    public static function disableDebug(): void
    {
        self::$debug = false;
    }

    /**
     * Get the debug log of all WASM calls since last clear.
     *
     * @return array<int, array{function: string, input_size: int, output_size: int, time_ms: float, input_preview: string, output_preview: string}>
     */
    public static function getDebugLog(): array
    {
        return self::$debugLog;
    }

    /**
     * Clear the debug log.
     */
    public static function clearDebugLog(): void
    {
        self::$debugLog = [];
    }

    /**
     * Get the loaded Extism plugin instance.
     */
    private static function plugin(): Plugin
    {
        if (self::$plugin === null) {
            \Tag1\Scolta\ExtismCheck::verify();

            $path = self::$wasmPath ?? dirname(__DIR__, 2) . '/wasm/scolta_core.wasm';

            if (!file_exists($path)) {
                throw new \RuntimeException(
                    "Scolta WASM module not found at: {$path}. "
                    . 'Run `cargo build --release --target wasm32-wasip1` in scolta-core/ '
                    . 'and copy the .wasm file to scolta-php/wasm/'
                );
            }

            $manifest = new Manifest(new PathWasmSource($path));
            self::$plugin = new Plugin($manifest, withWasiOrOptions: true);
        }

        return self::$plugin;
    }

    /**
     * Call a WASM function with optional debug logging.
     */
    private static function call(string $function, string $input): string
    {
        $start = hrtime(true);
        $output = self::plugin()->call($function, $input);
        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms

        if (self::$debug) {
            self::$debugLog[] = [
                'function' => $function,
                'input_size' => strlen($input),
                'output_size' => strlen($output),
                'time_ms' => round($elapsed, 3),
                'input_preview' => substr($input, 0, 200),
                'output_preview' => substr($output, 0, 200),
            ];
        }

        return $output;
    }

    // -- Build-time API methods --

    /**
     * Clean HTML by removing page chrome and extracting main content.
     *
     * Used during content export to prepare HTML for Pagefind indexing.
     * This is a build-time operation — not called during search requests.
     */
    public static function cleanHtml(string $html, string $title = ''): string
    {
        return self::call('clean_html', json_encode([
            'html' => $html,
            'title' => $title,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Build a Pagefind-compatible HTML document.
     *
     * Used during content export to generate indexed documents.
     * This is a build-time operation — not called during search requests.
     */
    public static function buildPagefindHtml(string $id, string $title, string $body, string $url, string $date, string $siteName = ''): string
    {
        return self::call('build_pagefind_html', json_encode([
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'date' => $date,
            'site_name' => $siteName,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve all prompt templates with site-specific values.
     *
     * Call this at build time or when admin saves settings to cache the
     * resolved prompts. API proxy endpoints read cached prompts at runtime
     * without needing WASM.
     *
     * @return array{expand_query: string, summarize: string, follow_up: string}
     */
    public static function resolveAllPrompts(string $siteName, string $siteDescription = 'website'): array
    {
        return [
            'expand_query' => self::call('resolve_prompt', json_encode([
                'prompt_name' => 'expand_query',
                'site_name' => $siteName,
                'site_description' => $siteDescription,
            ], JSON_THROW_ON_ERROR)),
            'summarize' => self::call('resolve_prompt', json_encode([
                'prompt_name' => 'summarize',
                'site_name' => $siteName,
                'site_description' => $siteDescription,
            ], JSON_THROW_ON_ERROR)),
            'follow_up' => self::call('resolve_prompt', json_encode([
                'prompt_name' => 'follow_up',
                'site_name' => $siteName,
                'site_description' => $siteDescription,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Get the WASM module version.
     */
    public static function version(): string
    {
        return self::call('version', '');
    }
}
