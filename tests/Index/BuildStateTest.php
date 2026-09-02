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
        $this->assertFileExists($state->manifestFile());
        $this->assertNotSame(
            $this->tmpDir,
            $state->buildDirectory(),
            'Each build writes into its own generation directory, so two builds cannot share chunk filenames.',
        );
    }

    public function testInitiateBuildFailsIfLocked(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 100]);

        $state2 = new BuildState($this->tmpDir);
        $this->assertFalse($state2->initiateBuild(['total_pages' => 50]));
    }

    public function testOnlyOneLockAcquiredConcurrently(): void
    {
        // Simulate two handles racing: both call initiateBuild() before either
        // releases. flock(LOCK_EX | LOCK_NB) guarantees only one succeeds.
        // NOTE: objects must be kept alive (referenced) or PHP closes the handle.
        $states = [];
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $s = new BuildState($this->tmpDir);
            $states[] = $s; // Keep reference so lockHandle is not closed.
            $results[] = $s->initiateBuild(['total_pages' => 10]);
        }

        $acquired = array_filter($results);
        $this->assertCount(1, $acquired, 'Exactly one concurrent initiateBuild() should succeed');
    }

    public function testRecordAndReadChunk(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 10]);

        $data = [
            'pages' => [1 => ['url' => '/a', 'wordCount' => 5, 'content' => '', 'meta' => [], 'filters' => []]],
            'index' => ['word' => [1 => ['positions' => [25 => [5]], 'meta_positions' => []]]],
        ];
        $state->recordChunk(0, $data);

        $read = $state->readChunk(0);
        $this->assertEquals($data['pages'], $read['pages']);
        $this->assertEquals($data['index'], $read['index']);
    }

    public function testHmacVerification(): void
    {
        $secret = 'test-secret-key';
        $state  = new BuildState($this->tmpDir, $secret);
        $state->initiateBuild(['total_pages' => 10]);

        $data = [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 3, 'content' => '', 'meta' => [], 'filters' => []]],
            'index' => ['hello' => [0 => ['positions' => [25 => [1]], 'meta_positions' => []]]],
        ];
        $state->recordChunk(0, $data);

        // Read with correct secret succeeds.
        $read = $state->readChunk(0);
        $this->assertEquals($data['pages'], $read['pages']);
        $this->assertEquals($data['index'], $read['index']);
    }

    public function testHmacVerificationFailsWithWrongSecret(): void
    {
        $state1 = new BuildState($this->tmpDir, 'correct-secret');
        $state1->initiateBuild(['total_pages' => 10]);
        $state1->recordChunk(0, ['pages' => [], 'index' => ['word' => []]]);

        $state2 = new BuildState($this->tmpDir, 'wrong-secret');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');
        $state2->readChunk(0);
    }

    /**
     * A release marks the lock record released; it does not unlink the file.
     *
     * Unlinking is what broke mutual exclusion: the next process created a new
     * inode at the same path and flock()ed that, while the previous holder
     * still had its flock on the unlinked one.
     */
    public function testReleaseLockMarksTheRecordReleasedWithoutUnlinkingTheFile(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 10]);
        $state->releaseLock();

        $this->assertFileExists($this->tmpDir . '/lock');
        $this->assertFalse($state->isRunning());
        $this->assertSame('released', $state->lockDiagnostics()['state']);

        // And the lock is free for the next build.
        $next = new BuildState($this->tmpDir);
        $this->assertTrue($next->initiateBuild(['total_pages' => 5]));
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
        $buildDir = $state->buildDirectory();
        $state->recordChunk(0, ['test' => true]);
        $state->cleanup();

        $this->assertEmpty(glob($buildDir . '/*') ?: [], 'The build generation is emptied.');
        $this->assertDirectoryDoesNotExist($buildDir);

        // The lock is not this method's to delete: it is the only record of
        // whether the build that owns it is still running.
        $this->assertFileExists($this->tmpDir . '/lock');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
