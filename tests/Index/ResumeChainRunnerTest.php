<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\ResumeChainRunner;
use Tag1\Scolta\Index\StatusReport;

class ResumeChainRunnerTest extends TestCase
{
    private string $stateDir;
    private BuildState $state;

    /** @var list<array<string, string>> Environment each fake segment was run with. */
    private array $envs = [];

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-runner-' . uniqid('', true);
        $this->state    = new BuildState($this->stateDir);
        $this->state->initiateBuild(['total_pages' => 1000]);
        $this->state->releaseLockOnly();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/{,*/,*/*/}*', GLOB_BRACE | GLOB_MARK) ?: [] as $path) {
            str_ends_with($path, '/') ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->stateDir);
    }

    public function testAYieldingSegmentIsFollowedByOneThatFinishesTheBuild(): void
    {
        $runner = $this->runner([
            fn() => $this->segmentEnds(false, StatusReport::MEMORY_ABORT, 200),
            fn() => $this->segmentEnds(true, null, 300),
        ]);

        $report = $runner->run($this->yielded(100));

        $this->assertTrue($report->success);
        $this->assertNull($report->error);
        $this->assertSame(300, $report->pagesProcessed, 'The total belongs to the segment that finished, not the parent.');
        $this->assertCount(2, $this->envs);
        $this->assertSame(['SCOLTA_RESUME_SEGMENT' => '1'], $this->envs[0]);
    }

    public function testANonResumableFailureStopsTheChainWithItsError(): void
    {
        $runner = $this->runner([
            fn() => $this->segmentEnds(false, StatusReport::MEMORY_ABORT, 200),
            fn() => $this->segmentEnds(false, 'Duplicate page ordinal 13650', 250),
            fn() => $this->fail('A broken build must not get another segment.'),
        ]);

        $report = $runner->run($this->yielded(100));

        $this->assertFalse($report->success);
        $this->assertStringContainsString('failed in segment 2', (string) $report->error);
        $this->assertStringContainsString('Duplicate page ordinal 13650', (string) $report->error);
        $this->assertCount(2, $this->envs);
    }

    public function testASegmentThatCommitsNothingIsAStall(): void
    {
        $runner = $this->runner([
            fn() => $this->segmentEnds(false, StatusReport::MEMORY_ABORT, 100),
            fn() => $this->fail('A stalled chain must not get another segment.'),
        ]);

        $report = $runner->run($this->yielded(100));

        $this->assertFalse($report->success);
        $this->assertStringContainsString('stalled at 100 pages', (string) $report->error);
        $this->assertStringContainsString('segment 1', (string) $report->error);
    }

    public function testASegmentThatDiesWithoutRecordingIsJudgedOnProgress(): void
    {
        // Killed before it could record: no outcome file, but the manifest moved.
        $runner = $this->runner([
            fn() => $this->manifestReaches(200),
            fn() => $this->segmentEnds(true, null, 300),
        ]);

        $this->assertTrue($runner->run($this->yielded(100))->success);
        $this->assertCount(2, $this->envs);
    }

    public function testTheSegmentCapStopsARunawayChain(): void
    {
        $pages  = 100;
        $runner = $this->runner(
            array_fill(0, 10, function () use (&$pages) {
                $pages += 50;

                return $this->segmentEnds(false, StatusReport::MEMORY_ABORT, $pages);
            }),
            new ResumeChainPolicy('512M', 3),
        );

        $report = $runner->run($this->yielded(100));

        $this->assertFalse($report->success);
        $this->assertStringContainsString('within 3 resume segments', (string) $report->error);
        $this->assertCount(3, $this->envs);
    }

    public function testAParentThatCommittedNoChunkSpawnsNothing(): void
    {
        $runner = $this->runner([fn() => $this->fail('Nothing to resume from.')]);

        $report = $runner->run($this->yielded(0, chunks: 0));

        $this->assertFalse($report->success);
        $this->assertStringContainsString('this build hit the memory limit', (string) $report->error);
    }

    public function testASuccessfulReportIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->runner([])->run($this->yielded(100, error: null));
    }

    /** @param list<callable(): int> $segments */
    private function runner(array $segments, ?ResumeChainPolicy $policy = null): ResumeChainRunner
    {
        return new ResumeChainRunner(
            $this->state,
            $policy ?? new ResumeChainPolicy('512M'),
            function (array $env) use (&$segments): int {
                $this->envs[] = $env;
                $this->assertNull($this->state->readOutcome(), 'The previous outcome is cleared before a segment runs.');

                return (array_shift($segments))();
            },
        );
    }

    /** What a child that ran to a terminal report leaves behind, and its exit code. */
    private function segmentEnds(bool $success, ?string $error, int $pages): int
    {
        $this->manifestReaches($pages);
        $this->state->recordOutcome($success, $error, $pages);

        return $success ? 0 : 1;
    }

    private function manifestReaches(int $pages): int
    {
        $path     = $this->state->manifestFile();
        $manifest = json_decode((string) file_get_contents($path), true);

        $manifest['pages_processed'] = $pages;
        file_put_contents($path, json_encode($manifest));

        return 1;
    }

    private function yielded(int $pages, int $chunks = 1, ?string $error = StatusReport::MEMORY_ABORT): StatusReport
    {
        return new StatusReport(
            version: '1.0.0',
            pagefindVersion: '1.5.0',
            resolvedIndexer: 'php',
            pagesProcessed: $pages,
            chunksWritten: $chunks,
            peakMemoryBytes: 1,
            memoryBudgetBytes: 1,
            durationSeconds: 0.1,
            outputDir: $this->stateDir,
            success: $error === null,
            error: $error,
        );
    }
}
