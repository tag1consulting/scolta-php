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
use Tag1\Scolta\Storage\StorageDriverInterface;
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
     * A delete that fails has to be loud. The whole point of the reset is that
     * the old assignments are gone; a file that survives it is replayed over
     * the new ones by the next process to read the directory, which is the
     * duplicate-ordinal bug again.
     */
    public function testResetRefusesToRenumberWhenTheJournalCannotBeRemoved(): void
    {
        $real   = new FilesystemDriver();
        $ledger = new PageTableLedger($this->stateDir, $real);
        $ledger->allocate('a', '/a');
        $ledger->save();
        $ledger->allocate('b', '/b');
        $ledger->checkpoint();

        $stuckJournal = new PageTableLedger($this->stateDir, self::storageThatCannotDeleteTheJournal($real));
        $this->assertSame(0, $stuckJournal->ordinalFor('a'), 'Precondition: the snapshot and journal loaded.');
        $this->assertSame(1, $stuckJournal->ordinalFor('b'));

        try {
            $stuckJournal->reset();
            $this->fail('reset() must not report success while a ledger file survives it.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(PageTableLedger::JOURNAL_FILENAME, $e->getMessage());
            $this->assertStringContainsString('Refusing to renumber', $e->getMessage());
        }

        // The in-memory table is untouched, so nothing downstream can act on a
        // half-applied reset: the caller sees the assignments it had.
        $this->assertSame(0, $stuckJournal->ordinalFor('a'));
        $this->assertSame(1, $stuckJournal->ordinalFor('b'));
        $this->assertSame(2, $stuckJournal->pageTableSize());
        $this->assertFalse($stuckJournal->isEmpty());
    }

    /**
     * The operator-visible half of the same guard: the restart fails at the
     * start with the file named, rather than after re-indexing the corpus.
     */
    public function testARestartFailsUpFrontWhenTheLedgerCannotBeDiscarded(): void
    {
        $real = new FilesystemDriver();
        $this->buildFresh(SyntheticCorpus::generate(4, seed: 5));

        // Leave a journal behind for the restart to trip over: the completed
        // build's save() truncated it.
        $ledger = new PageTableLedger($this->stateDir, $real);
        $ledger->allocate('late-arrival', '/late-arrival');
        $ledger->checkpoint();

        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            storage: self::storageThatCannotDeleteTheJournal($real),
        );
        $report = $orchestrator->build(
            BuildIntent::restart(4, MemoryBudget::conservative()),
            SyntheticCorpus::generate(4, seed: 5),
        );

        $this->assertFalse($report->success, 'A restart that cannot discard the ledger must not report success.');
        $this->assertStringContainsString(PageTableLedger::JOURNAL_FILENAME, (string) $report->error);
        $this->assertSame(0, $report->pagesProcessed, 'It must fail before indexing, not after.');
    }

    /**
     * A driver that refuses to unlink the journal — an unwritable state
     * directory, or an NFS mount that reports success without removing it.
     */
    private static function storageThatCannotDeleteTheJournal(FilesystemDriver $inner): StorageDriverInterface
    {
        return new class ($inner) implements StorageDriverInterface {
            public function __construct(private readonly FilesystemDriver $inner) {}

            public function delete(string $path): bool
            {
                return str_ends_with($path, PageTableLedger::JOURNAL_FILENAME)
                    ? false
                    : $this->inner->delete($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }

            public function get(string $path): string
            {
                return $this->inner->get($path);
            }

            public function put(string $path, string $contents): bool
            {
                return $this->inner->put($path, $contents);
            }

            public function deleteDirectory(string $path): bool
            {
                return $this->inner->deleteDirectory($path);
            }

            public function makeDirectory(string $path): bool
            {
                return $this->inner->makeDirectory($path);
            }

            public function move(string $from, string $to): bool
            {
                return $this->inner->move($from, $to);
            }

            public function files(string $directory, string $pattern = '*'): array
            {
                return $this->inner->files($directory, $pattern);
            }
        };
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
