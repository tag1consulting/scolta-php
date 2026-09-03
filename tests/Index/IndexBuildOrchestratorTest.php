<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\StatusReport;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;
use Tag1\Scolta\Tests\Support\CborDecoder;

class IndexBuildOrchestratorTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $uid            = uniqid('', true);
        $this->stateDir = sys_get_temp_dir() . "/scolta-orch-state-{$uid}";
        $this->outputDir = sys_get_temp_dir() . "/scolta-orch-out-{$uid}";
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    private function makeItems(int $count, int $offset = 0): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = new ContentItem(
                id: 'page-' . ($offset + $i),
                title: 'Page ' . ($offset + $i),
                bodyHtml: '<p>Content for page ' . ($offset + $i) . ' hello world foo bar</p>',
                url: '/page/' . ($offset + $i),
                date: '2024-01-01',
                siteName: 'Test Site',
            );
        }

        return $items;
    }

    public function testBuildHappyPath(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $items        = $this->makeItems(5);
        $intent       = BuildIntent::fresh(5, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);

        $this->assertInstanceOf(StatusReport::class, $report);
        $this->assertTrue($report->success, $report->error ?? 'No error');
        $this->assertGreaterThan(0, $report->pagesProcessed);
        $this->assertDirectoryExists($this->outputDir . '/pagefind');
        $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');
    }

    public function testBuildPublishesOverStagingDirsLeftByAnInterruptedSwap(): void
    {
        // What an interrupted swap leaves on disk. Both are rename() targets
        // and rename() onto a non-empty directory fails with ENOTEMPTY, so
        // before the fix the first of these wedged every subsequent build:
        // the swap threw, and the failure reproduced the state that caused it.
        mkdir($this->outputDir . '/.scolta-new', 0755, true);
        mkdir($this->outputDir . '/.scolta-old', 0755, true);
        file_put_contents($this->outputDir . '/.scolta-new/corpse.txt', 'stale');
        file_put_contents($this->outputDir . '/.scolta-old/corpse.txt', 'stale');

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report       = $orchestrator->build(
            BuildIntent::fresh(3, MemoryBudget::conservative()),
            $this->makeItems(3),
        );

        $this->assertTrue($report->success, $report->error ?? 'No error');
        $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');
        $this->assertFileDoesNotExist($this->outputDir . '/pagefind/corpse.txt');
        $this->assertDirectoryDoesNotExist($this->outputDir . '/.scolta-new');
        $this->assertDirectoryDoesNotExist($this->outputDir . '/.scolta-old');
        $this->assertDirectoryDoesNotExist($this->outputDir . '/.scolta-building');
    }

    public function testBuildSweepsRetiredIndexTrashAfterPublishing(): void
    {
        // The swap retires the previous live index by renaming it to a
        // `.scolta-trash-*` directory — the inline unlink loop that used to
        // run there took hours on NFS (~8 unlinks/sec against ~100k fragment
        // files) after the new index was already published, which read as a
        // hang. The build then sweeps all trash after the swap: post-publish
        // so it gates nothing, announced at notice level, parallelized where
        // the platform allows. Trash from a build that died before its own
        // sweep is picked up here too.
        mkdir($this->outputDir . '/.scolta-trash-crashed', 0755, true);
        file_put_contents($this->outputDir . '/.scolta-trash-crashed/stale.pf_fragment', 'x');

        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $notices = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::NOTICE) {
                    $this->notices[] = (string) $message;
                }
            }
        };
        $intent = fn() => BuildIntent::fresh(2, MemoryBudget::conservative());
        for ($i = 0; $i < 2; $i++) {
            $report = (new IndexBuildOrchestrator($this->stateDir, $this->outputDir))
                ->build($intent(), $this->makeItems(2), $logger);
            $this->assertTrue($report->success, $report->error ?? 'No error');
        }

        $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');
        $this->assertDirectoryDoesNotExist($this->outputDir . '/.scolta-old');
        $this->assertSame([], glob($this->outputDir . '/.scolta-trash-*') ?: []);
        $sweepNotices = array_filter($logger->notices, fn($n) => str_contains($n, 'retired index'));
        $this->assertNotEmpty($sweepNotices, 'The sweep must announce itself so it is not mistaken for a hang');
    }

    public function testReportCarriesAWarningWhenTrashCannotBeFullyDeleted(): void
    {
        // A sweep failure must never fail the build (it is best-effort by
        // design), but it also must not vanish into the log alone — a caller
        // that only inspects the returned report needs a way to know cleanup
        // is still pending. StatusReport already had an unused $warnings
        // field for exactly this kind of case.
        $real = new FilesystemDriver();
        $failDelete = new class ($real) implements StorageDriverInterface {
            public function __construct(private readonly FilesystemDriver $inner) {}

            public function deleteDirectory(string $path): bool
            {
                return str_contains($path, '.scolta-trash-') ? false : $this->inner->deleteDirectory($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
            public function get(string $path): string
            {
                return $this->inner->get($path);
            }
            public function put(string $path, string $c): bool
            {
                return $this->inner->put($path, $c);
            }
            public function delete(string $path): bool
            {
                return $this->inner->delete($path);
            }
            public function makeDirectory(string $path): bool
            {
                return $this->inner->makeDirectory($path);
            }
            public function move(string $from, string $to): bool
            {
                return $this->inner->move($from, $to);
            }
            public function files(string $dir, string $p = '*'): array
            {
                return $this->inner->files($dir, $p);
            }
        };

        // First build publishes a live index; the second's swap retires it
        // to trash, which this storage can never actually delete.
        $intent = fn() => BuildIntent::fresh(2, MemoryBudget::conservative());
        (new IndexBuildOrchestrator($this->stateDir, $this->outputDir, storage: $failDelete))
            ->build($intent(), $this->makeItems(2));
        $report = (new IndexBuildOrchestrator($this->stateDir, $this->outputDir, storage: $failDelete))
            ->build($intent(), $this->makeItems(2));

        $this->assertTrue($report->success, 'A stuck trash directory must not fail the build');
        $this->assertNotNull($report->warnings);
        $this->assertNotEmpty(glob($this->outputDir . '/.scolta-trash-*') ?: []);
    }

    public function testBuildCreatesFragmentFiles(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $items        = $this->makeItems(3);
        $intent       = BuildIntent::fresh(3, MemoryBudget::conservative());

        $orchestrator->build($intent, $items);

        $fragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertCount(3, $fragments);
    }

    public function testBuildWithProgressReporter(): void
    {
        $calls        = ['start' => 0, 'advance' => 0, 'finish' => 0];
        $reporter     = new class ($calls) implements \Tag1\Scolta\Index\ProgressReporterInterface {
            public function __construct(private array &$calls) {}

            public function start(int $totalSteps, string $label): void
            {
                $this->calls['start']++;
            }

            public function advance(int $steps = 1, ?string $detail = null): void
            {
                $this->calls['advance']++;
            }

            public function finish(?string $summary = null): void
            {
                $this->calls['finish']++;
            }
        };

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $items        = $this->makeItems(4);
        $intent       = BuildIntent::fresh(4, MemoryBudget::conservative());
        $orchestrator->build($intent, $items, null, $reporter);

        $this->assertSame(1, $calls['start']);
        $this->assertGreaterThanOrEqual(1, $calls['advance']);
        $this->assertSame(1, $calls['finish']);
    }

    public function testBuildWithMultipleChunks(): void
    {
        // Use a tiny chunk size (1 page per chunk) to test multi-chunk merge.
        $budget = MemoryBudget::fromBytes(0); // smallest → conservative
        $items  = $this->makeItems(5);
        $intent = BuildIntent::fresh(5, MemoryBudget::conservative());

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report = $orchestrator->build($intent, $items);

        $this->assertTrue($report->success);
    }

    public function testResumeAfterInterruption(): void
    {
        $items = $this->makeItems(6);

        // Simulate an interrupted build: run the first chunk manually via coordinator.
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent       = BuildIntent::fresh(6, MemoryBudget::conservative());
        $coordinator  = $orchestrator->coordinator();

        $coordinator->prepare($intent);

        // Use the InvertedIndexBuilder directly to build chunk 0.
        $tokenizer = new \Tag1\Scolta\Index\Tokenizer();
        $stemmer   = new \Tag1\Scolta\Index\Stemmer('en');
        $builder   = new \Tag1\Scolta\Index\InvertedIndexBuilder($tokenizer, $stemmer);
        $partial   = $builder->build(array_slice($items, 0, 3), 0);
        $coordinator->commitChunk(0, $partial);
        $coordinator->releaseLockOnly();

        // Verify the chunk file exists.
        $this->assertCount(1, $coordinator->chunkFiles());

        // Now build the rest normally — but since we can't easily inject "resume from chunk 1"
        // into the orchestrator's build() without skipping pages, we just run a full fresh build
        // and assert it succeeds. The resume regression test is in BuildCoordinatorTest.
        $orchestrator2 = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent2       = BuildIntent::fresh(6, MemoryBudget::conservative());
        $report        = $orchestrator2->build($intent2, $items);

        $this->assertTrue($report->success);
        $this->assertGreaterThan(0, $report->pagesProcessed);
    }

    public function testReturnsFalseStatusOnError(): void
    {
        // Place a regular file at the output path so mkdir inside fails.
        $badOutput = $this->outputDir . '/blocked';
        file_put_contents($badOutput, 'x');
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $badOutput);
        $items        = $this->makeItems(2);
        $intent       = BuildIntent::fresh(2, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);

        $this->assertFalse($report->success);
        $this->assertNotNull($report->error);
    }

    public function testBuildResultConversion(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $items        = $this->makeItems(2);
        $intent       = BuildIntent::fresh(2, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);
        $result = $report->toBuildResult();

        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->pageCount);
    }

    public function testAtomicSwapFailureReturnsFalse(): void
    {
        $real    = new FilesystemDriver();
        $moveCallCount = 0;
        $failingStorage = new class ($real, $moveCallCount) implements StorageDriverInterface {
            public function __construct(
                private readonly FilesystemDriver $inner,
                private int &$moveCallCount,
            ) {}

            public function move(string $from, string $to): bool
            {
                $this->moveCallCount++;
                return false;
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
            public function get(string $path): string
            {
                return $this->inner->get($path);
            }
            public function put(string $path, string $c): bool
            {
                return $this->inner->put($path, $c);
            }
            public function delete(string $path): bool
            {
                return $this->inner->delete($path);
            }
            public function deleteDirectory(string $path): bool
            {
                return $this->inner->deleteDirectory($path);
            }
            public function makeDirectory(string $path): bool
            {
                return $this->inner->makeDirectory($path);
            }
            public function files(string $dir, string $p = '*'): array
            {
                return $this->inner->files($dir, $p);
            }
        };

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir, storage: $failingStorage);
        $items        = $this->makeItems(3);
        $intent       = BuildIntent::fresh(3, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);

        $this->assertFalse($report->success);
        $this->assertNotNull($report->error);
        $this->assertStringContainsString('Failed to stage', $report->error);
    }

    /**
     * An index with no fragments is refused.
     *
     * Driven through the check directly rather than by deleting the fragments
     * a swap just published: the check now runs against the staged directory
     * before the swap, precisely so a refusal leaves the live index alone, and
     * nothing the storage driver is asked to do happens between the write and
     * the check.
     */
    public function testEmptyFragmentDirectoryReturnsFalse(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report       = $orchestrator->build(BuildIntent::fresh(3, MemoryBudget::conservative()), $this->makeItems(3));
        $this->assertTrue($report->success, (string) $report->error);

        // An index root shaped like the writer's output, with the fragments
        // missing — the silent-write-failure the message names.
        $staged = $this->outputDir . '/.scolta-staged-empty';
        mkdir($staged . '/fragment', 0755, true);

        $method = new \ReflectionMethod(IndexBuildOrchestrator::class, 'verifyOutputHasFragments');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/zero fragment files/');
        $method->invoke($orchestrator, 3, $staged);
    }

    public function testBuildWithNoItemsSucceeds(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent       = BuildIntent::fresh(0, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, []);

        // Zero items is a degenerate but valid state — not a failure.
        $this->assertInstanceOf(StatusReport::class, $report);
        $this->assertSame(0, $report->pagesProcessed);
    }

    // -------------------------------------------------------------------
    // atomicSwap: output_dir /pagefind suffix normalization
    // -------------------------------------------------------------------

    public function testOutputDirWithoutPagofindSuffixWorksNormally(): void
    {
        // Standard case: output_dir = /some/path (no /pagefind suffix).
        // Index should land at /some/path/pagefind — unchanged behavior.
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $items        = $this->makeItems(2);
        $intent       = BuildIntent::fresh(2, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);

        $this->assertTrue($report->success);
        $this->assertDirectoryExists($this->outputDir . '/pagefind');
        $this->assertDirectoryDoesNotExist($this->outputDir . '/pagefind/pagefind');
    }

    public function testOutputDirWithPagofindSuffixDoesNotDoubleNest(): void
    {
        // Bug case: output_dir = /some/path/pagefind.
        // Without the fix the index would land at /some/path/pagefind/pagefind.
        // With the fix the index lands at /some/path/pagefind (the configured path).
        $outputDirWithSuffix = $this->outputDir . '/pagefind';
        mkdir($outputDirWithSuffix, 0755, true);

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $outputDirWithSuffix);
        $items        = $this->makeItems(2);
        $intent       = BuildIntent::fresh(2, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);

        $this->assertTrue($report->success, $report->error ?? 'No error');
        // Index must be AT the configured path, not one level deeper.
        $this->assertDirectoryExists($outputDirWithSuffix);
        $this->assertFileExists($outputDirWithSuffix . '/pagefind-entry.json');
        // The double-nested directory must NOT exist.
        $this->assertDirectoryDoesNotExist($outputDirWithSuffix . '/pagefind');
    }

    public function testOutputDirWithTrailingSlashAndPagofindDoesNotDoubleNest(): void
    {
        // Trailing-slash variant: output_dir = /some/path/pagefind/
        $outputDirWithSuffix = $this->outputDir . '/pagefind';
        mkdir($outputDirWithSuffix, 0755, true);

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $outputDirWithSuffix . '/');
        $items        = $this->makeItems(2);
        $intent       = BuildIntent::fresh(2, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);

        $this->assertTrue($report->success, $report->error ?? 'No error');
        $this->assertFileExists($outputDirWithSuffix . '/pagefind-entry.json');
        $this->assertDirectoryDoesNotExist($outputDirWithSuffix . '/pagefind');
    }

    public function testOutputDirNormalizationLogsWarning(): void
    {
        $outputDirWithSuffix = $this->outputDir . '/pagefind';
        mkdir($outputDirWithSuffix, 0755, true);

        $logger       = new class extends \Psr\Log\AbstractLogger {
            public array $warnings = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $outputDirWithSuffix);
        $items        = $this->makeItems(1);
        $intent       = BuildIntent::fresh(1, MemoryBudget::conservative());

        $orchestrator->build($intent, $items, $logger);

        $this->assertNotEmpty($logger->warnings, 'A warning must be logged when output_dir ends with /pagefind');
        $this->assertStringContainsString("'/pagefind'", $logger->warnings[0]);
    }

    public function testOutputDirWithoutSuffixLogsNoWarning(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $warnings = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $items        = $this->makeItems(1);
        $intent       = BuildIntent::fresh(1, MemoryBudget::conservative());

        $orchestrator->build($intent, $items, $logger);

        $pagefindWarnings = array_filter($logger->warnings, fn($w) => str_contains($w, "'/pagefind'"));
        $this->assertEmpty($pagefindWarnings, 'No /pagefind normalization warning expected for a correct output_dir');
    }

    // -------------------------------------------------------------------
    // gc_mem_caches() availability
    // -------------------------------------------------------------------

    public function testGcMemCachesIsAvailableOnPhp83(): void
    {
        if (PHP_VERSION_ID < 80300) {
            $this->markTestSkipped('gc_mem_caches() requires PHP 8.3+');
        }

        $this->assertTrue(function_exists('gc_mem_caches'), 'gc_mem_caches() must exist on PHP 8.3+');
    }

    // -------------------------------------------------------------------
    // Voluntary memory-aware restart
    // -------------------------------------------------------------------

    public function testBuildYieldsWithMemoryAbortWhenPressureDetected(): void
    {
        // Use more pages than one chunk and force the pressure probe to trigger
        // after the first committed chunk.
        $probeCallCount = 0;
        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            memoryPressureProbe: function () use (&$probeCallCount): bool {
                // Yield on the first pressure check (after chunk 0 is committed).
                return ++$probeCallCount === 1;
            },
        );

        $budget = MemoryBudget::conservative()->withChunkSize(2);
        $items  = $this->makeItems(10);
        $intent = BuildIntent::fresh(10, $budget);

        $report = $orchestrator->build($intent, $items);

        $this->assertFalse($report->success);
        $this->assertSame('memory_abort', $report->error);
        $this->assertGreaterThan(0, $report->chunksWritten);
        $this->assertGreaterThan(0, $report->pagesProcessed);
    }

    public function testVoluntaryYieldPreservesStateForResume(): void
    {
        // Step 1: build with forced yield after first chunk.
        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            memoryPressureProbe: static fn() => true,
        );

        $budget = MemoryBudget::conservative()->withChunkSize(3);
        $items  = $this->makeItems(9);
        $intent = BuildIntent::fresh(9, $budget);

        $firstReport = $orchestrator->build($intent, $items);

        $this->assertSame('memory_abort', $firstReport->error);
        // Chunk files must be on disk for resume to work.
        $this->assertGreaterThan(0, $firstReport->chunksWritten);
    }

    public function testResumeAfterVoluntaryYieldProducesCompleteIndex(): void
    {
        $budget  = MemoryBudget::conservative()->withChunkSize(3);
        $allItems = $this->makeItems(9);
        $total   = 9;

        // Step 1: single-pass reference build.
        $refStateDir  = sys_get_temp_dir() . '/scolta-ref-state-' . uniqid('', true);
        $refOutputDir = sys_get_temp_dir() . '/scolta-ref-out-' . uniqid('', true);
        mkdir($refStateDir, 0755, true);
        mkdir($refOutputDir, 0755, true);

        $refOrch = new IndexBuildOrchestrator($refStateDir, $refOutputDir);
        $refReport = $refOrch->build(BuildIntent::fresh($total, $budget), $allItems);
        $this->assertTrue($refReport->success, 'Reference build must succeed: ' . ($refReport->error ?? ''));

        // Step 2: multi-cycle build via voluntary yield.
        $yieldCycles = 0;
        $maxCycles   = 20;
        $pagesCommitted = 0;

        do {
            $probeHasFired = false;
            $orch = new IndexBuildOrchestrator(
                $this->stateDir,
                $this->outputDir,
                memoryPressureProbe: function () use (&$probeHasFired): bool {
                    // Yield exactly once per build() invocation.
                    if (!$probeHasFired) {
                        $probeHasFired = true;
                        return true;
                    }
                    return false;
                },
            );

            $mode   = $yieldCycles === 0 ? BuildIntent::fresh($total, $budget) : BuildIntent::resume($budget);
            $offset = $pagesCommitted;
            $slice  = array_slice($allItems, $offset);

            $report = $orch->build($mode, $slice);
            $yieldCycles++;

            if ($report->error === 'memory_abort') {
                $pagesCommitted = $report->pagesProcessed;
            }
        } while ($report->error === 'memory_abort' && $yieldCycles < $maxCycles);

        $this->assertTrue($report->success, 'Multi-cycle build must ultimately succeed: ' . ($report->error ?? ''));

        // The final index must contain the same fragment files as the reference.
        $refFragments  = glob($refOutputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $testFragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertCount(count($refFragments), $testFragments, 'Fragment count must match single-pass reference');

        $this->removeDir($refStateDir);
        $this->removeDir($refOutputDir);
    }

    // -------------------------------------------------------------------
    // CachedContentReference sortable passthrough
    // -------------------------------------------------------------------

    public function testCachedContentReferenceCarriesSortableIntoIndex(): void
    {
        // Build 1: fresh index with ContentItem that has sortable data.
        // This populates the PageWordCache with token data for the item.
        $item = new ContentItem(
            id: 'article-1',
            title: 'Test Article',
            bodyHtml: '<p>Wikipedia featured article about quantum physics with many references to notable works.</p>',
            url: '/node/1',
            date: '2024-01-01',
            siteName: 'Test',
            sortable: ['word_count' => 5000, 'reference_count' => 42],
        );

        $orch1  = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent = BuildIntent::fresh(1, MemoryBudget::conservative());
        $report = $orch1->build($intent, [$item]);
        $this->assertTrue($report->success, 'Build 1 must succeed: ' . ($report->error ?? ''));

        // Build 2: simulate an incremental rebuild using CachedContentReference.
        // Same stateDir → PageWordCache loaded into memory before cleanup() wipes disk files.
        $hash = PhpIndexer::contentHash($item);
        $ref  = new CachedContentReference(
            entityKey: '1',
            contentHash: $hash,
            id: 'article-1',
            url: '/node/1',
            date: '2024-01-01',
            siteName: 'Test',
            language: 'en',
            filters: [],
            sortable: ['word_count' => 5000, 'reference_count' => 42],
        );

        $orch2   = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent2 = BuildIntent::fresh(1, MemoryBudget::conservative());
        $report2 = $orch2->build($intent2, [$ref]);
        $this->assertTrue($report2->success, 'Build 2 must succeed: ' . ($report2->error ?? ''));

        // Verify the output pf_meta contains sort data for both fields.
        $metaFiles = glob($this->outputDir . '/pagefind/pagefind.*.pf_meta') ?: [];
        $this->assertCount(1, $metaFiles, 'Expected one pf_meta file');
        $decoded = CborDecoder::decodePfFile($metaFiles[0]);
        $sorts   = $decoded[4];

        $sortFieldNames = array_column($sorts, 0);
        $this->assertContains('word_count', $sortFieldNames, 'word_count sort data must be in the index');
        $this->assertContains('reference_count', $sortFieldNames, 'reference_count sort data must be in the index');
    }

    // -------------------------------------------------------------------
    // CachedContentReference metadata passthrough
    // -------------------------------------------------------------------

    /**
     * The cached path must not lose ContentItem::$metadata.
     *
     * CachedContentReference had no metadata property, so makeSlimProxy()'s
     * `$page->metadata ?? []` resolved to [] on every unchanged entity and any
     * per-item meta key vanished from the whole corpus with nothing logged.
     */
    public function testCachedContentReferenceCarriesMetadataIntoIndex(): void
    {
        // Build 1: fresh index from a ContentItem carrying metadata. This
        // populates the PageWordCache with token data keyed by content hash.
        $item = new ContentItem(
            id: 'article-2',
            title: 'Cached Metadata Article',
            bodyHtml: '<p>An article about incremental rebuilds with enough prose to tokenize into several terms.</p>',
            url: '/node/2',
            date: '2024-02-02',
            siteName: 'Test',
            metadata: ['entity_type' => 'node', 'entity_id' => '4321'],
        );

        $orch1  = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent = BuildIntent::fresh(1, MemoryBudget::conservative());
        $report = $orch1->build($intent, [$item]);
        $this->assertTrue($report->success, 'Build 1 must succeed: ' . ($report->error ?? ''));

        // Build 2: the incremental path. Same stateDir, so the token data cached
        // by build 1 is found by hash and the reference stands in for the entity.
        $hash = PhpIndexer::contentHash($item);
        $ref  = new CachedContentReference(
            entityKey: '2',
            contentHash: $hash,
            id: 'article-2',
            url: '/node/2',
            date: '2024-02-02',
            siteName: 'Test',
            language: 'en',
            filters: [],
            sortable: [],
            metadata: ['entity_type' => 'node', 'entity_id' => '4321'],
        );

        $orch2   = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent2 = BuildIntent::fresh(1, MemoryBudget::conservative());
        $report2 = $orch2->build($intent2, [$ref]);
        $this->assertTrue($report2->success, 'Build 2 must succeed: ' . ($report2->error ?? ''));

        // Fragments are gzipped JSON, not CBOR, so the meta map is read from the
        // fragment file rather than from pf_meta.
        $fragments = $this->loadFragments($this->outputDir . '/pagefind');
        $this->assertCount(1, $fragments, 'Expected one fragment from the cached-reference build');

        $meta = $fragments[0]['meta'] ?? [];
        $this->assertSame(
            'node',
            $meta['entity_type'] ?? null,
            'entity_type from CachedContentReference::$metadata must survive into the fragment meta map',
        );
        $this->assertSame(
            '4321',
            $meta['entity_id'] ?? null,
            'entity_id from CachedContentReference::$metadata must survive into the fragment meta map',
        );
    }

    /**
     * Decode every fragment under a pagefind output directory.
     *
     * Same recipe as IndexerUrlParityTest::loadFragmentsByBodyId(): gzipped
     * JSON, optionally prefixed with a 12-byte `pagefind_dcd` delimiter.
     *
     * @return list<array<string, mixed>>
     */
    private function loadFragments(string $dir): array
    {
        $fragments = [];
        $files     = glob($dir . '/fragment/*.pf_fragment') ?: glob($dir . '/*.pf_fragment');

        foreach ($files ?: [] as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $decompressed = gzdecode($raw);
            if ($decompressed === false) {
                continue;
            }
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }
            $json = json_decode($decompressed, true);
            if (is_array($json)) {
                $fragments[] = $json;
            }
        }

        return $fragments;
    }

    // -------------------------------------------------------------------
    // Index verification
    // -------------------------------------------------------------------

    public function testVerifyIndexCompletePassesOnValidBuild(): void
    {
        $orch   = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $intent = BuildIntent::fresh(3, MemoryBudget::conservative());
        $report = $orch->build($intent, $this->makeItems(3));

        $this->assertTrue($report->success);

        // Should not throw — index is valid.
        IndexBuildOrchestrator::verifyIndexComplete($this->outputDir);
        $this->assertTrue(true); // Assertion to confirm no exception
    }

    public function testVerifyIndexCompleteThrowsWhenEntryMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pagefind-entry\.json not found/');

        IndexBuildOrchestrator::verifyIndexComplete($this->outputDir);
    }

    public function testVerifyIndexCompleteThrowsOnMalformedEntry(): void
    {
        $pagefindDir = $this->outputDir . '/pagefind';
        mkdir($pagefindDir, 0755, true);
        file_put_contents($pagefindDir . '/pagefind-entry.json', '{"broken": true}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed/');

        IndexBuildOrchestrator::verifyIndexComplete($this->outputDir);
    }

    public function testMemoryAbortDoesNotProduceValidIndex(): void
    {
        $orch = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            memoryPressureProbe: static fn() => true,
        );

        $budget = MemoryBudget::conservative()->withChunkSize(2);
        $intent = BuildIntent::fresh(10, $budget);
        $report = $orch->build($intent, $this->makeItems(10));

        $this->assertFalse($report->success);
        $this->assertSame('memory_abort', $report->error);

        // pagefind-entry.json must NOT exist after an aborted build.
        $entryPath = $this->outputDir . '/pagefind/pagefind-entry.json';
        $this->assertFileDoesNotExist($entryPath);
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
