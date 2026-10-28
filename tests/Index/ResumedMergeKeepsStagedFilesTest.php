<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\StatusReport;
use Tag1\Scolta\Tests\Benchmark\Support\IndexDirectoryComparer;

/**
 * A build killed while writing the index finishes the merge instead of
 * starting it over.
 *
 * merge_pages is the longest phase of a large build on a network filesystem
 * (65 of 95 minutes on sharemylesson.com), and a deploy that lands inside it
 * used to cost the whole phase again: the resumed segment cleared the staging
 * directory and rewrote every fragment. Now the staged files that are
 * already complete are kept, a truncated one is rewritten, and files the
 * resumed merge does not produce are removed — the pre-swap integrity check
 * counts fragments against the page table, so an orphan would fail it.
 */
final class ResumedMergeKeepsStagedFilesTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/scolta-resumed-merge-' . uniqid('', true);
        foreach (['state', 'out', 'ref-state', 'ref-out'] as $dir) {
            mkdir($this->base . '/' . $dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        self::removeDir($this->base);
    }

    public function testTheResumedMergeKeepsCompleteStagedFilesAndPublishesTheReferenceIndex(): void
    {
        $items  = $this->makeItems(12);
        $budget = MemoryBudget::conservative()->withChunkSize(4);

        $reference = (new IndexBuildOrchestrator($this->base . '/ref-state', $this->base . '/ref-out'))
            ->build(BuildIntent::fresh(12, $budget), $items);
        $this->assertTrue($reference->success, 'Reference build failed: ' . ($reference->error ?? ''));
        $refFragments = glob($this->base . '/ref-out/pagefind/fragment/*.pf_fragment') ?: [];
        $refChunks    = glob($this->base . '/ref-out/pagefind/index/*.pf_index') ?: [];
        $this->assertCount(12, $refFragments);
        $this->assertNotEmpty($refChunks);

        // The interrupted build: its first chunk committed, then — as a kill
        // mid-merge leaves it — a manifest in the merging phase and a staging
        // directory holding some complete files, one truncated, and leftovers
        // from a page that no longer exists.
        $fired = false;
        $first = new IndexBuildOrchestrator(
            $this->base . '/state',
            $this->base . '/out',
            memoryPressureProbe: function () use (&$fired): bool {
                if ($fired) {
                    return false;
                }

                return $fired = true;
            },
        );
        $yielded = $first->build(BuildIntent::fresh(12, $budget), $items);
        $this->assertSame(StatusReport::MEMORY_ABORT, $yielded->error);
        (new BuildState($this->base . '/state'))->enterPhase(BuildState::PHASE_MERGING);

        $staged = $this->base . '/out/.scolta-building';
        mkdir($staged . '/fragment', 0755, true);
        mkdir($staged . '/index', 0755, true);
        $complete = array_slice($refFragments, 0, 5);
        foreach ($complete as $path) {
            copy($path, $staged . '/fragment/' . basename($path));
        }
        $truncated = $refFragments[5];
        $bytes     = (string) file_get_contents($truncated);
        file_put_contents($staged . '/fragment/' . basename($truncated), substr($bytes, 0, intdiv(strlen($bytes), 2)));
        copy($refChunks[0], $staged . '/index/' . basename($refChunks[0]));
        file_put_contents($staged . '/fragment/0000deadbeef.pf_fragment', 'a page that was edited after the kill');
        file_put_contents($staged . '/index/0000deadbeef.pf_index', 'a chunk the resumed merge will not produce');
        file_put_contents($staged . '/pagefind.en_stale.pf_meta', 'metadata from the interrupted attempt');

        $log    = new CollectingLogger();
        $report = (new IndexBuildOrchestrator($this->base . '/state', $this->base . '/out'))
            ->build(BuildIntent::resume($budget), $items, $log);
        $this->assertTrue($report->success, 'Resumed build failed: ' . ($report->error ?? ''));
        $this->assertSame(12, $report->pagesProcessed);

        $this->assertSame(
            [],
            IndexDirectoryComparer::differences($this->base . '/ref-out/pagefind', $this->base . '/out/pagefind'),
            'The resumed merge must publish exactly the index a single-pass build publishes.',
        );
        $this->assertFileDoesNotExist($this->base . '/out/pagefind/fragment/0000deadbeef.pf_fragment');
        $this->assertFileDoesNotExist($this->base . '/out/pagefind/index/0000deadbeef.pf_index');
        $this->assertFileDoesNotExist($this->base . '/out/pagefind/pagefind.en_stale.pf_meta');
        $this->assertFileEquals($truncated, $this->base . '/out/pagefind/fragment/' . basename($truncated));

        $summary = $log->lineContaining('Resumed merge:');
        $this->assertNotNull($summary, 'The resumed merge reports what it kept.');
        $this->assertStringContainsString('5 staged fragments and 1 index chunks were complete and kept', $summary);
        $this->assertStringContainsString('7 fragments were written', $summary);
        $this->assertStringContainsString('3 stale staged files were removed', $summary);
        $this->assertNotNull($log->lineContaining('resuming the merge over its staged output'));
    }

    public function testAFreshBuildStillClearsAStrayStagingDirectory(): void
    {
        $staged = $this->base . '/out/.scolta-building/fragment';
        mkdir($staged, 0755, true);
        file_put_contents($staged . '/0000deadbeef.pf_fragment', 'left behind by nobody in particular');

        $log    = new CollectingLogger();
        $report = (new IndexBuildOrchestrator($this->base . '/state', $this->base . '/out'))
            ->build(BuildIntent::fresh(3, MemoryBudget::conservative()), $this->makeItems(3), $log);

        $this->assertTrue($report->success, $report->error ?? '');
        $this->assertFileDoesNotExist($this->base . '/out/pagefind/fragment/0000deadbeef.pf_fragment');
        $this->assertNull($log->lineContaining('resuming the merge'));
    }

    /** @return list<ContentItem> */
    private function makeItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = new ContentItem(
                id: 'page-' . $i,
                title: 'Page ' . $i,
                bodyHtml: '<p>Content for page ' . $i . ' hello world foo bar number ' . ($i * 7) . '</p>',
                url: '/page/' . $i,
                date: '2024-01-01',
                siteName: 'Test Site',
            );
        }

        return $items;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

/** Keeps every interpolated log line so a test can look for one. */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $lines = [];

    /**
     * @param mixed                $level
     * @param string|\Stringable    $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $message = (string) $message;
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }
        }
        $this->lines[] = $message;
    }

    public function lineContaining(string $needle): ?string
    {
        foreach ($this->lines as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        return null;
    }
}
