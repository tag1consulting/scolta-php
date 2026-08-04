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
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * The gate. A fast path that cannot be checked against an obvious one is
 * decoration, so every operation the updater supports is asserted against what
 * a full rebuild of the same logical state produces.
 *
 * Byte identity is the assertion, not search equivalence, and it is only
 * meaningful because the full build reads the same ordinal ledger. Without
 * that, the two would number their pages differently and nothing stronger than
 * "the same queries return the same results" could be claimed.
 */
#[CoversClass(IncrementalIndexUpdater::class)]
final class IncrementalDifferentialTest extends TestCase
{
    private string $incrementalState;
    private string $incrementalOut;
    private string $fullState;
    private string $fullOut;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/scolta-diff-' . uniqid();
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
    private function fullBuild(string $state, string $out, array $items): void
    {
        $orchestrator = new IndexBuildOrchestrator($state, $out);
        $result       = $orchestrator->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
        );
        $this->assertTrue($result->success, 'Full build failed: ' . ($result->error ?? ''));
    }

    private function updater(): IncrementalIndexUpdater
    {
        return new IncrementalIndexUpdater($this->incrementalState, $this->incrementalOut);
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

    /**
     * Seed both trees from the same corpus so the incremental side starts from
     * a real index and the full side has a matching ledger to number against.
     *
     * @param list<ContentItem> $items
     */
    private function seedBoth(array $items): void
    {
        $this->fullBuild($this->incrementalState, $this->incrementalOut, $items);
        $this->fullBuild($this->fullState, $this->fullOut, $items);
        $this->assertSame(
            self::manifest($this->incrementalOut),
            self::manifest($this->fullOut),
            'Two full builds of the same corpus must already agree.',
        );
    }

    /**
     * Rebuild the reference tree over $items, reusing its ledger so both sides
     * number pages the same way.
     *
     * @param list<ContentItem> $items
     */
    private function rebuildReference(array $items): void
    {
        $this->fullBuild($this->fullState, $this->fullOut, $items);
    }

    private function assertTreesMatch(string $because): void
    {
        $this->assertSame(self::manifest($this->fullOut), self::manifest($this->incrementalOut), $because);
    }

    // ── The four operations ────────────────────────────────────────────────

    public function testEditInPlaceMatchesAFullRebuild(): void
    {
        $items = SyntheticCorpus::generate(80, seed: 5);
        $this->seedBoth($items);

        $edited     = $items;
        $edited[40] = SyntheticCorpus::item(41, seed: 5, revision: 1);

        $updater = $this->updater();
        $updater->stageUpsert($edited[40]);
        $result = $updater->commit();

        $this->assertSame(1, $result->pagesUpdated);
        $this->assertGreaterThan(0, $result->chunksRewritten);

        $this->rebuildReference($edited);
        $this->assertTreesMatch('An in-place edit must produce what a full rebuild produces.');
    }

    public function testAppendMatchesAFullRebuild(): void
    {
        $items = SyntheticCorpus::generate(80, seed: 5);
        $this->seedBoth($items);

        $appended   = $items;
        $appended[] = SyntheticCorpus::item(81, seed: 5);

        $updater = $this->updater();
        $updater->stageUpsert($appended[80]);
        $updater->commit();

        $this->rebuildReference($appended);
        $this->assertTreesMatch('An append must produce what a full rebuild produces.');
    }

    public function testDeleteMatchesAFullRebuildThatSawTheSameDeletion(): void
    {
        $items = SyntheticCorpus::generate(80, seed: 5);
        $this->seedBoth($items);

        $survivors = array_values(array_filter(
            $items,
            static fn(ContentItem $i): bool => $i->id !== 'item-41',
        ));

        $updater = $this->updater();
        $updater->stageDelete('item-41');
        $result = $updater->commit();

        $this->assertSame(1, $result->pagesDeleted);
        $this->assertGreaterThan(0.0, $result->tombstoneRatio);

        // The full build tombstones the same ordinal, because it reads the same
        // ledger and releases ids it no longer sees. Both sides therefore keep
        // a dense page table with one dead row, and byte identity still holds.
        $this->rebuildReference($survivors);
        $this->assertTreesMatch('A delete must produce what a full rebuild of the survivors produces.');
    }

    public function testDeleteThenAppendReusesTheFreedOrdinalAndStillMatches(): void
    {
        $items = SyntheticCorpus::generate(80, seed: 5);
        $this->seedBoth($items);

        $survivors = array_values(array_filter($items, static fn(ContentItem $i): bool => $i->id !== 'item-41'));
        $fresh     = SyntheticCorpus::item(81, seed: 5);

        // The guarantee is that the two paths agree when both observe the same
        // SEQUENCE of corpus states, not merely the same final state. A single
        // rebuild jumping straight to the end cannot know a delete preceded the
        // append: it allocates the new page a fresh ordinal during the stream
        // and only discovers the deletion afterwards, so it ends with 82 rows
        // and a tombstone where the incremental path ends with 81 and none.
        // Both are correct indexes of the same content; they are not the same
        // bytes, and asserting otherwise would be asserting something false.
        $updater = $this->updater();
        $updater->stageDelete('item-41');
        $updater->commit();
        $this->rebuildReference($survivors);
        $this->assertTreesMatch('The delete step must match a rebuild of that state.');

        $updater = $this->updater();
        $updater->stageUpsert($fresh);
        $updater->commit();

        $ledger = new PageTableLedger($this->incrementalState, new FilesystemDriver());
        $this->assertSame(40, $ledger->ordinalFor('item-81'), 'Freed ordinal must be reused.');
        $this->assertSame([], $ledger->tombstones());

        $after   = $survivors;
        $after[] = $fresh;
        $this->rebuildReference($after);
        $this->assertTreesMatch('The append step must match a rebuild of that state.');
    }

    public function testUrlChangeRenamesTheFragmentAndLeavesNoOrphan(): void
    {
        $items = SyntheticCorpus::generate(60, seed: 5);
        $this->seedBoth($items);

        $before = count(glob($this->incrementalOut . '/pagefind/fragment/*.pf_fragment') ?: []);

        $moved = $items[30]->cloneWith(['url' => '/moved/somewhere-else']);
        $updater = $this->updater();
        $updater->stageUpsert($moved);
        $updater->commit();

        $after = count(glob($this->incrementalOut . '/pagefind/fragment/*.pf_fragment') ?: []);
        $this->assertSame($before, $after, 'A url change must rename the fragment, not add one.');

        $changed     = $items;
        $changed[30] = $moved;
        $this->rebuildReference($changed);
        $this->assertTreesMatch('A url change must produce what a full rebuild produces.');
    }

    // ── The property the whole design turns on ─────────────────────────────

    public function testChangingOnlyPostingsRenamesNoIndexChunk(): void
    {
        // A corpus whose vocabulary is closed: every page draws from the same
        // small word pool, so editing a page changes which pages a term points
        // at without adding or removing any term.
        $items = [];
        for ($i = 1; $i <= 40; $i++) {
            $items[] = self::closedVocabularyPage($i, 0);
        }
        $this->seedBoth($items);

        $namesBefore = array_map('basename', glob($this->incrementalOut . '/pagefind/index/*.pf_index') ?: []);
        sort($namesBefore);

        $updater = $this->updater();
        $updater->stageUpsert(self::closedVocabularyPage(20, 1));
        $updater->commit();

        $namesAfter = array_map('basename', glob($this->incrementalOut . '/pagefind/index/*.pf_index') ?: []);
        sort($namesAfter);

        $this->assertSame(
            $namesBefore,
            $namesAfter,
            'Changing which pages appear in a posting list must rename no chunk: the filename '
            . 'hashes the word list, and the word list did not change.',
        );
    }

    /**
     * Every page uses the same closed vocabulary, in a different order.
     */
    private static function closedVocabularyPage(int $i, int $revision): ContentItem
    {
        $pool  = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel'];
        $words = [];
        for ($w = 0; $w < 120; $w++) {
            $words[] = $pool[($i * 7 + $w * 3 + $revision * 5) % count($pool)];
        }

        return new ContentItem(
            id: "item-{$i}",
            title: "Page {$i}",
            bodyHtml: '<p>' . implode(' ', $words) . '</p>',
            url: "/p/{$i}",
            date: '2025-01-01',
            siteName: 'Closed',
            filters: ['bucket' => (string) ($i % 4)],
        );
    }

    // ── Refusals ───────────────────────────────────────────────────────────

    public function testRefusesWhenThereIsNoIndexToUpdate(): void
    {
        $this->expectException(IncrementalUpdateUnavailable::class);
        $this->expectExceptionMessage('Run a full build first');

        $updater = $this->updater();
        $updater->stageUpsert(SyntheticCorpus::item(1, seed: 5));
        $updater->commit();
    }

    public function testCommitWithNothingStagedIsANoOp(): void
    {
        $this->seedBoth(SyntheticCorpus::generate(20, seed: 5));
        $before = self::manifest($this->incrementalOut);

        $result = $this->updater()->commit();

        $this->assertSame(0, $result->pagesUpdated);
        $this->assertSame(0, $result->pagesDeleted);
        $this->assertSame($before, self::manifest($this->incrementalOut));
    }

    public function testIsAvailableReflectsWhetherAnIndexAndLedgerExist(): void
    {
        $this->assertFalse($this->updater()->isAvailable());

        $this->seedBoth(SyntheticCorpus::generate(20, seed: 5));

        $this->assertTrue($this->updater()->isAvailable());
    }
}
