<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\ChangeSet;
use Tag1\Scolta\Index\ChangeSetPlanner;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\TimestampManifest;
use Tag1\Scolta\Storage\FilesystemDriver;

#[CoversClass(ChangeSetPlanner::class)]
#[CoversClass(ChangeSet::class)]
final class ChangeSetPlannerTest extends TestCase
{
    private string $stateDir;
    private TimestampManifest $manifest;
    private PageTableLedger $ledger;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-planner-' . uniqid('', true);
        mkdir($this->stateDir, 0755, true);
        $storage        = new FilesystemDriver();
        $this->manifest = new TimestampManifest($this->stateDir, $storage);
        $this->ledger   = new PageTableLedger($this->stateDir, $storage);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->stateDir)) {
            rmdir($this->stateDir);
        }
    }

    /**
     * An index of $count entities, one page each, all recorded at $ts.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function seed(int $count, int $ts = 1000): array
    {
        $published = [];
        for ($i = 1; $i <= $count; $i++) {
            $key = 'entity-' . $i;
            $this->manifest->put($key, $ts, [['hash' => 'h' . $i]]);
            $this->ledger->allocate($key, '/page-' . $i);
            $published[] = [$key, $ts];
        }

        return $published;
    }

    /** One page per entity, which is what most adapters do. */
    private function planner(int $threshold = 1_000): ChangeSetPlanner
    {
        return new ChangeSetPlanner(
            $this->manifest,
            $this->ledger,
            static fn(string $key): array => [$key],
            $threshold,
        );
    }

    public function testNothingChangedRoutesToNone(): void
    {
        $published = $this->seed(20);

        $plan = $this->planner()->plan($published);

        $this->assertSame(ChangeSet::ROUTE_NONE, $plan->route());
        $this->assertTrue($plan->isEmpty());
        $this->assertSame([], $plan->upsertEntityKeys);
        $this->assertSame([], $plan->deleteItemIds);
        $this->assertSame(20, $plan->unchangedCount);
        $this->assertSame(0, $plan->changedCount());
    }

    public function testAFewChangesRouteToIncremental(): void
    {
        $published = $this->seed(20);

        // Two entities edited, one brand new.
        $published[3][1]  = 2000;
        $published[11][1] = 2000;
        $published[]      = ['entity-99', 2000];

        $plan = $this->planner()->plan($published);

        $this->assertSame(ChangeSet::ROUTE_INCREMENTAL, $plan->route());
        $this->assertFalse($plan->isEmpty());
        $this->assertSame(['entity-4', 'entity-12', 'entity-99'], $plan->upsertEntityKeys);
        $this->assertSame([], $plan->deleteItemIds);
        $this->assertSame(18, $plan->unchangedCount);
        $this->assertSame(3, $plan->changedCount());
    }

    public function testEnoughChangesRouteToFull(): void
    {
        $published = $this->seed(20);
        foreach ($published as $i => $_) {
            $published[$i][1] = 2000;
        }

        $plan = $this->planner(threshold: 10)->plan($published);

        $this->assertSame(ChangeSet::ROUTE_FULL, $plan->route());
        $this->assertCount(20, $plan->upsertEntityKeys);
        $this->assertSame(0, $plan->unchangedCount);
    }

    public function testTheThresholdIsInclusive(): void
    {
        $published = $this->seed(20);
        for ($i = 0; $i < 5; $i++) {
            $published[$i][1] = 2000;
        }

        $this->assertSame(ChangeSet::ROUTE_FULL, $this->planner(threshold: 5)->plan($published)->route());
        $this->assertSame(ChangeSet::ROUTE_INCREMENTAL, $this->planner(threshold: 6)->plan($published)->route());
    }

    public function testAThresholdOfZeroNeverRoutesToFull(): void
    {
        $published = $this->seed(20);
        foreach ($published as $i => $_) {
            $published[$i][1] = 2000;
        }

        $this->assertSame(ChangeSet::ROUTE_INCREMENTAL, $this->planner(threshold: 0)->plan($published)->route());
    }

    public function testAnEntityMissingFromTheInputIsPlannedForDeletion(): void
    {
        $published = $this->seed(10);

        // entity-4 is no longer published.
        unset($published[3]);
        $published = array_values($published);

        $plan = $this->planner()->plan($published);

        $this->assertSame(ChangeSet::ROUTE_INCREMENTAL, $plan->route());
        $this->assertSame([], $plan->upsertEntityKeys);
        $this->assertSame(['entity-4'], $plan->deleteItemIds);
        $this->assertSame(9, $plan->unchangedCount);
    }

    public function testWithNoIndexEveryChangeRoutesToFull(): void
    {
        // Nothing indexed yet: there is nothing for the incremental path to
        // apply changes to, so routing it there would hand the adapter a plan
        // that can only throw.
        $plan = $this->planner()->plan([['entity-1', 1000], ['entity-2', 1000]]);

        $this->assertSame(ChangeSet::ROUTE_FULL, $plan->route());
        $this->assertCount(2, $plan->upsertEntityKeys);
        $this->assertSame([], $plan->deleteItemIds);
    }

    public function testWithNoIndexAndNoContentThereIsNothingToDo(): void
    {
        $plan = $this->planner()->plan([]);

        $this->assertSame(ChangeSet::ROUTE_NONE, $plan->route());
        $this->assertTrue($plan->isEmpty());
    }

    public function testATimestampThatWentBackwardsCountsAsChanged(): void
    {
        // A restored revision or a botched migration leaves the source older
        // than the index. Treating that as unchanged is how an index quietly
        // stops reflecting the site.
        $published        = $this->seed(5, ts: 2000);
        $published[2][1]  = 1000;

        $plan = $this->planner()->plan($published);

        $this->assertSame(['entity-3'], $plan->upsertEntityKeys);
    }

    public function testAnEntityOwningSeveralItemIdsOnlyLosesTheOnesThatWent(): void
    {
        // A node with translations is several pages under one entity key. The
        // planner cannot subtract one key space from the other, so it asks the
        // adapter — and a convention applied wrongly here would delete the
        // translations of every entity that is still published.
        $this->manifest->put('node-1', 1000, [['hash' => 'a']]);
        $this->manifest->put('node-2', 1000, [['hash' => 'b']]);
        $this->ledger->allocate('node-1:en', '/en/1');
        $this->ledger->allocate('node-1:fr', '/fr/1');
        $this->ledger->allocate('node-2:en', '/en/2');
        $this->ledger->allocate('node-2:fr', '/fr/2');

        $planner = new ChangeSetPlanner(
            $this->manifest,
            $this->ledger,
            static fn(string $key): array => [$key . ':en', $key . ':fr'],
        );

        // node-2 is unpublished; node-1 stays.
        $plan = $planner->plan([['node-1', 1000]]);

        $this->assertSame([], $plan->upsertEntityKeys);
        $this->assertSame(['node-2:en', 'node-2:fr'], $plan->deleteItemIds);
        $this->assertSame(1, $plan->unchangedCount);
    }

    public function testATranslationRemovedFromAStillPublishedEntityIsNotDeleted(): void
    {
        // The convention is the adapter's claim about what an entity owns, not
        // an observation. An entity that is still published keeps every id the
        // convention says it owns, even one the index happens not to hold.
        $this->manifest->put('node-1', 1000, [['hash' => 'a']]);
        $this->ledger->allocate('node-1:en', '/en/1');

        $planner = new ChangeSetPlanner(
            $this->manifest,
            $this->ledger,
            static fn(string $key): array => [$key . ':en', $key . ':fr'],
        );

        $plan = $planner->plan([['node-1', 1000]]);

        $this->assertSame([], $plan->deleteItemIds);
        $this->assertSame(ChangeSet::ROUTE_NONE, $plan->route());
    }

    public function testPlanningReadsAGeneratorOnce(): void
    {
        // Adapters stream this from a database query, so the planner must not
        // need a second pass over the input.
        $published = $this->seed(6);

        $generator = (static function () use ($published): \Generator {
            foreach ($published as $row) {
                yield $row;
            }
        })();

        $plan = $this->planner()->plan($generator);

        $this->assertSame(ChangeSet::ROUTE_NONE, $plan->route());
        $this->assertSame(6, $plan->unchangedCount);
    }

    public function testPlanningWritesNothingToTheStateDirectory(): void
    {
        $published = $this->seed(8);
        $published[0][1] = 5000;

        // The manifest and ledger were seeded in memory, so a clean state
        // directory here means the planner performed no I/O of its own.
        foreach (glob($this->stateDir . '/*') ?: [] as $f) {
            unlink($f);
        }

        $this->planner()->plan($published);

        $this->assertSame([], glob($this->stateDir . '/*') ?: [], 'The planner wrote to the state directory.');
    }
}
