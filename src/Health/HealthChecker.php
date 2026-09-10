<?php

declare(strict_types=1);

namespace Tag1\Scolta\Health;

use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Config\ResolvedApiKey;
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
     * @param ResolvedApiKey|null $resolvedKey The resolution the client
     *   actually performs, from {@see \Tag1\Scolta\Config\ApiKeyResolver}.
     *   When provided, the payload reports the key's source, so /health and
     *   the settings UI cannot disagree about it. When null, the payload
     *   falls back to the key already on the config and omits the source.
     */
    public function __construct(
        private readonly ScoltaConfig $config,
        private readonly string $indexOutputDir,
        private readonly ?string $pagefindBinaryPath,
        private readonly ?string $projectDir,
        private readonly ?CacheDriverInterface $cache = null,
        private readonly ?ResolvedApiKey $resolvedKey = null,
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
     * `ai_key_source` is the backing value of the resolved
     * {@see \Tag1\Scolta\Config\ApiKeySource}, or NULL when the adapter passed
     * no resolution. `ai_amazee_overridden` reports credentials that exist but
     * lost to an explicit key, which is otherwise invisible to an operator.
     *
     * `ai_auth_failing` is a cached marker, not a live probe, so it is reported
     * with its age: `ai_auth_failing_since` is the Unix timestamp of the failed
     * call that recorded it and `ai_auth_failing_ttl` the seconds it survives
     * without a further failing call. Both are NULL when no failure is
     * recorded, and `ai_auth_failing_since` is also NULL for a marker written
     * before the timestamp was read back. A successful AI call clears the
     * marker, so an operator who sees one with an old `since` and a working
     * site is looking at a site that has not made an AI call since the fix.
     *
     * `status` is `ok` or `degraded`; `status_reasons` names every fault behind
     * a `degraded`, for the operator who can see past the trimmed anonymous
     * payload. Selecting no provider at all is a supported configuration, not
     * a fault, so `ai_usable: false` alone does not degrade the status. See
     * docs/HEALTH_REFERENCE.md.
     *
     * @return array{status: 'ok'|'degraded', status_reasons: list<string>, ai_provider: string, ai_provider_selected: bool, ai_configured: bool, ai_usable: bool, ai_auth_failing: bool, ai_auth_failing_since: int|null, ai_auth_failing_ttl: int|null, ai_key_source: string|null, ai_amazee_overridden: bool, pagefind_available: bool, wasm_available: bool, index_exists: bool, indexer_active: string, indexer_upgrade_available: bool, indexer_upgrade_message: string|null, stale_artifact_urls: bool, stale_artifact_message: string|null, pagefind: array, wasm: array}
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

        // An Amazee resolution counts as configured even when it withholds the
        // key: the credentials are what the site is running on, and a
        // half-provisioned install is degraded rather than unconfigured.
        $aiConfigured = $this->resolvedKey !== null
            ? ($this->resolvedKey->isConfigured() || $this->resolvedKey->isAmazee())
            : trim($this->config->aiApiKey) !== '';

        // "Configured" must not imply "usable": stored credentials can be
        // expired/revoked server-side. KeyExpiryRecovery records auth failures
        // in the cache at call time; reading that marker here keeps health
        // truthful without adding a live API call per health request.
        //
        // The marker's age travels with it. A boolean alone cannot distinguish
        // a failure from a second ago from one whose cause was fixed and whose
        // marker has not aged out, and this payload is the first place an
        // operator looks when AI is reported down.
        $aiAuthFailing = $this->cache !== null
            && (bool) $this->cache->get(KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE);
        $aiAuthFailingSince = $aiAuthFailing
            ? KeyExpiryRecovery::readFailureTimestamp($this->cache)
            : null;
        // No provider selected means AI is off, whatever else is present. A key
        // can exist without a provider — an environment variable set before
        // anybody chose one — and reporting that as usable would restore by the
        // back door the assumption that an unselected provider is Anthropic.
        $providerSelected = $this->resolvedKey !== null
            ? $this->resolvedKey->providerSelected()
            : trim($this->config->aiProvider) !== '';

        $awaitingAmazeeModel = $this->resolvedKey !== null
            && $this->resolvedKey->awaitingAmazeeModelResolution;

        $aiUsable = $aiConfigured
            && $providerSelected
            && !$aiAuthFailing
            && !$awaitingAmazeeModel;

        // The fault is not "AI is off" but "AI was asked for and does not
        // work". An install with no provider, no key and nothing in flight
        // asked for nothing, and search works without one.
        $aiIntended = $providerSelected || $aiConfigured || $awaitingAmazeeModel;

        $reasons = [];

        if (!$indexExists) {
            $reasons[] = 'index_missing';
        }

        // At most one ai_* reason, the next thing to fix: a key cannot be
        // auth-failing before a provider exists to reject it.
        if ($aiIntended) {
            if (!$providerSelected) {
                $reasons[] = 'ai_provider_unselected';
            } elseif (!$aiConfigured) {
                $reasons[] = 'ai_key_missing';
            } elseif ($aiAuthFailing) {
                $reasons[] = 'ai_auth_failing';
            } elseif ($awaitingAmazeeModel) {
                $reasons[] = 'ai_awaiting_amazee_model_resolution';
            }
        }

        $configuredIndexer = $this->config->indexer ?? 'auto';
        $indexerActive = ($configuredIndexer === 'binary' && $binaryStatus['available']) ? 'binary' : 'php';
        $upgradeMessage = ($configuredIndexer === 'binary' && !$binaryStatus['available'])
            ? 'Pagefind binary not found. Set indexer to "php" or install Pagefind: npm install -g pagefind'
            : null;

        $staleIndex = $this->detectStaleArtifactUrls();

        if ($staleIndex) {
            $reasons[] = 'index_stale_artifact_urls';
        }

        return [
            'status' => $reasons === [] ? 'ok' : 'degraded',
            // Why the status is what it is, for the operator who sees detail.
            'status_reasons' => $reasons,
            // '' means no provider has been selected, which is what a fresh
            // install reports. It is never coalesced to 'anthropic': claiming a
            // provider nobody chose is the failure this field exists to expose.
            'ai_provider' => $this->resolvedKey?->provider ?: $this->config->aiProvider,
            'ai_provider_selected' => $providerSelected,
            'ai_configured' => $aiConfigured,
            'ai_usable' => $aiUsable,
            'ai_auth_failing' => $aiAuthFailing,
            'ai_auth_failing_since' => $aiAuthFailingSince,
            'ai_auth_failing_ttl' => $aiAuthFailing ? KeyExpiryRecovery::AUTH_FAILURE_TTL : null,
            'ai_key_source' => $this->resolvedKey?->source->value,
            'ai_amazee_overridden' => $this->resolvedKey?->amazeeOverridden() ?? false,
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
