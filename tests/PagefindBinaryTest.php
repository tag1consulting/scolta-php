<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Tests the PagefindBinary resolver.
 *
 * Uses a temp directory with a fake pagefind script to test the
 * resolution chain without requiring the real Pagefind binary.
 */
class PagefindBinaryTest extends TestCase
{
    private string $tempDir;
    private string $fakeBinary;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scolta_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Create a fake pagefind binary that responds to --version.
        $binDir = $this->tempDir . '/.scolta/bin';
        mkdir($binDir, 0755, true);
        $this->fakeBinary = $binDir . '/pagefind';
        file_put_contents($this->fakeBinary, "#!/bin/sh\necho 'pagefind 1.5.0'\n");
        chmod($this->fakeBinary, 0755);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory.
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------
    // Resolution chain
    // -------------------------------------------------------------------

    public function testResolvesConfiguredPath(): void
    {
        $resolver = new PagefindBinary(
            configuredPath: $this->fakeBinary,
            projectDir: $this->tempDir,
        );

        $this->assertEquals($this->fakeBinary, $resolver->resolve());
        $this->assertEquals('configured', $resolver->resolvedVia());
    }

    public function testResolvesProjectLocalBinary(): void
    {
        // No configured path — should find .scolta/bin/pagefind.
        $resolver = new PagefindBinary(
            configuredPath: null,
            projectDir: $this->tempDir,
        );

        $this->assertEquals($this->fakeBinary, $resolver->resolve());
        $this->assertEquals('local', $resolver->resolvedVia());
    }

    public function testConfiguredPathTakesPrecedenceOverLocal(): void
    {
        // Create a separate configured binary.
        $customBin = $this->tempDir . '/custom-pagefind';
        file_put_contents($customBin, "#!/bin/sh\necho 'pagefind 2.0.0'\n");
        chmod($customBin, 0755);

        $resolver = new PagefindBinary(
            configuredPath: $customBin,
            projectDir: $this->tempDir,
        );

        $this->assertEquals($customBin, $resolver->resolve());
        $this->assertEquals('configured', $resolver->resolvedVia());
    }

    public function testReturnsNullWhenNothingFound(): void
    {
        // Empty project dir, no configured path, and we can't control
        // system PATH in a test, so use a path that doesn't exist.
        $emptyDir = $this->tempDir . '/empty';
        mkdir($emptyDir, 0755, true);

        $resolver = new PagefindBinary(
            configuredPath: '/nonexistent/pagefind',
            projectDir: $emptyDir,
        );

        // This test may find npx or system pagefind if they're installed.
        // We can only reliably test the configured + local paths.
        // The full chain test is done in integration tests.
        $result = $resolver->resolve();
        if ($result === null) {
            $this->assertEquals('none', $resolver->resolvedVia());
        } else {
            // npx or system pagefind found — still valid behavior.
            $this->assertContains($resolver->resolvedVia(), ['npx', 'path']);
        }
    }

    public function testSkipsBarePagefindAsConfiguredPath(): void
    {
        // 'pagefind' (the default) should be skipped as a configured path
        // and fall through to the local/npx/path chain instead.
        $resolver = new PagefindBinary(
            configuredPath: 'pagefind',
            projectDir: $this->tempDir,
        );

        $resolved = $resolver->resolve();
        $this->assertNotNull($resolved);
        // Should resolve via local, not configured.
        $this->assertNotEquals('configured', $resolver->resolvedVia());
    }

    public function testSkipsEmptyConfiguredPath(): void
    {
        $resolver = new PagefindBinary(
            configuredPath: '',
            projectDir: $this->tempDir,
        );

        $this->assertEquals($this->fakeBinary, $resolver->resolve());
        $this->assertEquals('local', $resolver->resolvedVia());
    }

    // -------------------------------------------------------------------
    // Version
    // -------------------------------------------------------------------

    public function testVersionReturnsString(): void
    {
        $resolver = new PagefindBinary(
            configuredPath: $this->fakeBinary,
        );

        $version = $resolver->version();
        $this->assertNotNull($version);
        $this->assertStringContainsString('pagefind', $version);
    }

    public function testVersionReturnsNullWhenNoResolver(): void
    {
        $emptyDir = $this->tempDir . '/empty2';
        mkdir($emptyDir, 0755, true);

        $resolver = new PagefindBinary(
            configuredPath: '/nonexistent/pagefind',
            projectDir: $emptyDir,
        );

        $result = $resolver->resolve();
        if ($result === null) {
            $this->assertNull($resolver->version());
        } else {
            // npx or system pagefind found — version should be a string.
            $this->assertNotNull($resolver->version());
        }
    }

    // -------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------

    public function testStatusWhenAvailable(): void
    {
        $resolver = new PagefindBinary(
            configuredPath: $this->fakeBinary,
        );

        $status = $resolver->status();
        $this->assertTrue($status['available']);
        $this->assertEquals($this->fakeBinary, $status['binary']);
        $this->assertNotNull($status['version']);
        $this->assertEquals('configured', $status['via']);
        $this->assertStringContainsString('Pagefind', $status['message']);
    }

    public function testStatusWhenUnavailable(): void
    {
        $emptyDir = $this->tempDir . '/empty3';
        mkdir($emptyDir, 0755, true);

        $resolver = new PagefindBinary(
            configuredPath: '/nonexistent/pagefind',
            projectDir: $emptyDir,
        );

        $status = $resolver->status();
        if (!$status['available']) {
            $this->assertNull($status['binary']);
            $this->assertNull($status['version']);
            $this->assertEquals('none', $status['via']);
            $this->assertStringContainsString('not found', $status['message']);
        } else {
            // npx or system pagefind found — via should be npx or path.
            $this->assertContains($status['via'], ['npx', 'path']);
            $this->assertTrue($status['available']);
        }
    }

    public function testStatusMessageIncludesTriedPaths(): void
    {
        $emptyDir = $this->tempDir . '/empty4';
        mkdir($emptyDir, 0755, true);

        $resolver = new PagefindBinary(
            configuredPath: '/custom/path/pagefind',
            projectDir: $emptyDir,
        );

        $status = $resolver->status();
        if (!$status['available']) {
            $this->assertStringContainsString('/custom/path/pagefind', $status['message']);
            $this->assertStringContainsString('.scolta/bin/pagefind', $status['message']);
            $this->assertStringContainsString('npx', $status['message']);
            $this->assertStringContainsString('PATH', $status['message']);
        } else {
            // npx or system pagefind found — message should mention the resolved method.
            $this->assertStringContainsString('resolved via', $status['message']);
        }
    }

    // -------------------------------------------------------------------
    // Download target
    // -------------------------------------------------------------------

    public function testDownloadTargetDirCreatesDirectory(): void
    {
        $newDir = $this->tempDir . '/newproject';
        mkdir($newDir, 0755, true);

        $resolver = new PagefindBinary(projectDir: $newDir);
        $target = $resolver->downloadTargetDir();

        $this->assertEquals($newDir . '/.scolta/bin', $target);
        $this->assertDirectoryExists($target);
    }

    public function testDownloadTargetDirFallsBackToTemp(): void
    {
        $resolver = new PagefindBinary();
        $target = $resolver->downloadTargetDir();

        $this->assertEquals(sys_get_temp_dir(), $target);
    }

    // -------------------------------------------------------------------
    // Caching
    // -------------------------------------------------------------------

    public function testResolveCachesResult(): void
    {
        $resolver = new PagefindBinary(
            configuredPath: $this->fakeBinary,
        );

        $first = $resolver->resolve();
        $second = $resolver->resolve();
        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------
    // isExecutable() visibility and internal usage (Part 3A)
    // -------------------------------------------------------------------

    /**
     * Verify that resolve() internally uses isExecutable() without exposing it.
     *
     * If isExecutable() were removed or broken, resolve() would return null
     * even with a valid binary.
     */
    public function testResolveCallsIsExecutableInternally(): void
    {
        $resolver = new PagefindBinary(
            configuredPath: $this->fakeBinary,
            projectDir: $this->tempDir,
        );

        $path = $resolver->resolve();
        $this->assertNotNull($path, 'resolve() must find the fake binary via internal isExecutable() call');
        $this->assertSame($this->fakeBinary, $path);

        $status = $resolver->status();
        $this->assertTrue($status['available']);
        $this->assertSame('configured', $status['via']);
    }

    /**
     * Verify that isExecutable() is not callable from outside the class.
     *
     * If visibility changes from private to public/protected, this fails.
     */
    public function testIsExecutableIsPrivate(): void
    {
        $reflection = new \ReflectionMethod(PagefindBinary::class, 'isExecutable');
        $this->assertTrue(
            $reflection->isPrivate(),
            'isExecutable() must remain private — callers use resolve() + status()'
        );
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
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
