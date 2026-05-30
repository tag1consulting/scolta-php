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

        // PhpIndexer writes into a pagefind/ subdirectory of outputDir (atomic
        // swap from .scolta-building → pagefind/). The binary pipeline also
        // uses --output-path {outputDir}/pagefind. Check both locations so the
        // health check works regardless of which pipeline last built the index.
        $indexExists = file_exists($this->indexOutputDir . '/pagefind/pagefind.js')
            || file_exists($this->indexOutputDir . '/pagefind.js');

        $aiConfigured = trim($this->config->aiApiKey) !== '';

        $status = 'ok';
        if (!$indexExists || !$aiConfigured) {
            $status = 'degraded';
        }

        $configuredIndexer = $this->config->indexer ?? 'auto';
        $indexerActive = ($configuredIndexer === 'binary' && $binaryStatus['available']) ? 'binary' : 'php';
        $upgradeMessage = ($configuredIndexer === 'binary' && !$binaryStatus['available'])
            ? 'Pagefind binary not found. Set indexer to "php" or install Pagefind: npm install -g pagefind'
            : null;

        $staleIndex = $this->detectStaleArtifactUrls();

        if ($staleIndex) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'ai_provider' => $this->config->aiProvider ?: 'anthropic',
            'ai_configured' => $aiConfigured,
            'pagefind_available' => $binaryStatus['available'],
            'wasm_available' => false,
            'index_exists' => $indexExists,
            'indexer_active' => $indexerActive,
            'indexer_upgrade_available' => ($configuredIndexer === 'binary' && !$binaryStatus['available']),
            'indexer_upgrade_message' => $upgradeMessage,
            'stale_artifact_urls' => $staleIndex,
            'stale_artifact_message' => $staleIndex
                ? 'Index contains /{id}.html URLs from a pre-1.1.0 binary build. Run a full rebuild to fix.'
                : null,
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

    /**
     * Sample index fragments for /{id}.html-shaped URLs.
     *
     * Pre-1.1.0 binary builds stored flat file paths as data.url. These
     * 404 on the live site. Sampling a few fragments is enough to flag
     * the issue without scanning the entire index.
     */
    private function detectStaleArtifactUrls(): bool
    {
        $indexDir = file_exists($this->indexOutputDir . '/pagefind/pagefind-entry.json')
            ? $this->indexOutputDir . '/pagefind'
            : $this->indexOutputDir;

        $fragmentDir = is_dir($indexDir . '/fragment') ? $indexDir . '/fragment' : $indexDir;
        $fragments = glob($fragmentDir . '/*.pf_fragment');

        if (empty($fragments)) {
            return false;
        }

        $sample = array_slice($fragments, 0, 5);
        foreach ($sample as $file) {
            $data = @gzdecode((string) file_get_contents($file));
            if ($data === false) {
                continue;
            }
            if (str_starts_with($data, 'pagefind_dcd')) {
                $data = substr($data, 12);
            }
            $json = json_decode($data, true);
            if (!is_array($json) || !isset($json['url'])) {
                continue;
            }
            if (preg_match('#^/[a-zA-Z0-9_-]+\.html$#', $json['url'])) {
                return true;
            }
        }

        return false;
    }
}
