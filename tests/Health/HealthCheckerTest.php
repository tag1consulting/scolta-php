<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Health;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;

/**
 * Tests for the shared HealthChecker class.
 */
class HealthCheckerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scolta_health_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------
    // Structure
    // -------------------------------------------------------------------

    public function testCheckReturnsExpectedStructure(): void
    {
        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-test']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('ai_configured', $result);
        $this->assertArrayHasKey('ai_provider', $result);
        $this->assertArrayHasKey('pagefind_available', $result);
        $this->assertArrayHasKey('wasm_available', $result);
        $this->assertArrayHasKey('index_exists', $result);
        $this->assertArrayHasKey('pagefind', $result);
        $this->assertArrayHasKey('wasm', $result);
    }

    // -------------------------------------------------------------------
    // Healthy system with index
    // -------------------------------------------------------------------

    public function testHealthySystemWithIndex(): void
    {
        // Create pagefind.js to simulate an existing index.
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-test-key']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertEquals('ok', $result['status']);
        $this->assertTrue($result['ai_configured']);
        $this->assertTrue($result['index_exists']);
    }

    // -------------------------------------------------------------------
    // Degraded without index
    // -------------------------------------------------------------------

    public function testDegradedWithoutIndex(): void
    {
        // Empty dir, no pagefind.js.
        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-test-key']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertFalse($result['index_exists']);
        $this->assertEquals('degraded', $result['status']);
    }

    // -------------------------------------------------------------------
    // Degraded without AI key
    // -------------------------------------------------------------------

    public function testDegradedWithoutAiKey(): void
    {
        // With index but no AI key.
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        $config = ScoltaConfig::fromArray(['ai_api_key' => '']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertFalse($result['ai_configured']);
        $this->assertEquals('degraded', $result['status']);
    }

    // -------------------------------------------------------------------
    // Whitespace-only key is not configured
    // -------------------------------------------------------------------

    public function testWhitespaceOnlyKeyIsNotConfigured(): void
    {
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        foreach (['   ', "\t", " \n ", "\t\n\r "] as $key) {
            $config = ScoltaConfig::fromArray(['ai_api_key' => $key]);
            $checker = new HealthChecker($config, $this->tempDir, null, null);
            $result = $checker->check();

            $this->assertFalse($result['ai_configured'], 'Expected ai_configured false for key: ' . json_encode($key));
            $this->assertEquals('degraded', $result['status'], 'Expected degraded status for whitespace-only key');
        }
    }

    // -------------------------------------------------------------------
    // Both missing — still degraded
    // -------------------------------------------------------------------

    public function testDegradedWhenBothMissing(): void
    {
        // No index AND no AI key.
        $config = ScoltaConfig::fromArray(['ai_api_key' => '']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertFalse($result['index_exists']);
        $this->assertFalse($result['ai_configured']);
        $this->assertEquals('degraded', $result['status']);
    }

    // -------------------------------------------------------------------
    // Index check verifies pagefind.js specifically
    // -------------------------------------------------------------------

    public function testIndexCheckVerifiesPagefindJs(): void
    {
        // Dir exists but no pagefind.js — should fail.
        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-test']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();
        $this->assertFalse($result['index_exists']);

        // Now add pagefind.js — should pass.
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');
        $checker2 = new HealthChecker($config, $this->tempDir, null, null);

        $result2 = $checker2->check();
        $this->assertTrue($result2['index_exists']);
    }

    // -------------------------------------------------------------------
    // indexer_active reflects config, not binary availability
    // -------------------------------------------------------------------

    public function testIndexerActiveIsPhpWhenConfigIsAuto(): void
    {
        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-test', 'indexer' => 'auto']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertSame('php', $result['indexer_active']);
    }

    public function testIndexerActiveIsPhpWhenConfigIsPhp(): void
    {
        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-test', 'indexer' => 'php']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertSame('php', $result['indexer_active']);
    }

    public function testIndexerActiveIsBinaryWhenConfigIsBinaryAndBinaryAvailable(): void
    {
        // Create a fake binary so PagefindBinary reports available.
        $tempBin = $this->tempDir . '/pagefind';
        file_put_contents($tempBin, "#!/bin/sh\necho 'pagefind 1.5.0'");
        chmod($tempBin, 0755);

        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-test', 'indexer' => 'binary']);
        $checker = new HealthChecker($config, $this->tempDir, $tempBin, null);

        $result = $checker->check();

        $this->assertSame('binary', $result['indexer_active']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------
    // AI usability — "configured" must not imply "usable"
    //
    // Regression (django demo, 2026-06-09): an expired Amazee trial key kept
    // health reporting ai_configured: true for ~24h while every AI call
    // returned 400 expired_key.
    // -------------------------------------------------------------------

    public function testStoredButAuthFailingCredentialsReportAiNotUsable(): void
    {
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        $cache = new HealthTestCache();
        $cache->set(\Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE, time(), 3600);

        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-stored-but-expired']);
        $checker = new HealthChecker($config, $this->tempDir, null, null, $cache);

        $result = $checker->check();

        $this->assertTrue($result['ai_configured'], 'Credentials ARE present — configured stays true');
        $this->assertTrue($result['ai_auth_failing']);
        $this->assertFalse($result['ai_usable'], 'Known-expired credentials must not report AI as usable');
        $this->assertEquals('degraded', $result['status']);
    }

    public function testConfiguredAndNotAuthFailingReportsUsable(): void
    {
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-good']);
        $checker = new HealthChecker($config, $this->tempDir, null, null, new HealthTestCache());

        $result = $checker->check();

        $this->assertTrue($result['ai_usable']);
        $this->assertFalse($result['ai_auth_failing']);
        $this->assertEquals('ok', $result['status']);
    }

    public function testWithoutCacheAiUsableMirrorsConfigured(): void
    {
        // Adapters that have not wired recovery yet pass no cache; behavior
        // is unchanged from before the ai_usable field existed.
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-good']);
        $checker = new HealthChecker($config, $this->tempDir, null, null);

        $result = $checker->check();

        $this->assertTrue($result['ai_usable']);
        $this->assertFalse($result['ai_auth_failing']);
        $this->assertEquals('ok', $result['status']);
    }

    public function testClearedAuthFailureMarkerRestoresUsable(): void
    {
        file_put_contents($this->tempDir . '/pagefind.js', '// pagefind');

        $cache = new HealthTestCache();
        // KeyExpiryRecovery clears the marker by overwriting it with false.
        $cache->set(\Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE, false, 1);

        $config = ScoltaConfig::fromArray(['ai_api_key' => 'sk-recovered']);
        $checker = new HealthChecker($config, $this->tempDir, null, null, $cache);

        $result = $checker->check();

        $this->assertTrue($result['ai_usable']);
        $this->assertEquals('ok', $result['status']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}

/**
 * Minimal in-memory cache for health tests.
 */
class HealthTestCache implements \Tag1\Scolta\Cache\CacheDriverInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store[$key] = $value;
    }
}
