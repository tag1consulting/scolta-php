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
 * band: the update must be dramatically cheaper than the full build it
 * replaces, and it must not become dramatically cheaper still without someone
 * noticing — a sudden collapse usually means the update stopped doing the work
 * rather than started doing it faster.
 *
 * Measured baseline, 2026-08-02, 5,000-page synthetic corpus on an M-series
 * laptop: full build ~46 s, single-page update ~1.6 s, ratio roughly 29x.
 * On the real 109,308-page Share My Lesson corpus the full build measured
 * 2,364 s, of which the entire merge, write and swap was 59 s — the update
 * path skips the other 97.5% because it never gathers or tokenizes a page it
 * was not told about.
 *
 * Run with:
 *     ./vendor/bin/phpunit --group benchmark tests/Benchmark/
 */
#[Group('benchmark')]
final class IncrementalUpdateBenchmarkTest extends TestCase
{
    /** Corpus size. Large enough that the merge dominates a full build. */
    private const PAGES = 5_000;

    /** An update must be at least this much cheaper than a full rebuild. */
    private const MIN_SPEEDUP = 8.0;

    /**
     * And no more than this. Not a performance ceiling — a tripwire for an
     * update that silently stopped rewriting the chunks it should.
     */
    private const MAX_SPEEDUP = 400.0;

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
            "\n  %d pages: full build %.2fs, single-page update %.2fs (%.0fx, %d chunks rewritten)\n",
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
                'Update took %.2fs against a %.2fs full build (%.0fx). Expected at least %.0fx — '
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
                'Update was %.0fx faster than the full build, beyond the %.0fx band. That usually means '
                . 'it stopped doing work it should — check that chunks were actually rewritten.',
                $speedup,
                self::MAX_SPEEDUP,
            ),
        );
    }
}
