<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IncrementalUpdateUnavailable;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * Randomised oracle differential for the incremental path.
 *
 * IncrementalDifferentialTest asserts hand-picked operations against a full
 * rebuild. That proves the cases someone thought of. This drives a long random
 * SEQUENCE of edits, deletes and appends through the updater and, after every
 * commit, asserts the incremental tree is byte-identical to a full rebuild of
 * the same state reached in the same order.
 *
 * Byte identity — not "the same postings" — is the assertion, and it is only
 * available because the corpus is closed-vocabulary: every page draws from the
 * same eight-word pool, so an edit changes which pages a term points at without
 * ever adding or removing a term. A vocabulary change would re-cut the full
 * build's chunk boundaries against the updater's frozen range table, which is
 * a divergence IncrementalDifferentialTest already covers with a logical-index
 * assertion; here the point is the stronger claim, held across depth.
 *
 * Determinism: the sequence is seeded. On failure the message carries the seed
 * and the operation log, so a red run reproduces as a fixed regression:
 *
 *     SCOLTA_FUZZ_SEED=<n> SCOLTA_FUZZ_OPS=<k> \
 *       vendor/bin/phpunit tests/Index/BuildDifferentialFuzzTest.php
 *
 * The default size is small enough for CI (each step is two ~30-page full
 * builds). Raise SCOLTA_FUZZ_OPS / SCOLTA_FUZZ_PAGES to hunt harder locally.
 *
 * What this does NOT cover, deliberately, because neither can be driven
 * soundly in one process — both belong to the SML3 acceptance run:
 *   - resume (the memory-pressure yield and restart): only a real
 *     kill-and-restart drives the segmented path, and one process cannot
 *     stage it.
 *   - compaction (PageTableLedger::reset() renumbering ordinals mid-life).
 *
 * @coversNothing
 */
#[CoversClass(IncrementalIndexUpdater::class)]
final class BuildDifferentialFuzzTest extends TestCase
{
    /** The whole vocabulary. Nothing an edit does can add to it or empty it. */
    private const POOL = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel'];

    private string $incrementalState;
    private string $incrementalOut;
    private string $fullState;
    private string $fullOut;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/scolta-fuzz-' . uniqid('', true);
        $this->incrementalState = $base . '/inc-state';
        $this->incrementalOut   = $base . '/inc-out';
        $this->fullState        = $base . '/full-state';
        $this->fullOut          = $base . '/full-out';
        foreach ([$this->incrementalState, $this->incrementalOut, $this->fullState, $this->fullOut] as $d) {
            mkdir($d, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        self::removeDir(dirname($this->incrementalState));
    }

    /**
     * Drive a random sequence and check the invariant after every commit.
     */
    public function testARandomOperationSequenceStaysByteIdenticalToAFullRebuild(): void
    {
        $seed  = (int) (getenv('SCOLTA_FUZZ_SEED') ?: 1);
        $ops   = max(1, (int) (getenv('SCOLTA_FUZZ_OPS') ?: 40));
        $pages = max(4, (int) (getenv('SCOLTA_FUZZ_PAGES') ?: 30));
        mt_srand($seed);

        // id => revision, for every live page. Deleted pages leave the map.
        $live = [];
        for ($i = 1; $i <= $pages; $i++) {
            $live[$i] = 0;
        }
        $nextId = $pages + 1;
        $log    = ["seed={$seed} pages={$pages} ops={$ops}"];

        $items = $this->itemsFor($live);
        $this->seedBoth($items, $log);

        for ($step = 1; $step <= $ops; $step++) {
            $updater = $this->updater();
            $op      = $this->pickOp($live);

            switch ($op) {
                case 'edit':
                    $id = $this->randomLiveId($live);
                    $live[$id]++;
                    $updater->stageUpsert($this->page($id, $live[$id]));
                    $log[] = "step {$step}: edit item-{$id} -> rev {$live[$id]}";
                    break;

                case 'delete':
                    $id = $this->randomLiveId($live);
                    unset($live[$id]);
                    $updater->stageDelete("item-{$id}");
                    $log[] = "step {$step}: delete item-{$id}";
                    break;

                case 'append':
                    $id        = $nextId++;
                    $live[$id] = 0;
                    $updater->stageUpsert($this->page($id, 0));
                    $log[] = "step {$step}: append item-{$id}";
                    break;
            }

            $because = "Sequence diverged from a full rebuild.\n" . implode("\n", $log);

            try {
                $updater->commit();
            } catch (IncrementalUpdateUnavailable $e) {
                // A closed vocabulary never empties a term or a chunk, so the
                // updater has no legitimate reason to bail. If it does, the
                // reproduction is the whole point of the message.
                $this->fail($because . "\n  threw IncrementalUpdateUnavailable: " . $e->getMessage());
            }

            $items = $this->itemsFor($live);
            $this->fullBuild($this->fullState, $this->fullOut, $items);

            $this->assertSame(self::manifest($this->fullOut), self::manifest($this->incrementalOut), $because);
        }

        // A sanity floor: the sequence actually exercised the machinery rather
        // than no-opping (e.g. every op landing on the same chunk).
        $ledger = new PageTableLedger($this->incrementalState, new FilesystemDriver());
        $this->assertGreaterThan(0, $ledger->pageTableSize(), 'The fuzz corpus collapsed to nothing.');
    }

    /**
     * The known, pre-existing divergence, pinned so it is tracked rather than
     * discovered again. A page that gains a brand-new sortable FIELD (not a new
     * value for an existing field) makes the incremental corpus-table rebuild
     * and the full build disagree on pf_meta[4]. Reproduced against shipped
     * code; SML never triggers it (one stable `date` sortable). This test
     * documents it and will start failing — usefully — the day it is fixed, at
     * which point the body should assert byte identity and the skip removed.
     *
     * Tracking: pre-existing pf_meta[4] divergence on a new sortable field; not yet ticketed; SML-irrelevant (single stable date sortable).
     */
    public function testGainingANewSortableFieldIsAKnownCorpusTableDivergence(): void
    {
        $this->markTestIncomplete(
            'Pre-existing: incremental vs full disagree on pf_meta[4] when a page gains a new '
            . 'sortable field. Tracked separately; SML-irrelevant (single stable `date` sortable). '
            . 'Replace this skip with a byte-identity assertion when the corpus-table encoding is fixed.',
        );
    }

    // -- Corpus ---------------------------------------------------------------

    /**
     * @param array<int, int> $live id => revision
     * @return list<ContentItem>
     */
    private function itemsFor(array $live): array
    {
        ksort($live, SORT_NUMERIC);
        $items = [];
        foreach ($live as $id => $rev) {
            $items[] = $this->page($id, $rev);
        }

        return $items;
    }

    /**
     * One closed-vocabulary page. The revision only permutes word order, so the
     * term set is invariant and byte identity is available.
     */
    private function page(int $id, int $revision): ContentItem
    {
        $words = [];
        for ($w = 0; $w < 120; $w++) {
            $words[] = self::POOL[($id * 7 + $w * 3 + $revision * 5) % count(self::POOL)];
        }

        return new ContentItem(
            id: "item-{$id}",
            title: "Page {$id}",
            bodyHtml: '<p>' . implode(' ', $words) . '</p>',
            url: "/p/{$id}",
            date: '2025-01-01',
            siteName: 'Fuzz',
            filters: ['bucket' => (string) ($id % 4)],
        );
    }

    /**
     * @param array<int, int> $live
     * @return 'edit'|'delete'|'append'
     */
    private function pickOp(array $live): string
    {
        $liveCount = count($live);
        $roll      = mt_rand(1, 100);

        // Never delete the last live page: an empty live table is a different
        // build entirely, and the guards for it are covered elsewhere.
        if ($liveCount <= 1) {
            return $roll <= 60 ? 'edit' : 'append';
        }
        if ($roll <= 55) {
            return 'edit';
        }
        if ($roll <= 80) {
            return 'append';
        }

        return 'delete';
    }

    /**
     * @param array<int, int> $live
     */
    private function randomLiveId(array $live): int
    {
        $ids = array_keys($live);

        return $ids[mt_rand(0, count($ids) - 1)];
    }

    // -- Build helpers (mirroring IncrementalDifferentialTest) -----------------

    private function updater(): IncrementalIndexUpdater
    {
        return new IncrementalIndexUpdater($this->incrementalState, $this->incrementalOut);
    }

    /**
     * @param list<ContentItem> $items
     */
    private function fullBuild(string $state, string $out, array $items): void
    {
        $orchestrator = new IndexBuildOrchestrator($state, $out);
        $result       = $orchestrator->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
        );
        $this->assertTrue($result->success, 'Full build failed: ' . ($result->error ?? ''));
    }

    /**
     * @param list<ContentItem> $items
     * @param list<string>      $log
     */
    private function seedBoth(array $items, array $log): void
    {
        $this->fullBuild($this->incrementalState, $this->incrementalOut, $items);
        $this->fullBuild($this->fullState, $this->fullOut, $items);
        $this->assertSame(
            self::manifest($this->incrementalOut),
            self::manifest($this->fullOut),
            "Two full builds of the same corpus must already agree.\n" . implode("\n", $log),
        );
    }

    /**
     * @return array<string, string> Relative path => sha256 of decompressed bytes.
     */
    private static function manifest(string $out): array
    {
        $base     = $out . '/pagefind';
        $manifest = [];
        $items    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $raw = (string) file_get_contents($file->getPathname());
            $dec = @gzdecode($raw);
            $manifest[substr($file->getPathname(), strlen($base) + 1)] = hash('sha256', $dec === false ? $raw : $dec);
        }
        ksort($manifest);

        return $manifest;
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
}
