<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\MemoryBudget;

class BuildCoordinatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-coord-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testFreshPrepareInitiatesNewBuild(): void
    {
        $coord  = new BuildCoordinator($this->tmpDir);
        $intent = BuildIntent::fresh(100, MemoryBudget::conservative());
        $manifest = $coord->prepare($intent);

        $this->assertIsArray($manifest);
        $this->assertSame(100, $manifest['total_pages']);
        $this->assertTrue(file_exists($this->tmpDir . '/manifest.json'));
        $this->assertTrue(file_exists($this->tmpDir . '/lock'));

        $coord->release();
    }

    public function testFreshPrepareClearsExistingState(): void
    {
        // Write a fake chunk file to simulate prior state.
        file_put_contents($this->tmpDir . '/chunk-000.dat', 'dummy');

        $coord  = new BuildCoordinator($this->tmpDir);
        $intent = BuildIntent::fresh(50, MemoryBudget::conservative());
        $coord->prepare($intent);

        $this->assertFalse(file_exists($this->tmpDir . '/chunk-000.dat'), 'Fresh build must wipe old chunks');
        $coord->release();
    }

    public function testCommitChunkWritesFile(): void
    {
        $coord  = new BuildCoordinator($this->tmpDir);
        $intent = BuildIntent::fresh(2, MemoryBudget::conservative());
        $coord->prepare($intent);

        $partial = [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 1, 'content' => 'hello', 'meta' => ['title' => 'A'], 'filters' => []]],
            'index' => ['hello' => [0 => ['positions' => [25 => [0]], 'meta_positions' => []]]],
        ];
        $coord->commitChunk(0, $partial);

        $files = $coord->chunkFiles();
        $this->assertCount(1, $files);
        $this->assertTrue(file_exists($files[0]));

        $coord->release();
    }

    public function testReleaseDeletesStateFiles(): void
    {
        $coord  = new BuildCoordinator($this->tmpDir);
        $intent = BuildIntent::fresh(1, MemoryBudget::conservative());
        $coord->prepare($intent);
        $coord->release();

        $this->assertFalse(file_exists($this->tmpDir . '/lock'));
        $this->assertFalse(file_exists($this->tmpDir . '/manifest.json'));
    }

    public function testReleaseLockOnlyPreservesChunks(): void
    {
        $coord  = new BuildCoordinator($this->tmpDir);
        $intent = BuildIntent::fresh(2, MemoryBudget::conservative());
        $coord->prepare($intent);

        $partial = [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 1, 'content' => 'x', 'meta' => [], 'filters' => []]],
            'index' => [],
        ];
        $coord->commitChunk(0, $partial);
        $coord->releaseLockOnly();

        // Lock should be gone but chunk file should remain.
        $this->assertFalse(file_exists($this->tmpDir . '/lock'));
        $this->assertNotEmpty($coord->chunkFiles());
    }

    public function testResumeRequiresExistingResumeState(): void
    {
        $coord  = new BuildCoordinator($this->tmpDir);
        $intent = BuildIntent::resume(MemoryBudget::conservative());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No resumable build found/');
        $coord->prepare($intent);
    }

    public function testResumePicksUpFromExistingManifest(): void
    {
        // Simulate an interrupted build: prepare, commit, release lock only.
        $coord1  = new BuildCoordinator($this->tmpDir);
        $intent1 = BuildIntent::fresh(10, MemoryBudget::conservative());
        $coord1->prepare($intent1);
        $partial = [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 1, 'content' => 'x', 'meta' => [], 'filters' => []]],
            'index' => [],
        ];
        $coord1->commitChunk(0, $partial);
        $coord1->releaseLockOnly();

        // Now resume.
        $coord2  = new BuildCoordinator($this->tmpDir);
        $intent2 = BuildIntent::resume(MemoryBudget::conservative());
        $manifest = $coord2->prepare($intent2);

        $this->assertSame(10, (int) $manifest['total_pages']);
        $this->assertCount(1, $coord2->chunkFiles());

        $coord2->release();
    }

    public function testPagesProcessedReflectsCommittedChunks(): void
    {
        $coord  = new BuildCoordinator($this->tmpDir);
        $intent = BuildIntent::fresh(3, MemoryBudget::conservative());
        $coord->prepare($intent);

        $partial = [
            'pages' => [
                0 => ['url' => '/a', 'wordCount' => 1, 'content' => 'x', 'meta' => [], 'filters' => []],
                1 => ['url' => '/b', 'wordCount' => 1, 'content' => 'y', 'meta' => [], 'filters' => []],
            ],
            'index' => [],
        ];
        $coord->commitChunk(0, $partial);

        $this->assertSame(2, $coord->pagesProcessed());
        $coord->release();
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
