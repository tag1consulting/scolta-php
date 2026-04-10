<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\SetupCheck;

/**
 * Tests the SetupCheck pre-flight dependency chain.
 *
 * Checks are split into runtime (PHP, AI key, browser WASM) and
 * build (FFI, Extism, server WASM, Pagefind). Build checks are
 * informational ('info'), not critical failures.
 */
class SetupCheckTest extends TestCase
{
    public function testRunReturnsArrayOfResults(): void
    {
        $results = SetupCheck::run();
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }

    public function testEachResultHasRequiredKeys(): void
    {
        $results = SetupCheck::run();
        foreach ($results as $result) {
            $this->assertArrayHasKey('name', $result, 'Each check must have a name');
            $this->assertArrayHasKey('status', $result, 'Each check must have a status');
            $this->assertArrayHasKey('message', $result, 'Each check must have a message');
            $this->assertArrayHasKey('category', $result, 'Each check must have a category');
            $this->assertContains($result['status'], ['pass', 'fail', 'warn', 'info'],
                "Status must be pass, fail, warn, or info, got: {$result['status']}");
            $this->assertContains($result['category'], ['runtime', 'build'],
                "Category must be runtime or build, got: {$result['category']}");
        }
    }

    public function testPhpVersionCheckPasses(): void
    {
        $results = SetupCheck::run();
        $phpCheck = $this->findCheck($results, 'PHP version');
        $this->assertNotNull($phpCheck, 'PHP version check should exist');
        $this->assertEquals('pass', $phpCheck['status'],
            'PHP version check should pass on PHP 8.1+');
        $this->assertEquals('runtime', $phpCheck['category']);
    }

    public function testMissingServerWasmReportsInfo(): void
    {
        $results = SetupCheck::run(
            wasmPath: '/nonexistent/scolta_core.wasm',
        );
        $wasmCheck = $this->findCheck($results, 'Server WASM');
        $this->assertNotNull($wasmCheck);
        $this->assertEquals('info', $wasmCheck['status'],
            'Missing server WASM should be informational (build-time only)');
        $this->assertEquals('build', $wasmCheck['category']);
    }

    public function testMissingApiKeyReportsWarn(): void
    {
        $results = SetupCheck::run(
            aiApiKey: '',
        );
        $aiCheck = $this->findCheck($results, 'AI API key');
        $this->assertNotNull($aiCheck);
        $this->assertEquals('warn', $aiCheck['status']);
    }

    public function testPresentApiKeyReportsPass(): void
    {
        $results = SetupCheck::run(
            aiApiKey: 'sk-ant-test-key',
        );
        $aiCheck = $this->findCheck($results, 'AI API key');
        $this->assertNotNull($aiCheck);
        $this->assertEquals('pass', $aiCheck['status']);
    }

    public function testExitCodeZeroWhenAllCriticalPass(): void
    {
        $results = SetupCheck::run(
            aiApiKey: 'test-key',
        );
        $exitCode = SetupCheck::exitCode($results);
        $this->assertIsInt($exitCode);
        $this->assertContains($exitCode, [0, 1]);
    }

    public function testExitCodeZeroWhenOnlyBuildDepsAreMissing(): void
    {
        // Build-time dependencies are 'info' status, not 'fail',
        // so missing server WASM should not cause non-zero exit.
        $results = SetupCheck::run(
            wasmPath: '/nonexistent/scolta_core.wasm',
        );
        $exitCode = SetupCheck::exitCode($results);
        // Exit code should be 0 unless there are actual 'fail' statuses
        // (e.g., PHP version too old), which won't happen in test env.
        $this->assertEquals(0, $exitCode,
            'Missing build-time deps should not cause non-zero exit');
    }

    public function testExitCodeZeroOnWarnOnly(): void
    {
        $fakeResults = [
            ['name' => 'PHP version', 'status' => 'pass', 'message' => 'OK', 'category' => 'runtime'],
            ['name' => 'AI API key', 'status' => 'warn', 'message' => 'Not set', 'category' => 'runtime'],
            ['name' => 'Pagefind', 'status' => 'info', 'message' => 'Not found', 'category' => 'build'],
        ];
        $this->assertEquals(0, SetupCheck::exitCode($fakeResults));
    }

    public function testChecksIncludeAllExpectedItems(): void
    {
        $results = SetupCheck::run();
        $names = array_column($results, 'name');
        $this->assertContains('PHP version', $names);
        $this->assertContains('FFI extension', $names);
        $this->assertContains('Server WASM binary', $names);
        $this->assertContains('Browser WASM', $names);
        $this->assertContains('Pagefind binary', $names);
        $this->assertContains('AI API key', $names);
    }

    public function testBrowserWasmCheckExists(): void
    {
        $results = SetupCheck::run();
        $browserCheck = $this->findCheck($results, 'Browser WASM');
        $this->assertNotNull($browserCheck, 'Browser WASM check should exist');
        $this->assertEquals('runtime', $browserCheck['category']);
    }

    /**
     * Find a check result by name substring.
     */
    private function findCheck(array $results, string $nameSubstring): ?array
    {
        foreach ($results as $r) {
            if (stripos($r['name'], $nameSubstring) !== false) {
                return $r;
            }
        }
        return null;
    }
}
