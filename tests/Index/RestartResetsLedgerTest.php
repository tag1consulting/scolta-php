<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\ChunkWriter;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\IndexMerger;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\StreamingFormatWriter;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * --restart means "rebuild from scratch", including the page table.
 *
 * Observed on a production site: a build failed at the merge with
 * "Duplicate page ordinal 13650 across chunks … Re-run with --restart to
 * rebuild from scratch", and the restart could not clear it. The duplicate
 * lived in the page-table journal, which no restart touched — beginBuild()
 * only bumped the generation — so the restart inherited a 139 MB journal
 * written by two concurrent builds, spent 5.5 hours indexing 119,077 pages,
 * and refused to merge on the same ordinal. The operator's only way out was
 * unlinking the journal by hand.
 *
 * The line these tests hold: a restart renumbers from zero and inherits
 * nothing, a plain fresh build still reuses ordinals (which is what makes
 * fragment reuse across builds possible), and the failure message names the
 * files an operator would otherwise have to find themselves.
 */
#[CoversClass(IndexBuildOrchestrator::class)]
#[CoversClass(PageTableLedger::class)]
#[CoversClass(BuildIntent::class)]
final class RestartResetsLedgerTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir  = sys_get_temp_dir() . '/scolta-restart-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-restart-out-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->stateDir);
        self::removeDir($this->outputDir);
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

    /**
     * @param list<ContentItem> $items
     */
    private function build(BuildIntent $intent, array $items): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $result       = $orchestrator->build($intent, $items);

        $this->assertTrue($result->success, 'Build failed: ' . ($result->error ?? ''));
    }

    /**
     * @param list<ContentItem> $items
     */
    private function buildFresh(array $items): void
    {
        $this->build(BuildIntent::fresh(count($items), MemoryBudget::conservative()), $items);
    }

    /**
     * @param list<ContentItem> $items
     */
    private function buildRestart(array $items): void
    {
        $this->build(BuildIntent::restart(count($items), MemoryBudget::conservative()), $items);
    }

    private function ledger(): PageTableLedger
    {
        return new PageTableLedger($this->stateDir, new FilesystemDriver());
    }

    /**
     * @param list<ContentItem> $items
     * @return list<ContentItem>
     */
    private static function without(array $items, string $id): array
    {
        return array_values(array_filter(
            $items,
            static fn(ContentItem $i): bool => $i->id !== $id,
        ));
    }

    public function testARestartAllocatesFromZeroAndInheritsNothing(): void
    {
        $items = SyntheticCorpus::generate(12, seed: 5);
        $this->buildFresh($items);

        // Delete a page from the middle so the ledger carries the three things
        // a restart must not inherit: assignments, a free ordinal, and a
        // tombstone.
        $survivors = self::without($items, 'item-6');
        $this->buildFresh($survivors);

        $before = $this->ledger();
        $this->assertSame([5], $before->tombstones(), 'Precondition: the fresh rebuild tombstoned the delete.');
        $this->assertSame(12, $before->pageTableSize());
        $this->assertSame(6, $before->ordinalFor('item-7'), 'Precondition: survivors kept their ordinals.');

        $this->buildRestart($survivors);

        $after = $this->ledger();
        $this->assertSame([], $after->tombstones(), 'A restart must not inherit tombstones.');
        $this->assertSame(11, $after->pageTableSize(), 'A restart numbers exactly the corpus it was given.');
        $this->assertSame(11, $after->liveCount());
        $this->assertNull($after->ordinalFor('item-6'), 'The deleted id must not come back.');

        // Gather order from zero, with no gap left where item-6 used to be:
        // item-7 moves from 6 to 5, which is the renumbering a restart is.
        foreach ($survivors as $i => $item) {
            $this->assertSame(
                $i,
                $after->ordinalFor($item->id),
                "After a restart, {$item->id} should hold gather-order ordinal {$i}.",
            );
        }

        // Held separately from the ordinals: the free list is not observable
        // through the public surface, and an inherited free ordinal would show
        // up here as the next append landing on a reused number instead of 11.
        $appended   = $survivors;
        $appended[] = SyntheticCorpus::item(99, seed: 5);
        $this->buildFresh($appended);
        $this->assertSame(11, $this->ledger()->ordinalFor('item-99'));
    }

    public function testARestartOntoADisjointCorpusReleasesEveryOldId(): void
    {
        $first = SyntheticCorpus::generate(8, seed: 5);
        $this->buildFresh($first);

        $second = array_map(
            static fn(int $n): ContentItem => SyntheticCorpus::item($n, seed: 5),
            range(100, 104),
        );
        $this->buildRestart($second);

        $ledger = $this->ledger();
        foreach ($first as $item) {
            $this->assertNull(
                $ledger->ordinalFor($item->id),
                "{$item->id} was not in the restart's corpus and must hold no ordinal.",
            );
        }
        $this->assertSame(5, $ledger->pageTableSize(), 'The table must be sized by the new corpus alone.');
        $this->assertSame(0, $ledger->ordinalFor('item-100'));
        $this->assertSame(4, $ledger->ordinalFor('item-104'));

        // The index has to agree: one fragment per ordinal, no leftover rows
        // padding the table out to the old corpus's size.
        $fragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertCount(5, $fragments);
    }

    /**
     * The other half of the decision. Ordinal continuity across plain fresh
     * builds is what lets an incremental update reuse the fragment files a
     * full build wrote, so a fresh build deliberately keeps the ledger — only
     * --restart (and an explicit --reset-ledger) discards it.
     */
    public function testAFreshBuildStillReusesOrdinalsAcrossBuilds(): void
    {
        $items = SyntheticCorpus::generate(10, seed: 5);
        $this->buildFresh($items);

        $survivors = self::without($items, 'item-4');
        $this->buildFresh($survivors);

        $ledger = $this->ledger();
        $this->assertSame([3], $ledger->tombstones(), 'A fresh build tombstones rather than renumbers.');
        $this->assertSame(10, $ledger->pageTableSize(), 'The table stays dense at its previous size.');
        $this->assertSame(9, $ledger->liveCount());
        $this->assertSame(4, $ledger->ordinalFor('item-5'), 'Pages after the delete must not renumber.');
        $this->assertSame(9, $ledger->ordinalFor('item-10'));

        // The freed ordinal is handed to the next new id, so a fresh build
        // still recycles rather than growing the table.
        $appended   = $survivors;
        $appended[] = SyntheticCorpus::item(50, seed: 5);
        $this->buildFresh($appended);

        $ledger = $this->ledger();
        $this->assertSame(3, $ledger->ordinalFor('item-50'), 'A fresh build reuses the freed ordinal.');
        $this->assertSame(10, $ledger->pageTableSize());
        $this->assertSame([], $ledger->tombstones());
    }

    /**
     * The specific mechanism the production loop turned on: the journal is
     * opened in append mode, so a reset that cleared only memory would leave
     * the discarded records in front of the new ones for the next process to
     * replay.
     */
    public function testResetPurgesTheSnapshotAndJournalFromDisk(): void
    {
        $fs     = new FilesystemDriver();
        $ledger = new PageTableLedger($this->stateDir, $fs);
        $ledger->allocate('a', '/a');
        $ledger->allocate('b', '/b');
        $ledger->save();
        $ledger->allocate('c', '/c');
        $ledger->checkpoint();

        $snapshot = $this->stateDir . '/' . PageTableLedger::FILENAME;
        $journal  = $this->stateDir . '/' . PageTableLedger::JOURNAL_FILENAME;
        $this->assertFileExists($snapshot);
        $this->assertFileExists($journal);

        $ledger->reset();

        $this->assertFileDoesNotExist($snapshot, 'reset() must remove the snapshot, not wait for the next save().');
        $this->assertFileDoesNotExist($journal, 'A journal left behind would be replayed over the new assignments.');
        $this->assertTrue($ledger->isEmpty());

        // A process that reads the directory after the reset — the resumed
        // segment of the restarted build — sees nothing to replay, and the
        // reset ledger hands out 0 again.
        $this->assertSame(0, $ledger->allocate('z', '/z'));
        $ledger->checkpoint();

        $reloaded = new PageTableLedger($this->stateDir, $fs);
        $this->assertSame(0, $reloaded->ordinalFor('z'));
        $this->assertNull($reloaded->ordinalFor('a'));
        $this->assertNull($reloaded->ordinalFor('b'));
        $this->assertNull($reloaded->ordinalFor('c'));
        $this->assertSame(1, $reloaded->pageTableSize());
    }

    /**
     * reset() leaves the generation counter alone: releaseStaleRows() only
     * needs it to be monotonic, and rewinding it would make a row stamped by a
     * later build look current.
     */
    public function testResetDoesNotRewindTheGenerationCounter(): void
    {
        $ledger = new PageTableLedger($this->stateDir, new FilesystemDriver());
        $ledger->beginBuild(true);
        $ledger->beginBuild(true);
        $generation = $ledger->generation();
        $this->assertGreaterThan(0, $generation);

        $ledger->reset();

        $this->assertSame($generation, $ledger->generation());
    }

    public function testTheDuplicateOrdinalRefusalNamesTheLedgerFiles(): void
    {
        $chunkDir = $this->stateDir . '/collide';
        mkdir($chunkDir, 0755, true);

        $writer = new ChunkWriter();
        foreach (['alpha', 'beta'] as $i => $id) {
            $writer->write($chunkDir . "/chunk-{$i}.dat", [
                'pages' => [0 => [
                    'id'        => $id,
                    'url'       => "/{$id}",
                    'title'     => ucfirst($id),
                    'content'   => "content for {$id}",
                    'wordCount' => 3,
                    'date'      => '2024-01-01',
                    'filters'   => [],
                    'meta'      => [],
                    'sortable'  => [],
                ]],
                'index' => [],
            ], null);
        }

        $streamWriter = new StreamingFormatWriter(new CborEncoder());
        $streamWriter->beginWrite($this->outputDir);

        $merger = new IndexMerger();
        $merger->setStateDir($this->stateDir);

        try {
            $merger->mergeStreaming(
                [$chunkDir . '/chunk-0.dat', $chunkDir . '/chunk-1.dat'],
                $streamWriter,
            );
            $this->fail('The merge must refuse two chunks that claim the same ordinal.');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Duplicate page ordinal 0 across chunks', $message);
            $this->assertStringContainsString('--restart', $message);
            $this->assertStringContainsString(
                $this->stateDir . '/' . PageTableLedger::JOURNAL_FILENAME,
                $message,
                'An operator told to re-run needs to know which file holds the state being discarded.',
            );
        }
    }
}
