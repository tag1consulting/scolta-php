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

    /**
     * A fresh build clears the state BuildState owns, and nothing else.
     *
     * cleanup() globbed the whole state directory, so prepare() deleted the
     * token-cache manifest, both timestamp manifests and the page-table ledger
     * with its journal — files belonging to PageWordCache, TimestampManifest
     * and PageTableLedger. Each of those loads in its own constructor, so a
     * build that ran to completion rewrote what had been deleted and the loss
     * was invisible; a build that died first left nothing on disk at all.
     */
    public function testFreshPrepareKeepsStateItDoesNotOwn(): void
    {
        $foreign = self::foreignState();
        foreach ($foreign as $name => $contents) {
            file_put_contents($this->tmpDir . '/' . $name, $contents);
        }
        file_put_contents($this->tmpDir . '/chunk-000.dat', 'stale');

        $coord = new BuildCoordinator($this->tmpDir);
        $coord->prepare(BuildIntent::fresh(50, MemoryBudget::conservative()));

        self::assertForeignStateIntact($this->tmpDir, $foreign, 'prepare()');
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/chunk-000.dat',
            'A fresh build must still clear the chunk files of an abandoned one.',
        );

        $coord->release();
    }

    /**
     * release() runs the same cleanup(), after the build that succeeded has
     * already written its state back. Wiping it there is why the orchestrator
     * had to re-save the token cache and the timestamp manifest *after*
     * releasing, and why anything that did not re-save lost its file.
     */
    public function testReleaseKeepsStateItDoesNotOwn(): void
    {
        $coord = new BuildCoordinator($this->tmpDir);
        $coord->prepare(BuildIntent::fresh(1, MemoryBudget::conservative()));

        // Written during the build, the way the cache and the manifests write
        // theirs: after prepare(), before release().
        $foreign = self::foreignState();
        foreach ($foreign as $name => $contents) {
            file_put_contents($this->tmpDir . '/' . $name, $contents);
        }

        $coord->release();

        self::assertForeignStateIntact($this->tmpDir, $foreign, 'release()');
        $this->assertFileDoesNotExist($this->tmpDir . '/lock');
        $this->assertFileDoesNotExist($this->tmpDir . '/manifest.json');
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

    /**
     * State files written by the other components that share the directory.
     *
     * @return array<string, string> filename => contents
     */
    private static function foreignState(): array
    {
        return [
            'token-cache-manifest.php'     => serialize(['a1b2c3' => 0, 'd4e5f6' => 1]),
            'timestamp-manifest.php'       => serialize(['node:1' => ['ts' => 1_700_000_000, 'items' => []]]),
            'timestamp-manifest-empty.php' => serialize(['0123456789abcdef']),
            'page-table-ledger.php'        => serialize(['next' => 2, 'byId' => [], 'free' => []]),
            'page-table-ledger.journal'    => "{\"t\":\"a\",\"id\":\"x\",\"o\":0}\n",
        ];
    }

    /**
     * @param array<string, string> $foreign
     */
    private static function assertForeignStateIntact(string $dir, array $foreign, string $after): void
    {
        foreach ($foreign as $name => $contents) {
            $path = $dir . '/' . $name;
            self::assertFileExists($path, "{$after} deleted {$name}, which BuildState does not own.");
            self::assertSame($contents, (string) file_get_contents($path), "{$name} must survive {$after} byte for byte.");
        }
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
