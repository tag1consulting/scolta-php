<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\MemoryBudget;

/**
 * Two builds, one state directory.
 *
 * Regression cover for a production collision: a cron-triggered full rebuild
 * was 16 minutes into writing chunks when a manual `scolta:build` started
 * against the same NFS-backed state directory. prepare() did not see the
 * running build, cleanup() deleted its lock, manifest and chunk files, and
 * both processes then incremented one manifest's page counter and wrote the
 * same chunk-NNN.dat filenames. The manual build's integrity check compared
 * 4818 committed pages against 1518 ledger rows and failed.
 *
 * Each test here isolates one link in that chain.
 */
class BuildLockConcurrencyTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-lock-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->tmpDir);
    }

    /**
     * A PID that cannot be signalled is not evidence that the owner is dead.
     *
     * This is the link that failed in production. kill(pid, 0) answers EPERM
     * for a live process owned by another uid, and a PID from another
     * container is not in this namespace at all; the old isLockStale() read
     * both as "dead" and let the second build in. PID 1 is used because it is
     * always alive and never signalable by an unprivileged process.
     */
    public function testALiveOwnerWhosePidCannotBeSignalledStillCountsAsRunning(): void
    {
        $this->assertFalse(
            posix_kill(1, 0),
            'This test needs a live PID that this process may not signal; run it unprivileged.',
        );

        $this->writeLockRecord(['pid' => 1, 'host' => gethostname(), 'heartbeat_at' => time()]);
        $this->writeBuildingManifest();

        $state = new BuildState($this->tmpDir);
        $this->assertTrue($state->isRunning(), 'A build whose PID answers EPERM is still running.');
        $this->assertFalse($state->initiateBuild(['total_pages' => 10]));
        $this->assertFalse($state->lockDiagnostics()['stale']);
    }

    /** An owner on another host is judged by heartbeat alone, never by PID. */
    public function testAnOwnerOnAnotherHostIsNotProbedForLiveness(): void
    {
        $this->writeLockRecord([
            // A PID that is almost certainly free on this machine — irrelevant,
            // because it belongs to a different host's namespace.
            'pid'          => 2014,
            'host'         => 'some-other-container',
            'heartbeat_at' => time(),
        ]);
        $this->writeBuildingManifest();

        $state = new BuildState($this->tmpDir);
        $this->assertTrue($state->isRunning());
        $this->assertFalse($state->initiateBuild(['total_pages' => 10]));
    }

    /** A heartbeat older than the stale window releases the lock to the next build. */
    public function testAnOwnerThatStoppedHeartbeatingLosesTheLock(): void
    {
        $this->writeLockRecord([
            'pid'          => 1,
            'host'         => gethostname(),
            'heartbeat_at' => time() - 7200,
        ]);
        $this->writeBuildingManifest();

        $state = new BuildState($this->tmpDir);
        $this->assertFalse($state->isRunning());
        $this->assertTrue($state->initiateBuild(['total_pages' => 10]));
    }

    /** A second process is refused, and told who holds the lock. */
    public function testASecondBuildIsRefusedWhileTheFirstHoldsTheLock(): void
    {
        $first = new BuildCoordinator($this->tmpDir);
        $first->prepare(BuildIntent::fresh(100, MemoryBudget::conservative()));

        $second = new BuildCoordinator($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Another index build is already running');
        $second->prepare(BuildIntent::fresh(100, MemoryBudget::conservative()));
    }

    /**
     * The refusal happens before anything is deleted.
     *
     * prepare() used to check isRunning() and then cleanup() — both outside
     * the lock. One misjudged liveness check and the running build's state was
     * gone. The lock is taken first now, so a refused build touches nothing.
     */
    public function testARefusedBuildLeavesTheRunningBuildsStateIntact(): void
    {
        $first = new BuildCoordinator($this->tmpDir);
        $first->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $first->commitChunk(0, self::partial('a'));

        $manifest = $first->manifestFile();
        $chunks   = $first->chunkFiles();
        $this->assertCount(1, $chunks);

        try {
            (new BuildCoordinator($this->tmpDir))
                ->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
            $this->fail('The second build should have been refused.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertFileExists($this->tmpDir . '/lock');
        $this->assertFileExists($manifest);
        $this->assertFileExists($chunks[0]);
        $this->assertSame(1, $first->pagesProcessed(), "The refused build must not touch the running build's counter.");
    }

    /** cleanup() refuses to delete state that a different live build owns. */
    public function testCleanupWillNotDeleteAnotherLiveBuildsState(): void
    {
        $first = new BuildCoordinator($this->tmpDir);
        $first->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $first->commitChunk(0, self::partial('a'));
        $chunk = $first->chunkFiles()[0];

        // Another build has since taken the lock, under its own generation.
        $this->writeLockRecord([
            'pid'          => 4242,
            'host'         => 'another-container',
            'generation'   => 'a-different-generation',
            'heartbeat_at' => time(),
        ]);

        try {
            (new BuildState($this->tmpDir))->cleanup();
            $this->fail('cleanup() should refuse while another build is running.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is still running there', $e->getMessage());
        }

        $this->assertFileExists($chunk, "The running build's chunk files must survive.");
    }

    /**
     * The other side of that guard: a segmented build finalizing in a later
     * process may clean the generation its earlier process gathered.
     *
     * The Drupal batch UI builds a fresh indexer per step, so the process that
     * finalizes never held the lock and the gathering process exited without
     * releasing it. Refusing here would leave the lock held until the
     * heartbeat aged out and block the next build for an hour.
     */
    public function testAFinalizeInALaterProcessMayCleanItsOwnGeneration(): void
    {
        $gatherer = new BuildCoordinator($this->tmpDir);
        $gatherer->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $gatherer->commitChunk(0, self::partial('a'));
        $chunk = $gatherer->chunkFiles()[0];

        // The gathering process is gone; its lock record still says 'held'.
        $finalizer = new BuildState($this->tmpDir);
        $finalizer->cleanup();

        $this->assertFileDoesNotExist($chunk);

        // Released in the record, which is what unblocks the next build. (The
        // gathering process is still alive here holding the flock, so actually
        // initiating that next build is not something this test can stage.)
        $this->assertSame('released', $finalizer->lockDiagnostics()['state']);
        $this->assertFalse($finalizer->isRunning());
    }

    /**
     * Two builds cannot share a chunk filename even if both are somehow live.
     *
     * The generation directory is the last line of defence: with the lock
     * defeated, colliding chunk-NNN.dat writes silently mixed one build's
     * pages into the other's index.
     */
    public function testEachBuildWritesIntoItsOwnGenerationDirectory(): void
    {
        $first = new BuildCoordinator($this->tmpDir);
        $first->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $first->commitChunk(0, self::partial('a'));
        $firstDir = $first->buildState()->buildDirectory();
        $first->releaseLockOnly();

        $second = new BuildCoordinator($this->tmpDir);
        $second->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $secondDir = $second->buildState()->buildDirectory();

        $this->assertNotSame($firstDir, $secondDir);
        $this->assertSame(0, $second->pagesProcessed(), 'A fresh build starts its own page count.');
    }

    /**
     * The manifest stays at the state directory root.
     *
     * Adapters read it there directly — scolta-laravel's queue dispatcher
     * compares it across dispatches to decide whether anything changed — so
     * only the chunk files are per-generation. The manifest names the
     * generation instead, which is what lets a later process find the chunks.
     */
    public function testTheManifestStaysAtTheStateDirectoryRootAndNamesTheGeneration(): void
    {
        $coord = new BuildCoordinator($this->tmpDir);
        $coord->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $coord->commitChunk(0, self::partial('a'));

        $this->assertSame($this->tmpDir . '/manifest.json', $coord->manifestFile());
        $this->assertFileExists($this->tmpDir . '/manifest.json');

        $manifest = json_decode((string) file_get_contents($this->tmpDir . '/manifest.json'), true);
        $this->assertSame($coord->buildState()->buildDirectory(), $this->tmpDir . '/builds/' . $manifest['generation']);
        $this->assertStringStartsWith($this->tmpDir . '/builds/', $coord->chunkFiles()[0]);
    }

    /**
     * A build whose manifest has been replaced by another build's aborts.
     *
     * The manifest is shared, so this is the guard that keeps the page counter
     * from being incremented by two builds at once — 9000 pages recorded
     * against a 1518-page build, in the incident.
     */
    public function testAChunkIsNotCountedIntoAnotherBuildsManifest(): void
    {
        $coord = new BuildCoordinator($this->tmpDir);
        $coord->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $coord->commitChunk(0, self::partial('a'));

        // Another build's manifest lands at the root.
        $manifest = json_decode((string) file_get_contents($this->tmpDir . '/manifest.json'), true);
        $manifest['generation'] = 'a-different-generation';
        file_put_contents($this->tmpDir . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('describes build generation');
        $coord->commitChunk(1, self::partial('b'));
    }

    /**
     * A build that loses its lock aborts instead of writing into the winner's
     * generation.
     */
    public function testAnOverriddenBuildFailsFastOnItsNextChunk(): void
    {
        $coord = new BuildCoordinator($this->tmpDir);
        $coord->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $coord->commitChunk(0, self::partial('a'));

        // Somebody else's claim lands on the lock file.
        $this->writeLockRecord([
            'owner'        => 'someone-else',
            'pid'          => 4242,
            'host'         => 'another-container',
            'heartbeat_at' => time(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lost the index build lock');
        $coord->commitChunk(1, self::partial('b'));
    }

    /** Chunk commits refresh the heartbeat, so a long build never looks stale. */
    public function testCommittingAChunkRefreshesTheHeartbeat(): void
    {
        $coord = new BuildCoordinator($this->tmpDir);
        $coord->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));

        $state = $coord->buildState();
        $before = $state->lockDiagnostics()['heartbeat_at'];

        // Backdate the heartbeat the way a build that has been running for
        // hours would have it, then commit.
        $record = json_decode((string) file_get_contents($this->tmpDir . '/lock'), true);
        $record['heartbeat_at'] = time() - 7200;
        file_put_contents($this->tmpDir . '/lock', json_encode($record));
        $this->assertTrue($state->lockDiagnostics()['stale']);

        $coord->commitChunk(0, self::partial('a'));

        $this->assertFalse($state->lockDiagnostics()['stale']);
        $this->assertGreaterThanOrEqual($before, $state->lockDiagnostics()['heartbeat_at']);
    }

    /** A lock file written by a pre-1.5.0 build is still understood. */
    public function testALegacyPidTimestampLockIsHonoured(): void
    {
        file_put_contents($this->tmpDir . '/lock', '1:' . time());
        $this->writeBuildingManifest();

        $state = new BuildState($this->tmpDir);
        $this->assertTrue($state->isRunning(), 'A fresh legacy lock still means a build is running.');

        file_put_contents($this->tmpDir . '/lock', '1:' . (time() - 7200));
        $this->assertFalse((new BuildState($this->tmpDir))->isRunning());
    }

    /**
     * A failed lock-release write aborts rather than assuming the release
     * happened.
     *
     * assertCleanable() takes this path when an earlier process in the same
     * generation died holding the lock: it writes the record as released so
     * cleanup() may proceed. If that write fails, silently going on would
     * leave the file still claiming the dead process holds it while this
     * process deletes state on the strength of a release that never
     * happened — so it must throw instead.
     */
    public function testAFailedLockReleaseWriteAbortsCleanupRatherThanAssumingItSucceeded(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('This test needs a write that the process is not privileged enough to force through.');
        }

        $first = new BuildCoordinator($this->tmpDir);
        $first->prepare(BuildIntent::fresh(20, MemoryBudget::conservative()));
        $first->commitChunk(0, self::partial('a'));
        // The gathering process died without releasing — the segmented-build
        // case assertCleanable() exists for — but the lock record is
        // otherwise exactly what a live build's would be.

        chmod($this->tmpDir . '/lock', 0444);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to write released lock record');
            // @: file_put_contents() itself emits a PHP-level warning for the
            // same permission failure the exception already reports; the
            // assertion above is what proves the failure was not swallowed.
            @(new BuildState($this->tmpDir))->cleanup();
        } finally {
            chmod($this->tmpDir . '/lock', 0644);
        }
    }

    /**
     * A purge that cannot fully clear abandoned state fails loudly and
     * releases the lock, instead of leaving an unremovable generation
     * directory to accumulate silently on every future fresh build.
     */
    public function testAPurgeThatCannotDeleteAbandonedStateAbortsAndReleasesTheLock(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('This test needs a write that the process is not privileged enough to force through.');
        }

        $abandoned = $this->tmpDir . '/builds/20200101T000000Z-deadbeef';
        mkdir($abandoned, 0755, true);
        file_put_contents($abandoned . '/chunk-000.dat', 'stale');
        // No write permission on the directory itself: unlink() of the file
        // inside it, and rmdir() of it, both fail.
        chmod($abandoned, 0555);

        $state = new BuildState($this->tmpDir);

        try {
            $state->initiateBuild(['total_pages' => 10]);
            $this->fail('initiateBuild() should have failed to purge the abandoned generation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to clear abandoned build state', $e->getMessage());
        }

        // Not left held by a build that never actually started.
        $this->assertFalse($state->isRunning());

        // Once the underlying problem is fixed, the next build is not stuck
        // behind it.
        chmod($abandoned, 0755);
        $this->assertTrue(
            (new BuildState($this->tmpDir))->initiateBuild(['total_pages' => 10]),
            'A failed purge must not block every future build behind an unremovable directory.',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function writeLockRecord(array $overrides): void
    {
        $record = array_merge([
            'state'        => 'held',
            'owner'        => bin2hex(random_bytes(8)),
            'host'         => gethostname(),
            'pid'          => getmypid(),
            'generation'   => null,
            'acquired_at'  => time(),
            'heartbeat_at' => time(),
        ], $overrides);

        file_put_contents($this->tmpDir . '/lock', json_encode($record, JSON_THROW_ON_ERROR));
    }

    /** A manifest at the legacy root path, which isRunning() still reads. */
    private function writeBuildingManifest(): void
    {
        file_put_contents(
            $this->tmpDir . '/manifest.json',
            json_encode(['status' => 'building', 'total_pages' => 10], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{pages: array, index: array}
     */
    private static function partial(string $label): array
    {
        return [
            'pages' => [0 => [
                'url' => '/' . $label,
                'wordCount' => 1,
                'content' => $label,
                'meta' => ['title' => $label],
                'filters' => [],
            ]],
            'index' => [$label => [0 => ['positions' => [25 => [0]], 'meta_positions' => []]]],
        ];
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
