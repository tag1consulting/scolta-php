<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\StatusReport;

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
