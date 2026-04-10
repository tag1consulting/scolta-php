<?php

declare(strict_types=1);

namespace Tag1\Scolta;

use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Runs pre-flight dependency checks for Scolta.
 *
 * Returns a structured array of check results that platform adapters
 * can format for their CLI output. Each check has:
 *   - name: short label
 *   - status: 'pass', 'fail', 'warn'
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
     * @param string|null $browserWasmDir       Directory containing browser WASM assets.
     *
     * @return array<array{name: string, status: string, message: string, category: string}>
     */
    public static function run(
        ?string $configuredBinaryPath = null,
        ?string $projectDir = null,
        ?string $aiApiKey = null,
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
                : 'AI API key not set — AI features disabled',
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

        // 4. Pagefind binary
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
                : 'Pagefind not found — needed for content indexing',
            'category' => 'build',
        ];

        return $results;
    }

    /**
     * Determine overall exit code from check results.
     *
     * Returns 0 if all critical checks pass, 1 if any 'fail'.
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
