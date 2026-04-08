<?php

declare(strict_types=1);

namespace Tag1\Scolta;

use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Runs all pre-flight dependency checks for Scolta.
 *
 * Returns a structured array of check results that platform adapters
 * can format for their CLI output. Each check has:
 *   - name: short label
 *   - status: 'pass', 'fail', 'warn'
 *   - message: human-readable detail
 */
final class SetupCheck
{
    /**
     * Run all checks and return results.
     *
     * @param string|null $configuredBinaryPath Pagefind binary from platform config.
     * @param string|null $projectDir           Project root for binary resolution.
     * @param string|null $aiApiKey             AI API key (pass the value, not the source).
     * @param string|null $wasmPath             Custom WASM binary path, or null for default.
     *
     * @return array<array{name: string, status: string, message: string}>
     */
    public static function run(
        ?string $configuredBinaryPath = null,
        ?string $projectDir = null,
        ?string $aiApiKey = null,
        ?string $wasmPath = null,
    ): array {
        $results = [];

        // 1. PHP version
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.1.0', '>=');
        $results[] = [
            'name' => 'PHP version',
            'status' => $phpOk ? 'pass' : 'fail',
            'message' => $phpOk
                ? "PHP {$phpVersion}"
                : "PHP {$phpVersion} — requires 8.1+",
        ];

        // 2. FFI extension
        $ffiLoaded = extension_loaded('ffi');
        $ffiEnabled = ini_get('ffi.enable');
        $ffiOk = $ffiLoaded && in_array($ffiEnabled, ['1', 'true', 'preload'], true);
        $results[] = [
            'name' => 'FFI extension',
            'status' => $ffiOk ? 'pass' : 'fail',
            'message' => $ffiOk
                ? "FFI loaded (ffi.enable={$ffiEnabled})"
                : ($ffiLoaded
                    ? "FFI loaded but disabled (ffi.enable={$ffiEnabled}) — set to true"
                    : 'FFI extension not loaded — enable in php.ini'),
        ];

        // 3. Extism shared library
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
            'status' => $extismLibFound ? 'pass' : 'fail',
            'message' => $extismLibFound
                ? 'Extism shared library found'
                : 'libextism not found — install: curl -s https://get.extism.org/cli | bash && sudo extism lib install',
        ];

        // 4. Extism PHP SDK
        $extismSdk = class_exists(\Extism\Plugin::class);
        $results[] = [
            'name' => 'Extism PHP SDK',
            'status' => $extismSdk ? 'pass' : 'fail',
            'message' => $extismSdk
                ? 'Extism PHP SDK installed'
                : 'Extism PHP SDK not found — install: composer require extism/extism',
        ];

        // 5. WASM binary
        $wasmFile = $wasmPath ?? dirname(__DIR__) . '/wasm/scolta_core.wasm';
        $wasmExists = file_exists($wasmFile);
        $results[] = [
            'name' => 'WASM binary',
            'status' => $wasmExists ? 'pass' : 'fail',
            'message' => $wasmExists
                ? "scolta_core.wasm found ({$wasmFile})"
                : "scolta_core.wasm not found at {$wasmFile}",
        ];

        // 6. Pagefind binary
        $resolver = new PagefindBinary(
            configuredPath: $configuredBinaryPath,
            projectDir: $projectDir,
        );
        $binaryStatus = $resolver->status();
        $results[] = [
            'name' => 'Pagefind binary',
            'status' => $binaryStatus['available'] ? 'pass' : 'warn',
            'message' => $binaryStatus['available']
                ? $binaryStatus['message']
                : 'Pagefind not found — run download-pagefind or: npm install -g pagefind',
        ];

        // 7. AI API key
        $hasKey = !empty($aiApiKey);
        $results[] = [
            'name' => 'AI API key',
            'status' => $hasKey ? 'pass' : 'warn',
            'message' => $hasKey
                ? 'AI API key configured'
                : 'AI API key not set — AI features disabled. Set SCOLTA_API_KEY environment variable.',
        ];

        return $results;
    }

    /**
     * Determine overall exit code from check results.
     *
     * Returns 0 if all critical checks pass, 1 if any 'fail'.
     * Warnings (pagefind, API key) don't cause failure.
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
