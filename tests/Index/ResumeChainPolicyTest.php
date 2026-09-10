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

class ResumeChainPolicyTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $uid             = uniqid('', true);
        $this->stateDir  = sys_get_temp_dir() . "/scolta-policy-state-{$uid}";
        $this->outputDir = sys_get_temp_dir() . "/scolta-policy-out-{$uid}";
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->stateDir, $this->outputDir] as $dir) {
            $this->removeDir($dir);
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

    // -------------------------------------------------------------------
    // failureReason(): the process-spawning drivers' form
    // -------------------------------------------------------------------

    public function testANonMemoryFailureStopsTheChainWithItsOwnError(): void
    {
        $policy = new ResumeChainPolicy('512M');
        $reason = $policy->failureReason(['error' => 'Duplicate page ordinal 13650'], 500, 100, 2);
        $this->assertStringContainsString('segment 2', (string) $reason);
        $this->assertStringContainsString('Duplicate page ordinal 13650', (string) $reason);
    }

    public function testASegmentThatCommittedNothingIsAStall(): void
    {
        $policy = new ResumeChainPolicy('512M');
        $reason = $policy->failureReason(['error' => StatusReport::MEMORY_ABORT], 100, 100, 1);
        $this->assertStringContainsString('stalled at 100 pages', (string) $reason);
        $this->assertStringContainsString('512M', (string) $reason);

        $this->assertStringContainsString(
            'this build hit the memory limit',
            (string) $policy->failureReason(['error' => StatusReport::MEMORY_ABORT], 0, 0, 0),
            'Segment 0 is the run the operator started, not a resume.',
        );
    }

    public function testASegmentThatDiedWithoutRecordingIsJudgedOnProgress(): void
    {
        $policy = new ResumeChainPolicy();
        $this->assertNull($policy->failureReason(null, 200, 100, 1));
        $this->assertNotNull($policy->failureReason(null, 100, 100, 1));
    }

    public function testTheSegmentCapStopsARunawayChain(): void
    {
        $policy = new ResumeChainPolicy(null, 3);
        $this->assertNull($policy->failureReason(['error' => StatusReport::MEMORY_ABORT], 300, 200, 2));
        $this->assertStringContainsString(
            'within 3 resume segments',
            (string) $policy->failureReason(['error' => StatusReport::MEMORY_ABORT], 400, 300, 3),
        );
    }

    // -------------------------------------------------------------------
    // resumable(): what the next cron run finds on disk
    // -------------------------------------------------------------------

    public function testNothingOnDiskIsNotResumable(): void
    {
        $this->assertFalse(ResumeChainPolicy::resumable(new BuildState($this->stateDir)));
    }

    public function testAMemoryYieldOrAKilledRunIsResumableButARecordedFailureIsNot(): void
    {
        $state = new BuildState($this->stateDir);
        $state->initiateBuild(['total_pages' => 10]);
        $state->recordChunk(0, ['pages' => [['id' => 'a']], 'terms' => []]);
        $state->releaseLockOnly();
        $this->assertTrue(ResumeChainPolicy::resumable($state), 'Killed mid-run: no outcome, chunks are good.');

        $state->recordOutcome(false, StatusReport::MEMORY_ABORT, 1);
        $this->assertTrue(ResumeChainPolicy::resumable($state));

        $state->recordOutcome(false, 'token cache lost; re-run with --force', 1);
        $this->assertFalse(ResumeChainPolicy::resumable($state), 'Resuming would reach the same error.');

        $state->recordOutcome(false, StatusReport::MEMORY_ABORT, 1);
        $state->releaseLock();
        $state->cleanup();
        $this->assertFalse(ResumeChainPolicy::resumable($state), 'A finished build leaves nothing to resume.');
    }

    // -------------------------------------------------------------------
    // stopReason(): in-process form
    // -------------------------------------------------------------------

    public function testStopReasonRefusesASuccessfulReport(): void
    {
        $this->expectException(\LogicException::class);
        (new ResumeChainPolicy())->stopReason($this->report(true, null, 5), new BuildState($this->stateDir));
    }

    public function testStopReasonReturnsANonMemoryErrorVerbatim(): void
    {
        $reason = (new ResumeChainPolicy())->stopReason(
            $this->report(false, 'index_only_complete', 5),
            new BuildState($this->stateDir),
        );
        $this->assertSame('index_only_complete', $reason);
    }

    public function testAStalledYieldIsRecordedSoTheNextRunStartsFresh(): void
    {
        $state = new BuildState($this->stateDir);
        $state->initiateBuild(['total_pages' => 10]);
        $state->recordChunk(0, ['pages' => [['id' => 'a']], 'terms' => []]);
        $state->releaseLockOnly();
        $state->resumeBuild($state->shouldResume());
        $state->releaseLockOnly();
        $state->recordOutcome(false, StatusReport::MEMORY_ABORT, 1);
        $this->assertTrue(ResumeChainPolicy::resumable($state));

        // Segment 1 started at 1 page and yielded at 1 page.
        $reason = (new ResumeChainPolicy('256M'))->stopReason($this->report(false, StatusReport::MEMORY_ABORT, 1), $state);
        $this->assertStringContainsString('stalled', (string) $reason);
        $this->assertSame($reason, $state->readOutcome()['error']);
        $this->assertFalse(ResumeChainPolicy::resumable($state));
    }

    /**
     * The whole cron-shaped flow against the real orchestrator: every run
     * decides from disk alone, holds nothing between runs, and runs one
     * segment.
     */
    public function testACronWorkerCompletesABuildAcrossRunsFromDiskAlone(): void
    {
        $budget = MemoryBudget::conservative()->withChunkSize(3);
        $items  = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = new ContentItem(
                id: "page-{$i}",
                title: "Page {$i}",
                bodyHtml: "<p>Content for page {$i} hello world foo bar</p>",
                url: "/page/{$i}",
                date: '2024-01-01',
                siteName: 'Test Site',
            );
        }

        $policy = new ResumeChainPolicy();
        $runs   = 0;
        do {
            $runs++;
            $fired = false;
            $orch  = new IndexBuildOrchestrator(
                $this->stateDir,
                $this->outputDir,
                memoryPressureProbe: function () use (&$fired): bool {
                    if ($fired) {
                        return false;
                    }

                    return $fired = true;
                },
            );
            $state  = $orch->coordinator()->buildState();
            $resume = ResumeChainPolicy::resumable($state);
            $intent = $resume ? BuildIntent::resume($budget) : BuildIntent::fresh(count($items), $budget);

            // The worker hands the whole corpus back; the ledger skips committed ids.
            $report = $orch->build($intent, $items);
            if ($report->success) {
                break;
            }
            $this->assertNull($policy->stopReason($report, $state), 'Each yield made progress, so the worker re-enqueues.');
        } while ($runs < 10);

        $this->assertTrue($report->success, $report->error ?? '');
        $this->assertGreaterThan(1, $runs, 'The probe must have forced at least one yield.');
        $this->assertSame(10, $report->pagesProcessed);
        $this->assertCount(10, glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: []);
        $this->assertFalse(ResumeChainPolicy::resumable($state), 'A published build leaves nothing to resume.');
    }

    private function report(bool $success, ?string $error, int $pages): StatusReport
    {
        return new StatusReport(
            version: '1.0.0',
            pagefindVersion: '1.5.0',
            resolvedIndexer: 'php',
            pagesProcessed: $pages,
            chunksWritten: 1,
            peakMemoryBytes: 1,
            memoryBudgetBytes: 1,
            durationSeconds: 0.1,
            outputDir: $this->outputDir,
            success: $success,
            error: $error,
        );
    }
}
