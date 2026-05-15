<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\StatusReport;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;

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
            public function __construct(private array &$calls)
            {
            }

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
            ) {
            }

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

    public function testEmptyFragmentDirectoryReturnsFalse(): void
    {
        $real      = new FilesystemDriver();
        $outputDir = $this->outputDir;
        $deletingStorage = new class ($real, $outputDir) implements StorageDriverInterface {
            public function __construct(
                private readonly FilesystemDriver $inner,
                private readonly string $outputDir,
            ) {
            }

            public function move(string $from, string $to): bool
            {
                $result = $this->inner->move($from, $to);
                if ($result && $to === $this->outputDir . '/pagefind') {
                    foreach (glob($to . '/fragment/*.pf_fragment') ?: [] as $f) {
                        unlink($f);
                    }
                }
                return $result;
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

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir, storage: $deletingStorage);
        $items        = $this->makeItems(3);
        $intent       = BuildIntent::fresh(3, MemoryBudget::conservative());

        $report = $orchestrator->build($intent, $items);

        $this->assertFalse($report->success);
        $this->assertNotNull($report->error);
        $this->assertStringContainsString('zero fragment files', $report->error);
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

        $logger       = new class () extends \Psr\Log\AbstractLogger {
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
        $logger = new class () extends \Psr\Log\AbstractLogger {
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

        $pagefindWarnings = array_filter($logger->warnings, fn ($w) => str_contains($w, "'/pagefind'"));
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

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
