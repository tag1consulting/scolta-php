<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;

#[CoversClass(PageTableLedger::class)]
final class PageTableLedgerTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-ordinal-' . uniqid();
        mkdir($this->stateDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->stateDir)) {
            rmdir($this->stateDir);
        }
    }

    private function ledger(): PageTableLedger
    {
        return new PageTableLedger($this->stateDir, new FilesystemDriver());
    }

    public function testAllocatesDenseOrdinalsFromZero(): void
    {
        $l = $this->ledger();

        $this->assertTrue($l->isEmpty());
        $this->assertSame(0, $l->allocate('a', '/a'));
        $this->assertSame(1, $l->allocate('b', '/b'));
        $this->assertSame(2, $l->allocate('c', '/c'));
        $this->assertSame(3, $l->pageTableSize());
        $this->assertFalse($l->isEmpty());
    }

    public function testAllocateIsIdempotentForAKnownId(): void
    {
        $l = $this->ledger();
        $this->assertSame(0, $l->allocate('a', '/a'));
        $this->assertSame(0, $l->allocate('a', '/a'));
        $this->assertSame(1, $l->pageTableSize(), 'Re-allocating must not grow the table.');
    }

    public function testUrlChangeAtAStableOrdinalIsRecorded(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/old');

        $this->assertSame('/old', $l->urlFor('a'));
        $this->assertSame(0, $l->allocate('a', '/new'), 'Ordinal must not move on a url change.');
        $this->assertSame('/new', $l->urlFor('a'));
    }

    public function testReleaseFreesTheOrdinalAndTheNextAllocationReusesIt(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/a');
        $l->allocate('b', '/b');
        $l->allocate('c', '/c');

        $this->assertSame(1, $l->release('b'));
        $this->assertNull($l->ordinalFor('b'));
        $this->assertSame([1], $l->tombstones());

        // The freed ordinal is reused rather than growing the page table.
        $this->assertSame(1, $l->allocate('d', '/d'));
        $this->assertSame(3, $l->pageTableSize());
        $this->assertSame([], $l->tombstones(), 'Reuse must clear the tombstone.');
    }

    public function testReleasingAnUnknownIdIsANoOp(): void
    {
        $l = $this->ledger();
        $this->assertNull($l->release('nope'));
    }

    public function testPageTableStaysDenseAcrossDeletes(): void
    {
        $l = $this->ledger();
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $l->allocate($id, "/{$id}");
        }
        $l->release('b');
        $l->release('c');

        // Dense: pf_meta[1] still has four rows, two of them tombstones, so
        // nothing downstream needs a hole case.
        $this->assertSame(4, $l->pageTableSize());
        $this->assertSame(2, $l->liveCount());
        $this->assertEqualsCanonicalizing([1, 2], $l->tombstones());
        $this->assertSame(0.5, $l->tombstoneRatio());
    }

    public function testTombstoneRatioIsZeroOnAnEmptyLedger(): void
    {
        $this->assertSame(0.0, $this->ledger()->tombstoneRatio());
    }

    public function testAssignmentsSurviveAReload(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/a');
        $l->allocate('b', '/b');
        $l->release('a');
        $l->save();

        $reloaded = $this->ledger();
        $this->assertSame(1, $reloaded->ordinalFor('b'));
        $this->assertNull($reloaded->ordinalFor('a'));
        $this->assertSame([0], $reloaded->tombstones());
        $this->assertSame(2, $reloaded->pageTableSize());

        // And the free list survived, so the next id reuses ordinal 0.
        $this->assertSame(0, $reloaded->allocate('c', '/c'));
    }

    public function testSaveIsANoOpWhenNothingChanged(): void
    {
        $l = $this->ledger();
        $l->save();

        $this->assertFileDoesNotExist($this->stateDir . '/' . PageTableLedger::FILENAME);
    }

    public function testResetDiscardsEverythingSoTheNextBuildRenumbersFromZero(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/a');
        $l->allocate('b', '/b');
        $l->release('a');

        $l->reset();

        $this->assertTrue($l->isEmpty());
        $this->assertSame(0, $l->pageTableSize());
        $this->assertSame([], $l->tombstones());
        $this->assertSame(0, $l->allocate('b', '/b'), 'Compaction renumbers from zero.');
    }

    public function testCarriesFiltersAndSortableForTheWholeCorpusArtifacts(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/a', ['topic' => 'PHP'], ['date' => '2025-01-01']);
        $l->allocate('b', '/b', ['topic' => 'Drupal'], ['date' => '2024-06-02']);

        $this->assertSame(['topic' => 'PHP'], $l->filtersFor('a'));
        $this->assertSame(['date' => '2024-06-02'], $l->sortableFor('b'));
        $this->assertSame([], $l->filtersFor('missing'));
    }

    public function testReallocatingUpdatesFiltersAndSortableWithoutMovingTheOrdinal(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/a', ['topic' => 'PHP'], ['date' => '2025-01-01']);

        $this->assertSame(0, $l->allocate('a', '/a', ['topic' => 'Drupal'], ['date' => '2025-09-09']));
        $this->assertSame(['topic' => 'Drupal'], $l->filtersFor('a'));
        $this->assertSame(['date' => '2025-09-09'], $l->sortableFor('a'));
    }

    public function testRowsByOrdinalIsAscendingAndSkipsTombstones(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/a', ['t' => '1'], []);
        $l->allocate('b', '/b', ['t' => '2'], []);
        $l->allocate('c', '/c', ['t' => '3'], []);
        $l->release('b');

        $rows = $l->rowsByOrdinal();

        $this->assertSame([0, 2], array_keys($rows), 'Ordinal order, tombstone absent.');
        $this->assertSame('a', $rows[0]['id']);
        $this->assertSame(['t' => '3'], $rows[2]['filters']);
    }

    public function testFiltersAndSortableSurviveAReload(): void
    {
        $l = $this->ledger();
        $l->allocate('a', '/a', ['topic' => 'PHP'], ['date' => '2025-01-01']);
        $l->save();

        $this->assertSame(['topic' => 'PHP'], $this->ledger()->filtersFor('a'));
        $this->assertSame(['date' => '2025-01-01'], $this->ledger()->sortableFor('a'));
    }

    /**
     * PHP stores a decimal-integer string array key as an int, so a ledger
     * holding the id '42' — an ordinary Drupal node id — hands `int(42)` back
     * from `array_keys()`. Passing that to `release(string $id)` under
     * strict_types is a TypeError, not a coercion, so before the fix this
     * threw partway through the prune and the full build died with no index.
     *
     * Every id in this file's other tests is 'a', 'b' or 'id-3', which is
     * exactly why the class had full delete coverage and still shipped this.
     */
    public function testReleaseAllExceptHandlesAPurelyNumericId(): void
    {
        $l = $this->ledger();
        $l->allocate('42', 'https://example.com/node/42');

        $released = $l->releaseAllExcept([]);

        $this->assertSame([0], $released, 'The numeric id\'s ordinal must come back released.');
        $this->assertNull($l->ordinalFor('42'), 'And it must lose its assignment.');
        $this->assertSame([0], $l->tombstones());
        $this->assertSame(1.0, $l->tombstoneRatio());
        $this->assertSame(0, $l->liveCount());
    }

    /**
     * The mixed case is the one a multilingual Drupal site produces: the
     * default-language item is keyed '42' (stored as int) and each translation
     * is '42-es' (stored as a string). Both must release in one pass.
     */
    public function testReleaseAllExceptHandlesNumericAndSuffixedIdsTogether(): void
    {
        $l = $this->ledger();
        $l->allocate('42', 'https://example.com/node/42');
        $l->allocate('42-es', 'https://example.com/es/node/42');
        $l->allocate('43', 'https://example.com/node/43');

        $released = $l->releaseAllExcept([]);

        $this->assertEqualsCanonicalizing([0, 1, 2], $released);
        $this->assertSame(0, $l->liveCount());
        $this->assertSame(3, $l->pageTableSize(), 'Releasing must tombstone, never shrink.');
    }

    /**
     * The seen-set side of the same normalization: a numeric id the build did
     * see must survive the prune. Both `array_fill_keys()` and the isset()
     * subscript normalize identically, so this holds — assert it rather than
     * assume it, because the whole bug was an asymmetry nobody assumed either.
     */
    public function testReleaseAllExceptKeepsANumericIdThatWasSeen(): void
    {
        $l = $this->ledger();
        $l->allocate('42', '/a');
        $l->allocate('43', '/b');
        $l->allocate('44-fr', '/c');

        $released = $l->releaseAllExcept(['42', '44-fr']);

        $this->assertSame([1], $released, 'Only the unseen id releases.');
        $this->assertSame(0, $l->ordinalFor('42'));
        $this->assertSame(2, $l->ordinalFor('44-fr'));
        $this->assertNull($l->ordinalFor('43'));
    }

    /** The map form of $seenIds normalizes its keys the same way. */
    public function testReleaseAllExceptAcceptsANumericIdInTheMapFormOfSeenIds(): void
    {
        $l = $this->ledger();
        $l->allocate('42', '/a');
        $l->allocate('43', '/b');

        $this->assertSame([1], $l->releaseAllExcept(['42' => true]));
        $this->assertSame(0, $l->ordinalFor('42'));
    }

    /**
     * rowsByOrdinal() declares `id: string` and fed an int for a numeric id
     * before the fix. Nothing reads that field today, which is the only reason
     * it was a latent type lie rather than a second crash.
     */
    public function testRowsByOrdinalReturnsNumericIdsAsStrings(): void
    {
        $l = $this->ledger();
        $l->allocate('42', '/a');
        $l->allocate('42-es', '/b');

        $rows = $l->rowsByOrdinal();

        $this->assertSame('42', $rows[0]['id']);
        $this->assertSame('42-es', $rows[1]['id']);
    }

    /** The int key survives serialization, so a reloaded ledger prunes too. */
    public function testANumericIdStillReleasesAfterAReload(): void
    {
        $l = $this->ledger();
        $l->allocate('42', '/a');
        $l->save();

        $reloaded = $this->ledger();
        $this->assertSame([0], $reloaded->releaseAllExcept([]));
        $this->assertSame([0], $reloaded->tombstones());
    }

    /**
     * Delete-then-append must reuse the freed ordinal rather than extend the
     * table, or a site that churns content grows an unbounded page table and
     * every full rebuild gets slower forever.
     */
    public function testDeleteThenAppendReusesRatherThanGrows(): void
    {
        $l = $this->ledger();
        for ($i = 0; $i < 10; $i++) {
            $l->allocate("id-{$i}", "/p{$i}");
        }

        for ($cycle = 0; $cycle < 5; $cycle++) {
            $l->release('id-3');
            $this->assertSame(3, $l->allocate('id-3', '/p3'));
        }

        $this->assertSame(10, $l->pageTableSize());
        $this->assertSame(10, $l->liveCount());
        $this->assertSame(0.0, $l->tombstoneRatio());
    }
}
