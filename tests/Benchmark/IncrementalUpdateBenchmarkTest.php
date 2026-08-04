<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Benchmark;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * What a single-page update costs, as a ratio rather than a wall-clock target.
 *
 * A hard threshold on a shared CI runner is a coin flip, so this asserts a
 * band: the update must be cheaper than the full build it replaces, and it
 * must not become dramatically cheaper still without someone noticing — a
 * sudden collapse usually means the update stopped doing the work rather than
 * started doing it faster.
 *
 * Measured baseline, 2026-08-02, M-series laptop, 5,000-page synthetic corpus,
 * across two runs: full build 4.25-4.49 s, single-page update 1.66-1.72 s,
 * 60 chunks rewritten — a ratio of only **2.5-2.7x**.
 *
 * That small ratio is the honest result for this fixture and it is worth
 * understanding before trusting it either way. The corpus is a list of
 * ContentItems already in memory, so the full build here pays no CMS gather
 * cost at all: it is almost entirely the work an update cannot avoid anyway —
 * `pf_meta`, the filter chunks and `scolta.facets` are each a function of the
 * whole page table and get rewritten in full by both paths. Against a build
 * that is nothing but that whole-corpus work, an update saves only the
 * per-page tokenization and the chunks it does not touch.
 *
 * On a real corpus the gather is the build. Share My Lesson, 109,308 pages:
 * the full build measured 2,364 s, of which `gather_wait` alone was 84.7% and
 * the entire merge, write and swap was 59 s. An update never gathers a page it
 * was not told about, so it skips that 84.7% outright, and the whole-corpus
 * artifact rewrite it cannot skip measured 6.5 s at that scale.
 *
 * So this test is a floor, not the expected win: it catches an update that has
 * started doing work proportional to the corpus, which is what would silently
 * destroy the real-world benefit.
 *
 * Run with:
 *     ./vendor/bin/phpunit --group benchmark tests/Benchmark/
 */
#[Group('benchmark')]
final class IncrementalUpdateBenchmarkTest extends TestCase
{
    /** Corpus size. Large enough that the merge dominates a full build. */
    private const PAGES = 5_000;

    /**
     * An update must be at least this much cheaper than a full rebuild.
     *
     * Deliberately close to the measured 2.5x: against a gather-free fixture
     * the headroom really is small, and a band set where the win "ought" to be
     * would just be red on every run.
     */
    private const MIN_SPEEDUP = 1.8;

    /**
     * And no more than this. Not a performance ceiling — a tripwire for an
     * update that silently stopped rewriting the chunks it should.
     */
    private const MAX_SPEEDUP = 200.0;

    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir  = sys_get_temp_dir() . '/scolta-incbench-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-incbench-out-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->stateDir, $this->outputDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }
    }

    public function testSinglePageUpdateIsFarCheaperThanAFullRebuild(): void
    {
        $items = SyntheticCorpus::generate(self::PAGES, seed: 13);

        $fullStart    = microtime(true);
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report       = $orchestrator->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
        );
        $fullSeconds = microtime(true) - $fullStart;
        $this->assertTrue($report->success, 'Full build failed: ' . ($report->error ?? ''));

        // Edit one page in the middle, changing its body but not its url.
        $edited = SyntheticCorpus::item(2_500, seed: 13, revision: 1);

        $updateStart = microtime(true);
        $updater     = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $updater->stageUpsert($edited);
        $result        = $updater->commit();
        $updateSeconds = microtime(true) - $updateStart;

        $speedup = $fullSeconds / max($updateSeconds, 0.0001);

        printf(
            "\n  %d pages: full build %.2fs, single-page update %.2fs (%.1fx, %d chunks rewritten)\n",
            self::PAGES,
            $fullSeconds,
            $updateSeconds,
            $speedup,
            $result->chunksRewritten,
        );

        $this->assertSame(1, $result->pagesUpdated);
        $this->assertGreaterThan(0, $result->chunksRewritten, 'An edit that changed vocabulary must rewrite chunks.');

        $this->assertGreaterThan(
            self::MIN_SPEEDUP,
            $speedup,
            sprintf(
                'Update took %.2fs against a %.2fs full build (%.1fx). Expected at least %.1fx — '
                . 'the update path is doing work proportional to the corpus.',
                $updateSeconds,
                $fullSeconds,
                $speedup,
                self::MIN_SPEEDUP,
            ),
        );

        $this->assertLessThan(
            self::MAX_SPEEDUP,
            $speedup,
            sprintf(
                'Update was %.1fx faster than the full build, beyond the %.0fx band. That usually means '
                . 'it stopped doing work it should — check that chunks were actually rewritten.',
                $speedup,
                self::MAX_SPEEDUP,
            ),
        );
    }
}
