<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * The full build now takes its page ordinals from a durable ledger instead of
 * a running counter. Two properties have to hold or that change is a
 * regression rather than a foundation.
 *
 * 1. On a state directory with no ledger, allocation hands out 0, 1, 2 … in
 *    gather order, which is exactly what the counter did. Output is unchanged.
 *    Verified additionally against origin/main outside CI: a 300-page corpus
 *    produced 319 output files, all byte-identical after decompression.
 *
 * 2. On a state directory that already has a ledger, a rebuild reproduces the
 *    same numbering, so fragment filenames are stable across builds. Today
 *    every full build renumbers from zero and invalidates every cached
 *    fragment in every browser; that is what makes an incremental update
 *    comparable to a rebuild at all.
 */
#[CoversClass(IndexBuildOrchestrator::class)]
#[CoversClass(PageTableLedger::class)]
final class LedgerDrivenBuildTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir  = sys_get_temp_dir() . '/scolta-ldb-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-ldb-out-' . uniqid();
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
     * @param list<\Tag1\Scolta\Export\ContentItem> $items
     */
    private function build(array $items): void
    {
        // IndexBuildOrchestrator::build() is the production path — it is what
        // ScoltaCommands and ScoltaRebuildWorker call. PhpIndexer is a second,
        // older entry point used by E2E scripts, and it does not read the
        // ledger, so a test driving it would prove nothing about this change.
        $budget       = MemoryBudget::conservative();
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $result       = $orchestrator->build(BuildIntent::fresh(count($items), $budget), $items);

        $this->assertTrue($result->success, 'Build failed: ' . ($result->error ?? ''));
    }

    /**
     * @return array<string, string> Relative path => sha256 of the decompressed bytes.
     */
    private function outputManifest(): array
    {
        $base     = $this->outputDir . '/pagefind';
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

    private function ledger(): PageTableLedger
    {
        return new PageTableLedger($this->stateDir, new FilesystemDriver());
    }

    public function testAnEmptyLedgerNumbersInGatherOrderExactlyLikeTheOldCounter(): void
    {
        $items = SyntheticCorpus::generate(60, seed: 3);
        $this->build($items);

        $ledger = $this->ledger();
        foreach ($items as $i => $item) {
            $this->assertSame(
                $i,
                $ledger->ordinalFor($item->id),
                "Item {$item->id} should hold gather-order ordinal {$i}.",
            );
        }
        $this->assertSame(60, $ledger->pageTableSize());
        $this->assertSame([], $ledger->tombstones());
    }

    public function testRebuildingTheSameCorpusReproducesTheIndexExactly(): void
    {
        $items = SyntheticCorpus::generate(60, seed: 3);

        $this->build($items);
        $first = $this->outputManifest();

        $this->build($items);
        $second = $this->outputManifest();

        $this->assertSame($first, $second, 'A rebuild of unchanged content must reproduce the index byte for byte.');
    }

    public function testOrdinalsSurviveAnAppendSoEarlierFragmentsAreNotRenamed(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $this->build($items);
        $before = $this->outputManifest();
        $beforeFragments = array_filter(
            array_keys($before),
            static fn(string $p): bool => str_starts_with($p, 'fragment/'),
        );

        // Append a page and rebuild.
        $items[] = SyntheticCorpus::item(41, seed: 3);
        $this->build($items);
        $after = $this->outputManifest();
        $afterFragments = array_filter(
            array_keys($after),
            static fn(string $p): bool => str_starts_with($p, 'fragment/'),
        );

        $this->assertCount(count($beforeFragments) + 1, $afterFragments);
        $this->assertSame(
            [],
            array_diff($beforeFragments, $afterFragments),
            'Appending a page must not rename any existing fragment.',
        );
        $this->assertSame(40, $this->ledger()->ordinalFor('item-41'));
    }

    public function testADeletedPageIsTombstonedAndThePageTableStaysDense(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $this->build($items);

        // Drop one page from the middle and rebuild.
        $survivors = array_values(array_filter(
            $items,
            static fn(\Tag1\Scolta\Export\ContentItem $i): bool => $i->id !== 'item-20',
        ));
        $this->build($survivors);

        $ledger = $this->ledger();
        $this->assertNull($ledger->ordinalFor('item-20'), 'Deleted id must lose its assignment.');
        $this->assertSame([19], $ledger->tombstones());
        $this->assertSame(40, $ledger->pageTableSize(), 'Table must stay dense, not shrink.');
        $this->assertSame(39, $ledger->liveCount());

        // The page table is positional: FacetIndexWriter throws on a hole, so a
        // successful build already proves the tombstone filled the row. Assert
        // the fragment count directly too.
        $fragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertCount(40, $fragments, 'Tombstone must occupy a real fragment slot.');

        // Surviving pages keep their ordinals, so nothing after 19 renumbered.
        $this->assertSame(20, $ledger->ordinalFor('item-21'));
        $this->assertSame(39, $ledger->ordinalFor('item-40'));
    }

    /**
     * The same delete-and-rebuild as above, on a corpus whose ids are bare
     * numbers — which is what a Drupal node id is, and therefore the common
     * case rather than the exotic one.
     *
     * This is the caller that made the ledger's key-normalization bug a
     * production crash: build() accumulates $seenIds over the corpus and ends
     * with releaseAllExcept($seenIds), so any id deleted at the source since
     * the last build gets passed to release(string $id) as an int and the
     * build dies at the merge boundary with no index written. The existing
     * delete coverage above missed it only because 'item-20' is not a decimal
     * integer and so keeps its string key.
     */
    public function testAFullRebuildPrunesADeletedNumericIdWithoutCrashing(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3, idPrefix: '');
        $this->build($items);
        $this->assertSame(19, $this->ledger()->ordinalFor('20'));

        $survivors = array_values(array_filter(
            $items,
            static fn(\Tag1\Scolta\Export\ContentItem $i): bool => $i->id !== '20',
        ));

        // Before the fix this build throws TypeError out of releaseAllExcept()
        // rather than returning a failed StatusReport, so the assertion that
        // matters is that build() returns at all.
        $this->build($survivors);

        $ledger = $this->ledger();
        $this->assertNull($ledger->ordinalFor('20'), 'Deleted numeric id must lose its assignment.');
        $this->assertSame([19], $ledger->tombstones());
        $this->assertSame(40, $ledger->pageTableSize(), 'Table must stay dense, not shrink.');
        $this->assertSame(39, $ledger->liveCount());
        $this->assertSame(20, $ledger->ordinalFor('21'), 'Survivors keep their ordinals.');

        $fragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertCount(40, $fragments, 'Tombstone must occupy a real fragment slot.');
    }

    /**
     * Numeric and translation-suffixed ids in one corpus, pruned together —
     * the multilingual Drupal shape, where the two id forms take different
     * array-key types inside the same ledger.
     */
    public function testAFullRebuildPrunesMixedNumericAndSuffixedIds(): void
    {
        $items = SyntheticCorpus::generate(20, seed: 5, idPrefix: '');
        foreach (SyntheticCorpus::generate(20, seed: 5, idPrefix: '') as $item) {
            $items[] = new \Tag1\Scolta\Export\ContentItem(
                id: $item->id . '-es',
                title: $item->title,
                bodyHtml: $item->bodyHtml,
                url: $item->url . '/es',
                date: $item->date,
                siteName: $item->siteName,
                language: $item->language,
                filters: $item->filters,
            );
        }
        $this->build($items);
        $this->assertSame(40, $this->ledger()->liveCount());

        // Drop one of each id shape.
        $survivors = array_values(array_filter(
            $items,
            static fn(\Tag1\Scolta\Export\ContentItem $i): bool => $i->id !== '7' && $i->id !== '12-es',
        ));
        $this->build($survivors);

        $ledger = $this->ledger();
        $this->assertNull($ledger->ordinalFor('7'));
        $this->assertNull($ledger->ordinalFor('12-es'));
        $this->assertSame(38, $ledger->liveCount());
        $this->assertCount(2, $ledger->tombstones());
        $this->assertSame(40, $ledger->pageTableSize());
    }

    public function testAFreedOrdinalIsReusedByTheNextNewPage(): void
    {
        $items = SyntheticCorpus::generate(30, seed: 3);
        $this->build($items);

        $withoutOne = array_values(array_filter(
            $items,
            static fn(\Tag1\Scolta\Export\ContentItem $i): bool => $i->id !== 'item-10',
        ));
        $this->build($withoutOne);
        $this->assertSame([9], $this->ledger()->tombstones());

        $withNew   = $withoutOne;
        $withNew[] = SyntheticCorpus::item(31, seed: 3);
        $this->build($withNew);

        $ledger = $this->ledger();
        $this->assertSame(9, $ledger->ordinalFor('item-31'), 'New page must reuse the freed ordinal.');
        $this->assertSame([], $ledger->tombstones());
        $this->assertSame(30, $ledger->pageTableSize(), 'Reuse must not grow the table.');
    }
}
