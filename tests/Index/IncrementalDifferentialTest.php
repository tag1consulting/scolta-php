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
use Tag1\Scolta\Index\PfIndexCodec;
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

    /**
     * Incremental removal had only ever been exercised for terms in the body.
     * A term living solely in attachment text is the case that breaks if the
     * removal sweep works from a hardcoded bucket list rather than from
     * TextChannel: its postings survive the update and the page goes on
     * matching a word it no longer contains. Tree equality catches that more
     * thoroughly than inspecting one posting would.
     */
    public function testFourOperationsInOneCommitMatchAFullRebuild(): void
    {
        // Two edits, one new page and one delete, committed together, and every
        // one of them written from vocabulary the corpus already has. The
        // byte-level chunk patch has to get all four right in one pass over each
        // touched chunk, and the interesting part is that they interleave: a
        // delete and an insert can land in the same posting run, where one moves
        // a delta down and the other moves it back up.
        //
        // Vocabulary-neutral deliberately. See
        // {@see self::assertSameLogicalIndex()} for why a change that adds or
        // removes a term cannot be asserted byte-identical at all.
        $items = SyntheticCorpus::generate(80, seed: 5);
        $this->seedBoth($items);

        $editA = $items[10]->cloneWith(['bodyHtml' => $items[41]->bodyHtml]);
        $editB = $items[55]->cloneWith(['bodyHtml' => $items[62]->bodyHtml]);
        $added = SyntheticCorpus::item(81, seed: 5)->cloneWith(['bodyHtml' => $items[7]->bodyHtml]);
        $gone  = $items[30];

        $next     = $items;
        $next[10] = $editA;
        $next[55] = $editB;
        unset($next[30]);
        $next   = array_values($next);
        $next[] = $added;

        $updater = $this->updater();
        $updater->stageUpsert($editA);
        $updater->stageUpsert($editB);
        $updater->stageUpsert($added);
        $updater->stageDelete($gone->id);
        $result = $updater->commit();

        $this->assertSame(3, $result->pagesUpdated);
        $this->assertSame(1, $result->pagesDeleted);
        $this->assertGreaterThan(0, $result->chunksRewritten);

        // The reference is rebuilt in the same two steps the commit took, and
        // that is not a technicality. A full build allocates every page it is
        // handed *during* the stream and only calls releaseStaleRows() at the
        // end, so a page deleted at the source frees its ordinal after the new
        // page has already taken a fresh one. The updater deletes first, so the
        // new page reuses the freed ordinal. Both are correct and they number
        // the two pages differently — and an ordinal names its fragment. So the
        // comparison is against a reference that saw the delete and the insert
        // in the same order, which is the strongest statement that is true here.
        $survivors = array_values(array_filter(
            $next,
            static fn(ContentItem $i): bool => $i->id !== $added->id,
        ));
        $this->rebuildReference($survivors);
        $this->rebuildReference($next);
        $this->assertTreesMatch(
            'Two edits, an insert and a delete in one commit must produce what a full rebuild produces.',
        );
    }

    public function testATermThatLeavesTheVocabularyIsRemovedFromItsChunk(): void
    {
        // A page carrying a word no other page has. Editing that word away takes
        // the term out of the chunk entirely, which is the one operation that
        // shrinks a chunk's word list.
        $items   = SyntheticCorpus::generate(40, seed: 9);
        $unique  = 'zzquixotrophic';
        $items[] = SyntheticCorpus::item(41, seed: 9)
            ->cloneWith(['bodyHtml' => '<p>' . $unique . ' appears only here.</p>']);
        $items   = array_values($items);
        $this->seedBoth($items);

        $this->assertTrue(
            $this->indexContainsTerm($unique),
            'The fixture never got the unique term into the index.',
        );

        $replacement = SyntheticCorpus::item(41, seed: 9)
            ->cloneWith(['bodyHtml' => '<p>ordinary words about reading.</p>']);
        $next        = $items;
        $next[40]    = $replacement;

        $updater = $this->updater();
        $updater->stageUpsert($replacement);
        $updater->commit();

        $this->assertFalse(
            $this->indexContainsTerm($unique),
            'The term left the corpus but its postings are still in the index.',
        );

        $this->rebuildReference($next);
        $this->assertSameLogicalIndex('A term leaving the vocabulary');
    }

    public function testANewTermJoinsItsChunk(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 11);
        $this->seedBoth($items);

        $novel       = 'zzflibbertigibbet';
        $replacement = SyntheticCorpus::item(20, seed: 11)
            ->cloneWith(['bodyHtml' => '<p>' . $novel . ' now appears here.</p>']);
        $next        = $items;
        $next[19]    = $replacement;

        $updater = $this->updater();
        $updater->stageUpsert($replacement);
        $updater->commit();

        $this->assertTrue($this->indexContainsTerm($novel), 'A new term did not reach the index.');

        $this->rebuildReference($next);
        $this->assertSameLogicalIndex('A term joining the vocabulary');
    }

    public function testANumericLookingTermIsRoutedAndStoredLikeTheMergeOrderedIt(): void
    {
        // Term order inside a chunk is PHP's standard comparison, the one
        // SplMinHeap used when the chunks were built, and it disagrees with
        // strcmp on numeric-looking terms. A patch that re-sorted with the other
        // one would produce a searchable index a rebuild would not reproduce,
        // rather than an error.
        $items   = SyntheticCorpus::generate(30, seed: 13);
        $items[] = SyntheticCorpus::item(31, seed: 13)
            ->cloneWith(['bodyHtml' => '<p>census 2024 and 9 and 10 and 100 counted.</p>']);
        $items   = array_values($items);
        $this->seedBoth($items);

        $replacement = SyntheticCorpus::item(31, seed: 13)
            ->cloneWith(['bodyHtml' => '<p>census 2024 and 9 and 10 and 100 and 2025 counted.</p>']);
        $next        = $items;
        $next[30]    = $replacement;

        $updater = $this->updater();
        $updater->stageUpsert($replacement);
        $updater->commit();

        $this->rebuildReference($next);
        $this->assertSameLogicalIndex('A numeric-looking term');

        // And every chunk is still internally ordered the way the merge orders
        // terms, which is what keeps the frozen range table a valid cover.
        foreach (glob($this->incrementalOut . '/pagefind/index/*.pf_index') ?: [] as $path) {
            $words  = PfIndexCodec::wordList(PfIndexCodec::splitEntriesFromFile($path));
            $sorted = $words;
            usort($sorted, static fn(string $a, string $b): int => $a <=> $b);
            $this->assertSame($sorted, $words, 'Chunk ' . basename($path) . ' is not in merge order.');
        }
    }

    /**
     * Compare the two trees on content rather than on chunk layout.
     *
     * Byte identity is the right assertion for every operation that leaves the
     * vocabulary alone, and it is not available for one that does not. The
     * updater treats the `pf_meta[2]` range table as frozen on purpose — that is
     * what stops one new term renaming most of the index — while a full rebuild
     * re-cuts chunk boundaries by byte size over whatever vocabulary it is
     * handed. So when a term joins or leaves, the two agree on every posting and
     * disagree on which chunk file carries it. Asserting bytes there would be
     * asserting that the frozen range table does not work.
     *
     * What must still hold exactly: the same terms, each with the same postings,
     * and the same fragments.
     */
    private function assertSameLogicalIndex(string $because): void
    {
        $this->assertSame(
            self::allPostings($this->fullOut),
            self::allPostings($this->incrementalOut),
            $because . ' must leave the same terms and postings as a full rebuild.',
        );
        $this->assertSame(
            self::fragmentHashes($this->fullOut),
            self::fragmentHashes($this->incrementalOut),
            $because . ' must leave the same fragments as a full rebuild.',
        );
    }

    /**
     * Every term in the index with its postings, independent of chunk layout.
     *
     * @return array<string, string>
     */
    private static function allPostings(string $out): array
    {
        $terms = [];
        foreach (glob($out . '/pagefind/index/*.pf_index') ?: [] as $path) {
            foreach (PfIndexCodec::decodeChunkFile($path) as $word => $entry) {
                ksort($entry);
                $terms[(string) $word] = hash('sha256', serialize($entry));
            }
        }
        ksort($terms);

        return $terms;
    }

    /** @return array<string, string> Fragment filename => sha256 of its decompressed bytes. */
    private static function fragmentHashes(string $out): array
    {
        $hashes = [];
        foreach (glob($out . '/pagefind/fragment/*.pf_fragment') ?: [] as $path) {
            $hashes[basename($path)] = hash('sha256', (string) gzdecode((string) file_get_contents($path)));
        }
        ksort($hashes);

        return $hashes;
    }

    /**
     * True when any index chunk still carries a term derived from $word.
     *
     * Matched on the stem's prefix rather than the surface form: terms are
     * stemmed on the way in, so asking for the word as written finds nothing
     * even when its postings are right there.
     */
    private function indexContainsTerm(string $word): bool
    {
        $stem = (new \Tag1\Scolta\Index\Stemmer('en'))->stem(strtolower($word));
        foreach (glob($this->incrementalOut . '/pagefind/index/*.pf_index') ?: [] as $path) {
            foreach (array_keys(PfIndexCodec::splitEntriesFromFile($path)) as $term) {
                if ((string) $term === $stem) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testDroppingAttachmentTextLeavesNoOrphanedPosting(): void
    {
        $items = SyntheticCorpus::generate(80, seed: 5);
        // A term that appears nowhere else in the corpus, so any surviving
        // posting for it can only have come from this page's attachment.
        $items[40] = $items[40]->cloneWith(['attachmentText' => 'Zygomorphic corolla symmetry.']);
        $this->seedBoth($items);

        $edited     = $items;
        $edited[40] = $edited[40]->cloneWith(['attachmentText' => '']);

        $updater = $this->updater();
        $updater->stageUpsert($edited[40]);
        $result = $updater->commit();

        $this->assertSame(1, $result->pagesUpdated);

        $this->rebuildReference($edited);
        $this->assertTreesMatch('Dropping attachment text must leave no posting a full rebuild would not have.');
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

    /**
     * A touched chunk is renamed, and the name it had is not left behind.
     *
     * The filename follows the chunk's contents rather than its word list
     * alone, so a postings change does move the file — that is what keeps a
     * cached copy of the previous bytes unreachable instead of merely stale.
     * What the design still turns on is that the *range table* holds still:
     * one chunk is rewritten, not the cover re-cut, and the result stays
     * byte-identical to a full rebuild.
     */
    public function testChangingOnlyPostingsRenamesItsChunkAndLeavesNoOrphan(): void
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

        $edited  = self::closedVocabularyPage(20, 1);
        $updater = $this->updater();
        $updater->stageUpsert($edited);
        $updater->commit();

        $namesAfter = array_map('basename', glob($this->incrementalOut . '/pagefind/index/*.pf_index') ?: []);
        sort($namesAfter);

        $this->assertCount(
            count($namesBefore),
            $namesAfter,
            'A postings change must rename the chunk it touches, not add one alongside it.',
        );
        $this->assertNotSame(
            $namesBefore,
            $namesAfter,
            'A chunk whose postings moved kept its filename, so a cache holding the previous '
            . 'bytes answers for the new contents.',
        );
        $this->assertCount(
            1,
            array_diff($namesBefore, $namesAfter),
            'Exactly the touched chunk should have been renamed.',
        );

        $changed     = $items;
        $changed[19] = $edited;
        $this->rebuildReference($changed);
        $this->assertTreesMatch('A renamed chunk must still match what a full rebuild produces.');
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

    public function testAFullBuildAfterJournalledUpdatesReproducesTheSameIndex(): void
    {
        // An incremental commit appends to the ledger journal instead of
        // snapshotting the whole table. The next full build therefore starts
        // from snapshot-plus-journal rather than from a snapshot, and it has to
        // reproduce exactly the numbering the updates left — including a
        // released ordinal still on the free list, which only the journal
        // records now.
        $items = SyntheticCorpus::generate(60, seed: 21);
        $this->seedBoth($items);

        $editA = $items[15]->cloneWith(['bodyHtml' => $items[31]->bodyHtml]);
        $gone  = $items[40];

        $updater = $this->updater();
        $updater->stageUpsert($editA);
        $updater->commit();

        $updater = $this->updater();
        $updater->stageDelete($gone->id);
        $updater->commit();

        // The journal is what the next load will read; if the update had
        // snapshotted, this assertion would be vacuous.
        $this->assertFileExists(
            $this->incrementalState . '/' . PageTableLedger::JOURNAL_FILENAME,
            'The incremental commits snapshotted the ledger instead of journalling it.',
        );

        $survivors = array_values(array_filter(
            $items,
            static fn(ContentItem $i): bool => $i->id !== $gone->id,
        ));
        $survivors[15] = $editA;

        // A full build over the journalled state directory.
        $this->fullBuild($this->incrementalState, $this->incrementalOut, $survivors);

        // The reference saw the same two changes in the same order.
        $this->rebuildReference($survivors);
        $this->assertTreesMatch(
            'A full build on top of journalled incremental commits must reproduce the reference index.',
        );
    }

    public function testAnAppendAfterAJournalledDeleteStillReusesTheFreedOrdinal(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 23);
        $this->seedBoth($items);

        $gone = $items[10];
        $updater = $this->updater();
        $updater->stageDelete($gone->id);
        $updater->commit();

        $freed = new PageTableLedger($this->incrementalState, new FilesystemDriver());
        $this->assertContains(
            10,
            $freed->tombstones(),
            'The journalled delete did not leave a tombstone a reloaded ledger can see.',
        );

        // A brand-new page must take the freed ordinal, not a fresh one, or the
        // page table grows a permanent hole across every future build.
        $added   = SyntheticCorpus::item(41, seed: 23);
        $updater = $this->updater();
        $updater->stageUpsert($added);
        $updater->commit();

        $after = new PageTableLedger($this->incrementalState, new FilesystemDriver());
        $this->assertSame(10, $after->ordinalFor($added->id));
        $this->assertSame(40, $after->pageTableSize());
    }

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
