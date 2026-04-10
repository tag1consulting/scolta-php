<?php

declare(strict_types=1);

namespace Tag1\Scolta;

use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Runs all pre-flight dependency checks for Scolta.
 *
 * Checks are split into two categories:
 * - Runtime: needed to serve search results (PHP, AI key, browser WASM)
 * - Build: needed for content indexing (FFI, Extism, server WASM, Pagefind)
 *
 * Returns a structured array of check results that platform adapters
 * can format for their CLI output. Each check has:
 *   - name: short label
 *   - status: 'pass', 'fail', 'warn', 'info'
 *   - message: human-readable detail
 *   - category: 'runtime' or 'build'
 */
final class SetupCheck
{
    /**
     * Run all checks and return results.
     *
     * @param string|null $configuredBinaryPath Pagefind binary from platform config.
     * @param string|null $projectDir           Project root for binary resolution.
     * @param string|null $aiApiKey             AI API key (pass the value, not the source).
     * @param string|null $wasmPath             Custom server WASM binary path, or null for default.
     * @param string|null $browserWasmDir       Directory containing browser WASM assets.
     *
     * @return array<array{name: string, status: string, message: string, category: string}>
     */
    public static function run(
        ?string $configuredBinaryPath = null,
        ?string $projectDir = null,
        ?string $aiApiKey = null,
        ?string $wasmPath = null,
        ?string $browserWasmDir = null,
    ): array {
        $results = [];

        // ---- Runtime Requirements ----

        // 1. PHP version
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.1.0', '>=');
        $results[] = [
            'name' => 'PHP version',
            'status' => $phpOk ? 'pass' : 'fail',
            'message' => $phpOk
                ? "PHP {$phpVersion}"
                : "PHP {$phpVersion} — requires 8.1+",
            'category' => 'runtime',
        ];

        // 2. AI API key
        $hasKey = !empty($aiApiKey);
        $results[] = [
            'name' => 'AI API key',
            'status' => $hasKey ? 'pass' : 'warn',
            'message' => $hasKey
                ? 'AI API key configured'
                : 'AI API key not set — AI features disabled. Set SCOLTA_API_KEY environment variable.',
            'category' => 'runtime',
        ];

        // 3. Browser WASM assets
        $browserDir = $browserWasmDir ?? dirname(__DIR__) . '/assets/wasm';
        $browserWasmExists = file_exists($browserDir . '/scolta_core_bg.wasm');
        $browserJsExists = file_exists($browserDir . '/scolta_core.js');
        $results[] = [
            'name' => 'Browser WASM',
            'status' => ($browserWasmExists && $browserJsExists) ? 'pass' : 'warn',
            'message' => ($browserWasmExists && $browserJsExists)
                ? 'Browser WASM assets found'
                : 'Browser WASM assets missing — client-side scoring unavailable',
            'category' => 'runtime',
        ];

        // ---- Build Requirements (for content indexing) ----

        // 4. FFI extension
        $ffiLoaded = extension_loaded('ffi');
        $ffiEnabled = ini_get('ffi.enable');
        $ffiOk = $ffiLoaded && in_array($ffiEnabled, ['1', 'true', 'preload'], true);
        $results[] = [
            'name' => 'FFI extension',
            'status' => $ffiOk ? 'pass' : 'info',
            'message' => $ffiOk
                ? "FFI loaded (ffi.enable={$ffiEnabled})"
                : 'FFI not available — only needed for content indexing CLI commands',
            'category' => 'build',
        ];

        // 5. Extism shared library
        $extismLibFound = false;
        $searchPaths = ['/usr/local/lib/libextism.so', '/usr/local/lib/libextism.dylib', '/usr/lib/libextism.so'];
        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                $extismLibFound = true;
                break;
            }
        }
        $results[] = [
            'name' => 'Extism shared library',
            'status' => $extismLibFound ? 'pass' : 'info',
            'message' => $extismLibFound
                ? 'Extism shared library found'
                : 'libextism not found — only needed for content indexing CLI commands',
            'category' => 'build',
        ];

        // 6. Extism PHP SDK
        $extismSdk = class_exists(\Extism\Plugin::class);
        $results[] = [
            'name' => 'Extism PHP SDK',
            'status' => $extismSdk ? 'pass' : 'info',
            'message' => $extismSdk
                ? 'Extism PHP SDK installed'
                : 'Extism PHP SDK not installed — only needed for content indexing CLI commands',
            'category' => 'build',
        ];

        // 7. Server WASM binary
        $wasmFile = $wasmPath ?? dirname(__DIR__) . '/wasm/scolta_core.wasm';
        $wasmExists = file_exists($wasmFile);
        $results[] = [
            'name' => 'Server WASM binary',
            'status' => $wasmExists ? 'pass' : 'info',
            'message' => $wasmExists
                ? "scolta_core.wasm found"
                : 'Server WASM not found — only needed for content indexing CLI commands',
            'category' => 'build',
        ];

        // 7b. WASM load test (only if binary exists and runtime available)
        if ($wasmExists && $ffiOk && $extismSdk) {
            try {
                \Tag1\Scolta\Wasm\ScoltaWasm::version();
                $results[] = [
                    'name' => 'WASM load test',
                    'status' => 'pass',
                    'message' => 'WASM module loads and responds',
                    'category' => 'build',
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'name' => 'WASM load test',
                    'status' => 'warn',
                    'message' => 'WASM binary exists but failed to load: ' . $e->getMessage(),
                    'category' => 'build',
                ];
            }
        }

        // 8. Pagefind binary
        $resolver = new PagefindBinary(
            configuredPath: $configuredBinaryPath,
            projectDir: $projectDir,
        );
        $binaryStatus = $resolver->status();
        $results[] = [
            'name' => 'Pagefind binary',
            'status' => $binaryStatus['available'] ? 'pass' : 'info',
            'message' => $binaryStatus['available']
                ? $binaryStatus['message']
                : 'Pagefind not found — only needed for content indexing CLI commands',
            'category' => 'build',
        ];

        return $results;
    }

    /**
     * Determine overall exit code from check results.
     *
     * Returns 0 if all critical checks pass, 1 if any 'fail'.
     * Info/warn items (build dependencies, API key) don't cause failure.
     */
    public static function exitCode(array $results): int
    {
        foreach ($results as $result) {
            if ($result['status'] === 'fail') {
                return 1;
            }
        }
        return 0;
    }
}
