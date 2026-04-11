<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildState;

class BuildStateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-buildstate-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testInitiateBuildAcquiresLock(): void
    {
        $state = new BuildState($this->tmpDir);
        $this->assertTrue($state->initiateBuild(['total_pages' => 100]));
        $this->assertFileExists($this->tmpDir . '/lock');
        $this->assertFileExists($this->tmpDir . '/manifest.json');
    }

    public function testInitiateBuildFailsIfLocked(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 100]);

        $state2 = new BuildState($this->tmpDir);
        $this->assertFalse($state2->initiateBuild(['total_pages' => 50]));
    }

    public function testRecordAndReadChunk(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 10]);

        $data = ['index' => ['word' => [1 => ['positions' => [25 => [5]]]]], 'pages' => [1 => ['url' => '/a']]];
        $state->recordChunk(0, $data);

        $read = $state->readChunk(0);
        $this->assertSame($data, $read);
    }

    public function testHmacVerification(): void
    {
        $secret = 'test-secret-key';
        $state = new BuildState($this->tmpDir, $secret);
        $state->initiateBuild(['total_pages' => 10]);

        $data = ['test' => 'data'];
        $state->recordChunk(0, $data);

        // Read with correct secret.
        $read = $state->readChunk(0);
        $this->assertSame($data, $read);
    }

    public function testHmacVerificationFailsWithWrongSecret(): void
    {
        $state1 = new BuildState($this->tmpDir, 'correct-secret');
        $state1->initiateBuild(['total_pages' => 10]);
        $state1->recordChunk(0, ['test' => 'data']);

        $state2 = new BuildState($this->tmpDir, 'wrong-secret');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');
        $state2->readChunk(0);
    }

    public function testReleaseLock(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 10]);
        $state->releaseLock();

        $this->assertFileDoesNotExist($this->tmpDir . '/lock');
    }

    public function testShouldResumeReturnsManifest(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 100]);
        $state->recordChunk(0, ['index' => [], 'pages' => []]);

        // Remove lock to simulate stale/cleared lock.
        unlink($this->tmpDir . '/lock');

        $manifest = $state->shouldResume();
        $this->assertNotNull($manifest);
        $this->assertSame('building', $manifest['status']);
        $this->assertSame(1, $manifest['chunks_written']);
    }

    public function testShouldResumeReturnsNullForFreshState(): void
    {
        $state = new BuildState($this->tmpDir);
        $this->assertNull($state->shouldResume());
    }

    public function testGetChunkFiles(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 30]);
        $state->recordChunk(0, ['index' => [], 'pages' => [1 => []]]);
        $state->recordChunk(1, ['index' => [], 'pages' => [2 => []]]);

        $files = $state->getChunkFiles();
        $this->assertCount(2, $files);
    }

    public function testCleanup(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 10]);
        $state->recordChunk(0, ['test' => true]);
        $state->cleanup();

        $remaining = glob($this->tmpDir . '/*');
        $this->assertEmpty($remaining);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
