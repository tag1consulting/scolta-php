<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\ExtismCheck;

/**
 * Tests the ExtismCheck pre-flight dependency verification.
 *
 * These tests are environment-dependent — one of the two paths
 * (FFI present or absent) will always run depending on the host.
 */
class ExtismCheckTest extends TestCase
{
    public function testStatusReturnsArrayWithAvailableKey(): void
    {
        $result = ExtismCheck::status();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('available', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['available']);
        $this->assertIsString($result['message']);
    }

    public function testVerifyBehaviorMatchesEnvironment(): void
    {
        if (!extension_loaded('ffi')) {
            // FFI not available — verify() should throw.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/FFI|ffi/i');
            ExtismCheck::verify();
        } else {
            // FFI available — verify() may succeed or throw based on
            // ffi.enable setting and Extism SDK presence.
            $ffiEnabled = ini_get('ffi.enable');
            if (in_array($ffiEnabled, ['1', 'true', 'preload'], true)) {
                if (class_exists(\Extism\Plugin::class)) {
                    // Full stack available — should not throw.
                    ExtismCheck::verify();
                    $this->assertTrue(true);
                } else {
                    // FFI enabled but SDK missing.
                    $this->expectException(\RuntimeException::class);
                    $this->expectExceptionMessageMatches('/Extism PHP SDK/i');
                    ExtismCheck::verify();
                }
            } else {
                // FFI loaded but disabled.
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessageMatches('/disabled/i');
                ExtismCheck::verify();
            }
        }
    }

    public function testStatusAvailableMatchesVerify(): void
    {
        $status = ExtismCheck::status();
        try {
            ExtismCheck::verify();
            $this->assertTrue($status['available'],
                'status() should report available when verify() succeeds');
        } catch (\RuntimeException $e) {
            $this->assertFalse($status['available'],
                'status() should report unavailable when verify() throws');
            $this->assertNotEmpty($status['message']);
        }
    }

    public function testStatusMessageIsNonEmpty(): void
    {
        $result = ExtismCheck::status();
        $this->assertNotEmpty($result['message']);
    }
}
