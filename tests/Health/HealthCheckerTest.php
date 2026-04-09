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
    // Helpers
    // -------------------------------------------------------------------

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
