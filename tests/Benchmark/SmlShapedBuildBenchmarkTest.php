<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Benchmark;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\AbstractLogger as PsrAbstractLogger;
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
     * The three build costs, with a tolerance band and the numbers behind it.
     *
     * ## Why the bands are what they are
     *
     * The obvious assertion — "a warm build is under a third of a cold one" —
     * is not a property of this pipeline; it is a property of the host. A cold
     * build is dominated by tokenization and a warm one is not, so the ratio
     * moves with how fast the machine tokenizes. Measured at 20,000 pages:
     *
     *   | host                    | cold   | warm  | warm/cold | edit/warm |
     *   |-------------------------|--------|-------|-----------|-----------|
     *   | Apple silicon, PHP 8.5  | 7.4 s  | 5.9 s | 0.80      | 0.051     |
     *   | 2 vCPU container (109k) | 128 s  | 61 s  | 0.48      | 0.059     |
     *
     * A "under a third" threshold fails on the faster host while the pipeline is
     * working perfectly, which is how a timing test teaches people to ignore it.
     *
     * Asserted at 20,000 pages or more, never less: see the note in the body.
     *
     * So the band asserted is the property the work actually established: **a
     * warm build is faster than a cold one.** Before this round it was 1.57x
     * *slower* at 20,000 pages (14.4 s against 9.2 s), because every page was
     * re-serialised into a chunk file identical to the one already on disk and
     * the build forced a garbage collection 400 times on the way. Both hosts
     * above clear the band with room; a regression to the old behaviour misses
     * it by a factor of two.
     *
     * The edit band is the one that transfers: an incremental update's cost is a
     * function of what changed, so it stays near 5% of a warm build on both
     * hosts, and 10% is a real ceiling rather than a restatement of the
     * measurement.
     *
     * Alongside them is an assertion that does not depend on wall clock at all:
     * a warm build over an unchanged corpus must reuse *every* chunk. That is
     * the mechanism the timing depends on, and it either happened or it did not.
     */
    public function testTheThreeBuildsStayWithinTheirMeasuredBands(): void
    {
        // Never below 20,000, whatever SCOLTA_BENCH_PAGES says. At 5,000 the
        // warm build is about 1.3 s and an incremental update's fixed costs are
        // most of the 0.11 s it takes, so the edit ratio sits at 0.084-0.093
        // against a 0.10 ceiling and crosses it whenever the machine is busy.
        // That is the band being too small for the corpus, not a regression, and
        // a test that reports it as one is worse than no test.
        $pages  = max(20_000, self::pages());
        $corpus = new SmlShapedCorpus($pages);

        $items = [];
        foreach ($corpus->items() as $item) {
            $items[] = $item;
        }

        $coldLog = new ReuseCountingLogger();
        $t0      = microtime(true);
        $cold    = $this->build($items, $coldLog);
        $coldSeconds = microtime(true) - $t0;
        $this->assertTrue($cold->success, 'Cold build failed: ' . ($cold->error ?? ''));

        $references = [];
        foreach ($items as $item) {
            $references[] = $corpus->cachedReference($item);
        }

        $warmLog = new ReuseCountingLogger();
        $t0      = microtime(true);
        $warm    = $this->build($references, $warmLog);
        $warmSeconds = microtime(true) - $t0;
        $this->assertTrue($warm->success, 'Warm build failed: ' . ($warm->error ?? ''));

        $updater = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $updater->stageUpsert($corpus->item(intdiv($pages, 2), edit: 1));
        $t0     = microtime(true);
        $update = $updater->commit();
        $editSeconds = microtime(true) - $t0;
        $this->assertSame(1, $update->pagesUpdated);

        printf(
            "\n%s bands: %d pages\n  cold %6.2fs  warm %6.2fs (%.2f of cold)  edit %6.3fs (%.3f of warm)\n"
            . "  chunks reused on the warm build: %d of %d\n",
            self::class,
            $pages,
            $coldSeconds,
            $warmSeconds,
            $coldSeconds > 0.0 ? $warmSeconds / $coldSeconds : 0.0,
            $editSeconds,
            $warmSeconds > 0.0 ? $editSeconds / $warmSeconds : 0.0,
            $warmLog->chunksReused,
            $warm->chunksWritten,
        );

        // Host-independent: the mechanism either fired or it did not.
        $this->assertSame(
            $warm->chunksWritten,
            $warmLog->chunksReused,
            'A warm build over an unchanged corpus rebuilt chunks it already had.',
        );

        $this->assertLessThan(
            $coldSeconds,
            $warmSeconds,
            sprintf(
                'A warm build took %.2fs against a cold build\'s %.2fs. It used to be 1.57x slower; '
                . 'being slower again means chunk reuse or the GC fix has regressed.',
                $warmSeconds,
                $coldSeconds,
            ),
        );

        $this->assertLessThan(
            $warmSeconds * 0.10,
            $editSeconds,
            sprintf(
                'A one-page edit took %.3fs, over 10%% of the %.2fs warm build. Measured at ~5%% on two hosts.',
                $editSeconds,
                $warmSeconds,
            ),
        );
    }

    /**
     * Every optimisation switched off must publish the same index.
     *
     * The differential proof for the round. Chunk reuse and fragment reuse both
     * skip reading the data they are reproducing, so "the index looks fine" is
     * not evidence of anything. The reference is the same pipeline with both
     * switched off, over a *copy of the same state directory* — a fresh state
     * directory means a fresh ledger, which renumbers the corpus and so renames
     * every fragment for a reason that has nothing to do with the optimisations.
     *
     * ## Two different claims, because only one of them is true everywhere
     *
     * For a **warm build** the claim is byte equality over the whole directory,
     * and it is exact: the corpus has not changed, so the vocabulary has not
     * changed, so there is nothing for the two paths to disagree about. This is
     * the assertion chunk reuse lives or dies by.
     *
     * For a state reached by **incremental updates** the claim is equality of
     * fragments, of the page table, and of every posting of every term — but not
     * of which chunk file carries which term. The updater treats the
     * `pf_meta[2]` range table as frozen on purpose, which is what stops one new
     * term renaming most of the index; a full rebuild re-cuts chunk boundaries by
     * byte size over whatever vocabulary it is handed. On a corpus large enough
     * to have a dozen chunks, an edit that introduces a term shifts a flush
     * boundary and every later chunk is renamed. Both indexes are correct and
     * answer every query identically. Asserting bytes there would be asserting
     * that the frozen range table does not work, which is why the per-operation
     * byte-identity claims live in IncrementalDifferentialTest, where the corpus
     * is small enough for the cover to be stable.
     */
    public function testTheOptimisedBuildEqualsTheReferenceBuildOnEveryState(): void
    {
        $pages  = self::pages();
        $corpus = new SmlShapedCorpus($pages);
        $logger = new ReuseCountingLogger();

        $items = [];
        foreach ($corpus->items() as $item) {
            $items[] = $item;
        }
        $references = [];
        foreach ($items as $item) {
            $references[] = $corpus->cachedReference($item);
        }

        $counts = [];

        // ── Cold, then warm. ──────────────────────────────────────────────
        $this->build($items, $logger);
        $counts['cold'] = $this->countComparableFiles();

        $this->build($references, $logger);
        $counts['warm'] = $this->countComparableFiles();

        // The warm state, compared in full against the same build with every
        // optimisation off, over a copy of the same ledger.
        $warmReference = $this->referenceBuild($references, 'warm');
        $warmDiff      = IndexDirectoryComparer::differences(
            $warmReference . '/pagefind',
            $this->outputDir . '/pagefind',
        );

        // ── One edit. ─────────────────────────────────────────────────────
        $updater = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $updater->stageUpsert($corpus->item(intdiv($pages, 2), edit: 1));
        $updater->commit();
        $counts['one edit'] = $this->countComparableFiles();

        $editFinal     = $this->corpusAfter($corpus, $items, [intdiv($pages, 2) => 1], null, false);
        $editReference = $this->referenceBuild($editFinal, 'edit');
        $editContent   = self::contentDifferences($editReference, $this->outputDir);

        // ── Edit plus a new page plus a delete. ───────────────────────────
        $updater = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $updater->stageUpsert($corpus->item(intdiv($pages, 4), edit: 2));
        $updater->stageUpsert($corpus->item($pages + 1));
        $updater->stageDelete((string) intdiv($pages, 3));
        $updater->commit();
        $counts['edit plus new plus delete'] = $this->countComparableFiles();

        $mixedFinal = $this->corpusAfter(
            $corpus,
            $items,
            [intdiv($pages, 2) => 1, intdiv($pages, 4) => 2],
            intdiv($pages, 3),
            true,
        );
        $mixedReference = $this->referenceBuild($mixedFinal, 'mixed');
        $mixedContent   = self::contentDifferences($mixedReference, $this->outputDir);

        printf("\n%s differential:\n", self::class);
        foreach ($counts as $label => $count) {
            printf("  %-26s %d files in the published index\n", $label, $count);
        }
        printf(
            "  warm, whole directory      %d differences\n"
            . "  one edit, content          %d differences\n"
            . "  edit+new+delete, content   %d differences\n",
            count($warmDiff),
            count($editContent),
            count($mixedContent),
        );

        $this->assertSame(
            [],
            $warmDiff,
            'A warm build that reused its chunks published a different index than the reference path.',
        );
        $this->assertSame([], $editContent, 'A one-page edit changed index content the reference path did not.');
        $this->assertSame(
            [],
            $mixedContent,
            'An edit plus a new page plus a delete changed index content the reference path did not.',
        );
    }

    /**
     * The corpus as it stands after a set of edits, an optional delete and an
     * optional appended page.
     *
     * @param list<\Tag1\Scolta\Export\ContentItem> $items
     * @param array<int, int>                        $edits    Item number => edit revision.
     * @return list<\Tag1\Scolta\Export\ContentItem>
     */
    private function corpusAfter(
        SmlShapedCorpus $corpus,
        array $items,
        array $edits,
        ?int $deleted,
        bool $appended,
    ): array {
        $pages = self::pages();
        $final = [];
        foreach ($items as $item) {
            $n = (int) $item->id;
            if ($deleted !== null && $n === $deleted) {
                continue;
            }
            $final[] = isset($edits[$n]) ? $corpus->item($n, edit: $edits[$n]) : $item;
        }
        if ($appended) {
            $final[] = $corpus->item($pages + 1);
        }

        return $final;
    }

    /**
     * Build $pages with every optimisation off, over a copy of the current
     * state directory, and return the output directory.
     *
     * @param list<\Tag1\Scolta\Export\ContentItem|\Tag1\Scolta\Index\CachedContentReference> $pages
     */
    private function referenceBuild(array $pages, string $label): string
    {
        $base  = dirname($this->stateDir) . '/ref-' . $label;
        $state = $base . '/state';
        $out   = $base . '/out';
        self::copyDir($this->stateDir, $state);
        mkdir($out, 0755, true);

        $result = (new IndexBuildOrchestrator(
            $state,
            $out,
            reuseFragments: false,
            reuseChunks: false,
        ))->build(
            BuildIntent::fresh(count($pages), MemoryBudget::default()),
            $pages,
        );
        $this->assertTrue($result->success, "Reference build ({$label}) failed: " . ($result->error ?? ''));

        return $out;
    }

    /**
     * Content differences between two indexes, ignoring the chunk cover.
     *
     * Fragments and the page table are compared by name and bytes; term postings
     * are compared as one merged map across all chunks, so which chunk file
     * carries a term does not enter into it. See the class-level note on why that
     * is the right comparison for a state reached incrementally.
     *
     * @return list<string>
     */
    private static function contentDifferences(string $expectedOut, string $actualOut): array
    {
        $out = [];

        $fragments = static function (string $dir): array {
            $map = [];
            foreach (glob($dir . '/pagefind/fragment/*.pf_fragment') ?: [] as $path) {
                $map[basename($path)] = hash('sha256', (string) gzdecode((string) file_get_contents($path)));
            }
            ksort($map);

            return $map;
        };
        if ($fragments($expectedOut) !== $fragments($actualOut)) {
            $out[] = 'fragments differ';
        }

        $postings = static function (string $dir): array {
            $terms = [];
            foreach (glob($dir . '/pagefind/index/*.pf_index') ?: [] as $path) {
                foreach (\Tag1\Scolta\Index\PfIndexCodec::decodeChunkFile($path) as $word => $entry) {
                    ksort($entry);
                    $terms[(string) $word] = hash('sha256', serialize($entry));
                }
            }
            ksort($terms);

            return $terms;
        };
        if ($postings($expectedOut) !== $postings($actualOut)) {
            $out[] = 'term postings differ';
        }

        $pageTable = static function (string $dir): array {
            $paths = glob($dir . '/pagefind/pagefind.*.pf_meta') ?: [];
            if ($paths === []) {
                return [];
            }
            /** @var list<mixed> $meta */
            $meta = \Tag1\Scolta\Index\CborDecoder::decodeArtifact($paths[0]);

            return array_map(
                static fn(array $row): string => (string) $row[0] . ':' . (string) $row[1],
                $meta[1] ?? [],
            );
        };
        if ($pageTable($expectedOut) !== $pageTable($actualOut)) {
            $out[] = 'page table differs';
        }

        return $out;
    }

    /** Count the comparable files in the current published index. */
    private function countComparableFiles(): int
    {
        $pf = $this->outputDir . '/pagefind';

        return count(glob($pf . '/index/*') ?: [])
            + count(glob($pf . '/fragment/*') ?: [])
            + count(glob($pf . '/filter/*') ?: [])
            + count(glob($pf . '/*.pf_meta') ?: [])
            + count(glob($pf . '/scolta.facets') ?: []);
    }

    /**
     * @param list<\Tag1\Scolta\Export\ContentItem|\Tag1\Scolta\Index\CachedContentReference> $pages
     */
    private function build(array $pages, \Psr\Log\LoggerInterface $logger): StatusReport
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

/**
 * Keeps the reuse counts the build reports, so a timing test can assert the
 * mechanism fired rather than only that the clock agreed.
 */
final class ReuseCountingLogger extends PsrAbstractLogger
{
    public int $chunksReused = 0;

    /**
     * @param mixed                $level
     * @param string|\Stringable    $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $message = (string) $message;
        if (str_contains($message, 'index chunks were unchanged')) {
            $this->chunksReused = (int) ($context['chunks'] ?? 0);
        }
    }
}
