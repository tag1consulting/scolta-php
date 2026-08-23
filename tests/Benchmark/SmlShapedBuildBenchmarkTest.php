<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Benchmark;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\StatusReport;
use Tag1\Scolta\Tests\Benchmark\Support\IndexDirectoryComparer;
use Tag1\Scolta\Tests\Benchmark\Support\SmlShapedCorpus;

/**
 * The three builds an operator actually runs, on a corpus shaped like the one
 * this package is slow on, with the numbers printed.
 *
 * Cold, then warm from cached references, then a single incremental edit.
 * {@see SmlShapedCorpus} explains why the shape matters: a Zipf vocabulary
 * with a long tail behaves nothing like the twenty-word topic pool the other
 * fixtures draw from, and the merge, the chunk cover and the compression are
 * all sensitive to which one they are handed.
 *
 * What it asserts is correctness, not speed: all three builds succeed, and the
 * warm index decompresses to exactly the cold index. That equality is the
 * thing every optimisation of this pipeline has to keep true, and it is
 * asserted on the decompressed bytes deliberately — the compression level is
 * not part of the format, so trading a level for wall clock must read as
 * equal here rather than as a regression.
 *
 * The timings are printed rather than asserted. A wall-clock threshold on a
 * shared CI runner is a coin flip; the numbers are here to be read by whoever
 * is changing the pipeline, alongside the orchestrator's own phase summary,
 * which is where the build actually spends its time.
 *
 * Run with:
 *     ./vendor/bin/phpunit --group benchmark tests/Benchmark/SmlShapedBuildBenchmarkTest.php
 */
#[Group('benchmark')]
final class SmlShapedBuildBenchmarkTest extends TestCase
{
    /**
     * Default corpus size. Two orders below the real 109,308 and still large
     * enough for the merge and the whole-corpus artifacts to dominate.
     */
    private const DEFAULT_PAGES = 5_000;

    /**
     * Corpus size for this run, from SCOLTA_BENCH_PAGES.
     *
     * The default is what CI runs. The optimisation work on this pipeline is
     * measured at 20,000 and re-checked at the real 109,308, and neither of
     * those belongs in a shared-runner default, so the size is an environment
     * variable rather than a constant somebody edits and forgets to put back.
     */
    private static function pages(): int
    {
        $raw = getenv('SCOLTA_BENCH_PAGES');
        if ($raw === false || trim($raw) === '') {
            return self::DEFAULT_PAGES;
        }
        $pages = (int) trim($raw);

        return $pages > 0 ? $pages : self::DEFAULT_PAGES;
    }

    private string $stateDir;
    private string $outputDir;
    private string $coldSnapshot;

    protected function setUp(): void
    {
        $base               = sys_get_temp_dir() . '/scolta-sml-bench-' . uniqid('', true);
        $this->stateDir     = $base . '/state';
        $this->outputDir    = $base . '/out';
        $this->coldSnapshot = $base . '/cold';
        foreach ([$this->stateDir, $this->outputDir, $this->coldSnapshot] as $dir) {
            mkdir($dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        self::removeDir(dirname($this->stateDir));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
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

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? mkdir($target, 0755, true) : copy($item->getPathname(), $target);
        }
    }

    public function testColdThenWarmThenIncrementalOnAnSmlShapedCorpus(): void
    {
        $pages  = self::pages();
        $corpus = new SmlShapedCorpus($pages);
        $logger = new PhaseSummaryLogger();

        $items = [];
        foreach ($corpus->items() as $item) {
            $items[] = $item;
        }

        // ── Cold: nothing cached, every page tokenized. ────────────────────
        $t0   = microtime(true);
        $cold = $this->build($items, $logger);
        $coldSeconds = microtime(true) - $t0;
        $this->assertTrue($cold->success, 'Cold build failed: ' . ($cold->error ?? ''));
        $this->assertSame($pages, $cold->pagesProcessed);

        self::copyDir($this->outputDir . '/pagefind', $this->coldSnapshot . '/pagefind');

        // ── Warm: what an adapter hands the orchestrator when nothing has
        //    changed — one reference per page, no bodies loaded. ────────────
        $references = [];
        foreach ($items as $item) {
            $references[] = $corpus->cachedReference($item);
        }

        $t0   = microtime(true);
        $warm = $this->build($references, $logger);
        $warmSeconds = microtime(true) - $t0;
        $this->assertTrue($warm->success, 'Warm build failed: ' . ($warm->error ?? ''));
        $this->assertSame($pages, $warm->pagesProcessed, 'A warm build must index every page it was handed.');

        $this->assertSame(
            [],
            IndexDirectoryComparer::differences(
                $this->coldSnapshot . '/pagefind',
                $this->outputDir . '/pagefind',
            ),
            'A warm build must produce the index the cold build produced.',
        );

        // ── Incremental: one page edited, everything else untouched. ───────
        $updater = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $updater->stageUpsert($corpus->item(intdiv($pages, 2), edit: 1));
        $t0     = microtime(true);
        $update = $updater->commit();
        $updateSeconds = microtime(true) - $t0;

        $this->assertSame(1, $update->pagesUpdated);
        $this->assertGreaterThan(0, $update->chunksRewritten);

        printf(
            "\n%s: %d pages\n  cold        %6.2fs\n  warm        %6.2fs (%.1fx)\n"
            . "  incremental %6.3fs (%d chunks rewritten)\n",
            self::class,
            $pages,
            $coldSeconds,
            $warmSeconds,
            $warmSeconds > 0.0 ? $coldSeconds / $warmSeconds : 0.0,
            $updateSeconds,
            $update->chunksRewritten,
        );
        foreach ($logger->phaseSummaries as $line) {
            echo '  ' . $line . "\n";
        }
    }

    /**
     * @param list<\Tag1\Scolta\Export\ContentItem|\Tag1\Scolta\Index\CachedContentReference> $pages
     */
    private function build(array $pages, PhaseSummaryLogger $logger): StatusReport
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);

        return $orchestrator->build(
            BuildIntent::fresh(count($pages), MemoryBudget::default()),
            $pages,
            $logger,
        );
    }
}

/**
 * Keeps the orchestrator's own phase breakdown, interpolated, and drops
 * everything else so the benchmark output stays readable.
 */
final class PhaseSummaryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $phaseSummaries = [];

    /**
     * @param mixed                $level
     * @param string|\Stringable    $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $message = (string) $message;
        if (!str_contains($message, 'Phase summary') && !str_contains($message, 'Sub-timers')) {
            return;
        }

        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }
        }

        $this->phaseSummaries[] = $message;
    }
}
