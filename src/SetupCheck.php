<?php

declare(strict_types=1);

namespace Tag1\Scolta;

use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Runs pre-flight dependency checks for Scolta.
 *
 * Returns structured check results for platform adapters to format.
 */
final class SetupCheck
{
    /**
     * Run all checks and return results.
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

        // ---- Runtime ----
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.1.0', '>=');
        $results[] = [
            'name' => 'PHP version',
            'status' => $phpOk ? 'pass' : 'fail',
            'message' => $phpOk ? "PHP {$phpVersion}" : "PHP {$phpVersion} — requires 8.1+",
            'category' => 'runtime',
        ];

        $hasKey = !empty($aiApiKey);
        $results[] = [
            'name' => 'AI API key',
            'status' => $hasKey ? 'pass' : 'warn',
            'message' => $hasKey ? 'AI API key configured' : 'AI API key not set — AI features disabled',
            'category' => 'runtime',
        ];

        $browserDir = $browserWasmDir ?? dirname(__DIR__) . '/assets/wasm';
        $browserWasmExists = file_exists($browserDir . '/scolta_core_bg.wasm');
        $browserJsExists = file_exists($browserDir . '/scolta_core.js');
        $results[] = [
            'name' => 'Browser WASM',
            'status' => ($browserWasmExists && $browserJsExists) ? 'pass' : 'warn',
            'message' => ($browserWasmExists && $browserJsExists) ? 'Browser WASM assets found' : 'Browser WASM assets missing',
            'category' => 'runtime',
        ];

        // ---- Build ----
        $resolver = new PagefindBinary(configuredPath: $configuredBinaryPath, projectDir: $projectDir);
        $binaryStatus = $resolver->status();
        $results[] = [
            'name' => 'Pagefind binary',
            'status' => $binaryStatus['available'] ? 'pass' : 'warn',
            'message' => $binaryStatus['available'] ? $binaryStatus['message'] : 'Pagefind not found — PHP indexer will be used',
            'category' => 'build',
        ];

        return $results;
    }

    /**
     * Run all environment checks including indexing-specific validations.
     *
     * @param string $outputDir The directory where index files will be written.
     * @return array<int, array{level: string, message: string}>
     */
    public static function runAll(string $outputDir): array
    {
        $results = [];
        $results[] = self::checkIntlExtension();
        $results[] = self::checkMemoryLimit();
        $results[] = self::checkMaxExecutionTime();
        $results[] = self::checkOutputDirectoryWritable($outputDir);

        return $results;
    }

    /**
     * Check that the intl extension is loaded (required for Unicode tokenization).
     *
     * @return array{level: string, message: string}
     */
    public static function checkIntlExtension(): array
    {
        if (extension_loaded('intl')) {
            return ['level' => 'ok', 'message' => 'intl extension loaded'];
        }

        return [
            'level' => 'warning',
            'message' => 'intl extension not loaded — Unicode tokenization and stemming may degrade',
        ];
    }

    /**
     * Check that memory_limit is sufficient for indexing.
     *
     * @return array{level: string, message: string}
     */
    public static function checkMemoryLimit(): array
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return ['level' => 'ok', 'message' => 'Memory limit: unlimited'];
        }

        $bytes = self::returnBytes($limit);
        $minBytes = 128 * 1024 * 1024; // 128MB

        if ($bytes >= $minBytes) {
            return ['level' => 'ok', 'message' => "Memory limit: {$limit}"];
        }

        return [
            'level' => 'warning',
            'message' => "Memory limit {$limit} is below recommended 128M for indexing large sites",
        ];
    }

    /**
     * Check that max_execution_time allows indexing to complete.
     *
     * @return array{level: string, message: string}
     */
    public static function checkMaxExecutionTime(): array
    {
        $time = (int) ini_get('max_execution_time');
        if ($time === 0) {
            return ['level' => 'ok', 'message' => 'Execution time: unlimited'];
        }

        if ($time >= 120) {
            return ['level' => 'ok', 'message' => "Execution time: {$time}s"];
        }

        return [
            'level' => 'warning',
            'message' => "max_execution_time is {$time}s (recommend ≥120s for indexing; queue-based builds are unaffected)",
        ];
    }

    /**
     * Check that the output directory is writable.
     *
     * @return array{level: string, message: string}
     */
    public static function checkOutputDirectoryWritable(string $outputDir): array
    {
        if (is_dir($outputDir) && is_writable($outputDir)) {
            return ['level' => 'ok', 'message' => "Output directory writable: {$outputDir}"];
        }

        // Check parent directory for first-time builds.
        $parent = dirname($outputDir);
        if (is_dir($parent) && is_writable($parent)) {
            return ['level' => 'ok', 'message' => "Output directory will be created in: {$parent}"];
        }

        return [
            'level' => 'error',
            'message' => "Output directory not writable: {$outputDir}",
        ];
    }

    /**
     * Determine exit code from check results.
     */
    public static function exitCode(array $results): int
    {
        foreach ($results as $result) {
            if (($result['status'] ?? $result['level'] ?? '') === 'fail'
                || ($result['status'] ?? $result['level'] ?? '') === 'error') {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Convert PHP ini shorthand (128M, 1G) to bytes.
     */
    private static function returnBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;

        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
