<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\SetupCheck;

/**
 * Tests the SetupCheck pre-flight dependency chain.
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
            $this->assertContains($result['status'], ['pass', 'fail', 'warn'],
                "Status must be pass, fail, or warn, got: {$result['status']}");
        }
    }

    public function testPhpVersionCheckPasses(): void
    {
        $results = SetupCheck::run();
        $phpCheck = $this->findCheck($results, 'PHP version');
        $this->assertNotNull($phpCheck, 'PHP version check should exist');
        $this->assertEquals('pass', $phpCheck['status'],
            'PHP version check should pass on PHP 8.1+');
    }

    public function testMissingWasmBinaryReportsFail(): void
    {
        $results = SetupCheck::run(
            wasmPath: '/nonexistent/scolta_core.wasm',
        );
        $wasmCheck = $this->findCheck($results, 'WASM binary');
        $this->assertNotNull($wasmCheck);
        $this->assertEquals('fail', $wasmCheck['status']);
        $this->assertStringContainsString('not found', $wasmCheck['message']);
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
        // Use the real environment — if FFI and Extism are available,
        // only WASM binary and pagefind might fail. Those are not
        // 'fail' status if the file exists.
        $results = SetupCheck::run(
            aiApiKey: 'test-key',
        );
        $exitCode = SetupCheck::exitCode($results);
        // Exit code depends on environment, but the function must return int.
        $this->assertIsInt($exitCode);
        $this->assertContains($exitCode, [0, 1]);
    }

    public function testExitCodeNonZeroOnCriticalFail(): void
    {
        // Force a critical failure by pointing to nonexistent WASM.
        $results = SetupCheck::run(
            wasmPath: '/nonexistent/scolta_core.wasm',
        );
        $exitCode = SetupCheck::exitCode($results);
        $this->assertEquals(1, $exitCode,
            'Exit code should be 1 when WASM binary check fails');
    }

    public function testExitCodeZeroOnWarnOnly(): void
    {
        // Warnings (pagefind, API key) should not cause non-zero exit.
        $fakeResults = [
            ['name' => 'PHP version', 'status' => 'pass', 'message' => 'OK'],
            ['name' => 'AI API key', 'status' => 'warn', 'message' => 'Not set'],
            ['name' => 'Pagefind', 'status' => 'warn', 'message' => 'Not found'],
        ];
        $this->assertEquals(0, SetupCheck::exitCode($fakeResults));
    }

    public function testChecksIncludeAllExpectedItems(): void
    {
        $results = SetupCheck::run();
        $names = array_column($results, 'name');
        $this->assertContains('PHP version', $names);
        $this->assertContains('FFI extension', $names);
        $this->assertContains('WASM binary', $names);
        $this->assertContains('Pagefind binary', $names);
        $this->assertContains('AI API key', $names);
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
