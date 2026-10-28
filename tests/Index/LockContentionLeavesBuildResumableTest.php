<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\StatusReport;

/**
 * A build refused the lock must not poison the build it was refused from.
 *
 * The production sequence this guards (sharemylesson.com, 2026-10-28): a
 * deploy killed the process mid-merge. Its lock record stays live for
 * STALE_LOCK_SECONDS, so every cron tick inside that window was refused the
 * lock on resume — and each refusal was recorded in segment-outcome.json as a
 * non-memory failure, which ResumeChainPolicy::resumable() reads as "start
 * over". Three refusals later the driver gave up on the resumable build and
 * ran a fresh full build: 95 minutes of work discarded for a lock that would
 * have been free in five.
 */
final class LockContentionLeavesBuildResumableTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $uid             = uniqid('', true);
        $this->stateDir  = sys_get_temp_dir() . "/scolta-contention-state-{$uid}";
        $this->outputDir = sys_get_temp_dir() . "/scolta-contention-out-{$uid}";
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->stateDir, $this->outputDir] as $dir) {
            self::removeDir($dir);
        }
    }

    public function testARefusedResumeRecordsNothingAndTheBuildStaysResumable(): void
    {
        $items  = $this->makeItems(12);
        $budget = MemoryBudget::conservative()->withChunkSize(4);

        // A build that yielded on memory pressure after its first chunk: the
        // canonical resumable state.
        $fired  = false;
        $first  = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            memoryPressureProbe: function () use (&$fired): bool {
                if ($fired) {
                    return false;
                }

                return $fired = true;
            },
        );
        $report = $first->build(BuildIntent::fresh(12, $budget), $items);
        $this->assertSame(StatusReport::MEMORY_ABORT, $report->error);
        $this->assertTrue(ResumeChainPolicy::resumable(new BuildState($this->stateDir)));

        // Another host still holds the lock with a fresh heartbeat: the
        // process a deploy killed, as its successor sees it for the next
        // STALE_LOCK_SECONDS.
        $this->writeLiveLockRecordFromAnotherHost();

        $refused = (new IndexBuildOrchestrator($this->stateDir, $this->outputDir))
            ->build(BuildIntent::resume($budget), $items);

        $this->assertFalse($refused->success);
        $this->assertTrue($refused->isLockContention(), 'A refusal must be reported as contention, not as a failure: ' . ($refused->error ?? ''));
        $this->assertFalse($refused->isMemoryAbort());
        $this->assertStringContainsString('another build is running', (string) $refused->error);

        $outcome = (new BuildState($this->stateDir))->readOutcome();
        $this->assertSame(
            StatusReport::MEMORY_ABORT,
            $outcome['error'] ?? null,
            'The refusal overwrote the recorded outcome; the next tick will start a fresh build instead of resuming.',
        );
        $this->assertTrue(
            ResumeChainPolicy::resumable(new BuildState($this->stateDir)),
            'The build on disk must still read as resumable once the lock is free.',
        );
    }

    public function testARefusedFreshBuildRecordsNothingEither(): void
    {
        $this->writeLiveLockRecordFromAnotherHost();
        file_put_contents(
            $this->stateDir . '/manifest.json',
            json_encode(['status' => 'building', 'total_pages' => 3], JSON_THROW_ON_ERROR),
        );

        $refused = (new IndexBuildOrchestrator($this->stateDir, $this->outputDir))
            ->build(BuildIntent::fresh(3, MemoryBudget::conservative()), $this->makeItems(3));

        $this->assertTrue($refused->isLockContention());
        $this->assertStringContainsString('already running', (string) $refused->error);
        $this->assertNull((new BuildState($this->stateDir))->readOutcome());
    }

    private function writeLiveLockRecordFromAnotherHost(): void
    {
        $record = [
            'state'        => 'held',
            'owner'        => bin2hex(random_bytes(8)),
            'host'         => 'cli-5f7f8f457d-56wsl',
            'pid'          => 4242,
            'generation'   => null,
            'acquired_at'  => time() - 30,
            'heartbeat_at' => time() - 30,
        ];
        file_put_contents($this->stateDir . '/lock', json_encode($record, JSON_THROW_ON_ERROR));
    }

    /** @return list<ContentItem> */
    private function makeItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = new ContentItem(
                id: 'page-' . $i,
                title: 'Page ' . $i,
                bodyHtml: '<p>Content for page ' . $i . ' hello world foo bar</p>',
                url: '/page/' . $i,
                date: '2024-01-01',
                siteName: 'Test Site',
            );
        }

        return $items;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
