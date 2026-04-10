<?php

declare(strict_types=1);

namespace Tag1\Scolta\Health;

use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Shared health check logic for all platform adapters.
 *
 * Each adapter constructs this with platform-specific paths and config,
 * then calls check() to get a structured result. Platform-specific fields
 * (Drupal AI module, Laravel tracker, etc.) are merged by the adapter.
 *
 * @since 0.2.0
 * @stability experimental
 */
final class HealthChecker
{
    public function __construct(
        private readonly ScoltaConfig $config,
        private readonly string $indexOutputDir,
        private readonly ?string $pagefindBinaryPath,
        private readonly ?string $projectDir,
    ) {
    }

    /**
     * Run all health checks and return a structured result.
     *
     * @return array{status: string, ai_configured: bool, ai_provider: string, pagefind_available: bool, wasm_available: bool, index_exists: bool, pagefind: array, wasm: array}
     */
    public function check(): array
    {
        $resolver = new PagefindBinary(
            configuredPath: $this->pagefindBinaryPath,
            projectDir: $this->projectDir,
        );
        $binaryStatus = $resolver->status();

        $indexExists = file_exists($this->indexOutputDir . '/pagefind.js');

        $aiConfigured = !empty($this->config->aiApiKey);

        $status = 'ok';
        if (!$indexExists || !$aiConfigured) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'ai_provider' => $this->config->aiProvider ?: 'anthropic',
            'ai_configured' => $aiConfigured,
            'pagefind_available' => $binaryStatus['available'],
            'wasm_available' => false,
            'index_exists' => $indexExists,
            'pagefind' => [
                'available' => $binaryStatus['available'],
                'version' => $binaryStatus['version'],
                'resolved_via' => $binaryStatus['via'],
            ],
            'wasm' => [
                'available' => false,
                'message' => 'Server-side WASM removed — HTML processing is now pure PHP',
            ],
        ];
    }
}
