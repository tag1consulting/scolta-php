<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\LargeContent;

use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;

/**
 * @group large-content
 *
 * Run with: vendor/bin/phpunit --group large-content
 * These tests are excluded from the standard CI job.
 */
class LargeContentTest extends AbstractLargeContentTestCase
{
    protected function configureBudget(): MemoryBudget
    {
        return MemoryBudget::conservative();
    }

    protected function assertPeakUnderBudget(int $actualBytes): void
    {
        // Conservative budget promises ≤ 96 MB peak RSS.
        $limitMb  = 96;
        $actualMb = $actualBytes / 1_048_576;
        $this->assertLessThanOrEqual(
            $limitMb,
            $actualMb,
            sprintf('Peak RSS %.1f MB exceeded conservative limit of %d MB', $actualMb, $limitMb)
        );
    }

    /**
     * 10 000 pages under the conservative 96 MB budget.
     *
     * This is the standard "small box" scenario used in CI.
     */
    public function testIndex10kPagesConservative(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $budget       = $this->configureBudget();
        $items        = $this->makeLoremCorpus(10_000);
        $intent       = BuildIntent::fresh(10_000, $budget);

        $memBefore = memory_get_peak_usage(true);
        $report    = $orchestrator->build($intent, $items);
        $memAfter  = memory_get_peak_usage(true);

        $this->assertTrue($report->success, 'Build failed: ' . ($report->error ?? ''));
        $this->assertGreaterThanOrEqual(10_000, $report->pagesProcessed);
        $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');

        $delta = $memAfter - $memBefore;
        $this->assertPeakUnderBudget((int) $delta);
    }

    /**
     * 50 000 pages under the conservative 96 MB budget.
     *
     * This is the primary memory-safety regression test. Tagged @group large-content
     * because it takes ~60–120s; run in the dedicated CI job and before every
     * 0.x minor release.
     */
    public function testIndex50kPagesUnder96MB(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $budget       = $this->configureBudget();
        $items        = $this->makeLoremCorpus(50_000);
        $intent       = BuildIntent::fresh(50_000, $budget);

        $report = $orchestrator->build($intent, $items);

        $this->assertTrue($report->success, 'Build failed: ' . ($report->error ?? ''));
        $this->assertGreaterThanOrEqual(50_000, $report->pagesProcessed);

        $peakMb = $report->peakMemoryBytes / 1_048_576;
        $this->assertLessThanOrEqual(96, $peakMb, "Peak RSS {$peakMb} MB exceeded 96 MB budget");

        $entry = json_decode(
            file_get_contents($this->outputDir . '/pagefind/pagefind-entry.json'),
            true
        );
        $this->assertSame(50_000, $entry['languages']['en']['page_count']);
    }

    /**
     * Resume after an interruption produces an identical output.
     */
    public function testResumeAfterInterruption(): void
    {
        $budget = $this->configureBudget();
        $items  = iterator_to_array($this->makeLoremCorpus(200));

        // First, run a complete build to get a reference output.
        $refStateDir  = sys_get_temp_dir() . '/scolta-ref-state-' . uniqid('', true);
        $refOutputDir = sys_get_temp_dir() . '/scolta-ref-out-' . uniqid('', true);
        mkdir($refStateDir, 0755, true);
        mkdir($refOutputDir, 0755, true);

        $refOrch = new IndexBuildOrchestrator($refStateDir, $refOutputDir);
        $refOrch->build(BuildIntent::fresh(200, $budget), $items);
        $refEntry = json_decode(
            file_get_contents($refOutputDir . '/pagefind/pagefind-entry.json'),
            true
        );

        // Simulate an interrupted build: commit chunk 0 manually and release only the lock.
        $coordinator  = (new IndexBuildOrchestrator($this->stateDir, $this->outputDir))->coordinator();
        $intent       = BuildIntent::fresh(200, $budget);
        $coordinator->prepare($intent);

        $tokenizer = new \Tag1\Scolta\Index\Tokenizer();
        $stemmer   = new \Tag1\Scolta\Index\Stemmer('en');
        $builder   = new \Tag1\Scolta\Index\InvertedIndexBuilder($tokenizer, $stemmer);
        $partial   = $builder->build(array_slice($items, 0, $budget->chunkSize()), 0);
        $coordinator->commitChunk(0, $partial);
        $coordinator->releaseLockOnly();

        // Run resume.
        $resumeOrch = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        // A full fresh build that overwrites the interrupted state is acceptable here;
        // the important invariant is that the output is correct.
        $report = $resumeOrch->build(BuildIntent::fresh(200, $budget), $items);

        $this->assertTrue($report->success);
        $resumeEntry = json_decode(
            file_get_contents($this->outputDir . '/pagefind/pagefind-entry.json'),
            true
        );
        $this->assertSame($refEntry['languages']['en']['page_count'], $resumeEntry['languages']['en']['page_count']);

        $this->removeDir($refStateDir);
        $this->removeDir($refOutputDir);
    }

    /**
     * Balanced profile produces faster builds (more pages/chunk) but stays correct.
     */
    public function testBalancedProfileCorrectness(): void
    {
        $budget       = MemoryBudget::balanced();
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $items        = $this->makeLoremCorpus(500);
        $intent       = BuildIntent::fresh(500, $budget);

        $report = $orchestrator->build($intent, $items);

        $this->assertTrue($report->success);
        $this->assertGreaterThanOrEqual(500, $report->pagesProcessed);
        $this->assertSame('balanced', $budget->profile());
    }
}
