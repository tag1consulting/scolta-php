<?php

declare(strict_types=1);

namespace Tag1\Scolta\Health;

use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Cache\CacheDriverInterface;
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
    /**
     * @param CacheDriverInterface|null $cache Optional cache used to read the
     *   KeyExpiryRecovery auth-failure marker. When provided, `ai_usable`
     *   reflects whether the stored credentials actually authenticate (a
     *   cached marker recorded at call time — never a live API call per
     *   health request). When null, `ai_usable` mirrors `ai_configured`,
     *   preserving the previous behavior for adapters that have not wired
     *   recovery yet.
     */
    public function __construct(
        private readonly ScoltaConfig $config,
        private readonly string $indexOutputDir,
        private readonly ?string $pagefindBinaryPath,
        private readonly ?string $projectDir,
        private readonly ?CacheDriverInterface $cache = null,
    ) {}

    /**
     * Run all health checks and return a structured result.
     *
     * `ai_configured` states that credentials are present; `ai_usable` states
     * that they are also not known to be expired/auth-failing. The two
     * diverged silently before: an expired Amazee trial key kept
     * `ai_configured: true` for ~24h while every AI call failed (django demo
     * outage, 2026-06-09).
     *
     * @return array{status: string, ai_configured: bool, ai_usable: bool, ai_auth_failing: bool, ai_provider: string, pagefind_available: bool, wasm_available: bool, index_exists: bool, pagefind: array, wasm: array}
     * @since 1.0.0
     * @stability stable
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

        // "Configured" must not imply "usable": stored credentials can be
        // expired/revoked server-side. KeyExpiryRecovery records auth failures
        // in the cache at call time; reading that marker here keeps health
        // truthful without adding a live API call per health request.
        $aiAuthFailing = $this->cache !== null
            && (bool) $this->cache->get(KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE);
        $aiUsable = $aiConfigured && !$aiAuthFailing;

        $status = 'ok';
        if (!$indexExists || !$aiUsable) {
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
            'ai_usable' => $aiUsable,
            'ai_auth_failing' => $aiAuthFailing,
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
