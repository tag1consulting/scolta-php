<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * A build handed a subset of the corpus must not delete the rest of it.
 *
 * Observed on production (sml, 2026-09-02): `drush scolta:build
 * --entity-type=node --bundle=tntl` gathered 1,518 pages, and build() then
 * called releaseStaleRows(), which released every one of the ~14,600 ledger
 * ids the scoped gather never yielded. The merge padded their ordinals with
 * tombstones, and the published index held 16,166 fragments of which 1,518
 * were live: the whole site bar one bundle, gone.
 *
 * releaseStaleRows() reads "not yielded" as "deleted at the source", which is
 * sound for a full build and false for every scoped one. The caller now says
 * which it is.
 */
#[CoversClass(IndexBuildOrchestrator::class)]
#[CoversClass(BuildIntent::class)]
#[CoversClass(PageTableLedger::class)]
final class PartialScopeBuildTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir  = sys_get_temp_dir() . '/scolta-scope-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-scope-out-' . uniqid();
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

    private function orchestrator(): IndexBuildOrchestrator
    {
        return new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
    }

    /**
     * @param list<ContentItem> $items
     */
    private function buildFull(array $items): void
    {
        $result = $this->orchestrator()->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
        );
        $this->assertTrue($result->success, 'Baseline build failed: ' . ($result->error ?? ''));
    }

    /**
     * @param list<ContentItem> $items
     */
    private function buildPartial(array $items): \Tag1\Scolta\Index\StatusReport
    {
        return $this->orchestrator()->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative())->withPartialScope(),
            $items,
        );
    }

    private function ledger(): PageTableLedger
    {
        return new PageTableLedger($this->stateDir, new FilesystemDriver());
    }

    /**
     * @return array<string, string> Relative path => sha256 of the bytes.
     */
    private function publishedIndex(): array
    {
        $base = $this->outputDir . '/pagefind';
        if (!is_dir($base)) {
            return [];
        }
        $manifest = [];
        $items    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $file) {
            if ($file->isFile()) {
                $manifest[substr($file->getPathname(), strlen($base) + 1)]
                    = hash('sha256', (string) file_get_contents($file->getPathname()));
            }
        }
        ksort($manifest);

        return $manifest;
    }

    /**
     * The regression. Twenty pages are indexed, then five of them are handed
     * back as a scoped build. The fifteen the second build was never asked to
     * look at must still be live in the ledger afterwards.
     */
    public function testABuildOverASubsetLeavesTheOutOfScopeRowsLive(): void
    {
        $corpus = SyntheticCorpus::generate(20, seed: 11);
        $this->buildFull($corpus);

        $before = $this->ledger();
        $this->assertSame(20, $before->liveCount());
        $this->assertSame([], $before->tombstones());

        $scope = array_slice($corpus, 0, 5);
        $this->buildPartial($scope);

        $after = $this->ledger();
        $this->assertSame(
            20,
            $after->liveCount(),
            'A scoped build must not release the ids it was never asked to look at.',
        );
        $this->assertSame([], $after->tombstones(), 'No ordinal outside the scope may be tombstoned.');
        $this->assertSame(20, $after->pageTableSize());

        // Every out-of-scope id still owns the ordinal it owned before.
        foreach ($corpus as $i => $item) {
            $this->assertSame(
                $i,
                $after->ordinalFor($item->id),
                "Item {$item->id} must keep ordinal {$i}.",
            );
        }
    }

    /**
     * The other half of the same guarantee: not removing the rows is no use if
     * the merge publishes tombstones over them anyway. A scoped build whose
     * scope does not cover the index refuses, and the index it refuses to
     * replace is still the one being served.
     */
    public function testAScopedBuildThatCannotRepublishTheRestRefusesAndLeavesTheIndexServing(): void
    {
        $corpus = SyntheticCorpus::generate(20, seed: 11);
        $this->buildFull($corpus);
        $published = $this->publishedIndex();
        $this->assertNotSame([], $published);

        $report = $this->buildPartial(array_slice($corpus, 0, 5));

        $this->assertFalse($report->success, 'A scoped build that would drop 15 pages must not report success.');
        $this->assertStringContainsString('scoped build refused', (string) $report->error);
        $this->assertStringContainsString('15', (string) $report->error);

        $this->assertSame(
            $published,
            $this->publishedIndex(),
            'The published index must be byte-identical to the one that was serving before the refusal.',
        );
    }

    /**
     * A site whose index only ever holds one bundle passes --bundle on every
     * build. Its scope covers the whole ledger, so there is nothing out of
     * scope to protect and the build publishes as it always did.
     */
    public function testAScopedBuildCoveringTheWholeLedgerStillPublishes(): void
    {
        $corpus = SyntheticCorpus::generate(12, seed: 5);
        $this->buildFull($corpus);
        $baseline = $this->publishedIndex();

        $report = $this->buildPartial($corpus);

        $this->assertTrue($report->success, 'Scoped build failed: ' . ($report->error ?? ''));
        $this->assertSame(12, $this->ledger()->liveCount());
        $this->assertSame($baseline, $this->publishedIndex(), 'Reindexing the same pages must reproduce the index.');
    }

    /**
     * A page genuinely deleted at the source still has to disappear, or the
     * guard has traded one silent wrong index for another. Full scope is the
     * default, so this is the untouched path.
     */
    public function testAFullBuildStillReleasesRowsForPagesDeletedAtTheSource(): void
    {
        $corpus = SyntheticCorpus::generate(12, seed: 5);
        $this->buildFull($corpus);

        $survivors = array_values(array_filter(
            $corpus,
            static fn(ContentItem $i): bool => $i->id !== 'item-6',
        ));
        $this->buildFull($survivors);

        $ledger = $this->ledger();
        $this->assertNull($ledger->ordinalFor('item-6'));
        $this->assertSame([5], $ledger->tombstones());
        $this->assertSame(11, $ledger->liveCount());
    }

    /**
     * The scope has to survive the process boundary. `scolta:finalize` runs in
     * a fresh process after a memory abort and does the same stale-release, so
     * it reads the scope back off the build manifest.
     */
    public function testFinalizeInheritsThePartialScopeFromTheManifest(): void
    {
        $corpus = SyntheticCorpus::generate(20, seed: 11);
        $this->buildFull($corpus);
        $published = $this->publishedIndex();

        // prepare() records the scope; assert it landed rather than trusting
        // the refusal below to prove it, which it would also do if finalize()
        // refused for some unrelated reason.
        $this->buildPartial(array_slice($corpus, 0, 5));

        $report = $this->orchestrator()->finalize(MemoryBudget::conservative());

        $this->assertFalse($report->success);
        $this->assertSame($published, $this->publishedIndex());
    }

    public function testTheFactoryDeclaresPartialScopeForScopedBuildsButNotForResume(): void
    {
        $budget = MemoryBudget::conservative();

        $this->assertFalse(BuildIntentFactory::fromFlags(false, false, 10, $budget)->isPartial());
        $this->assertTrue(
            BuildIntentFactory::fromFlags(false, false, 10, $budget, partial: true)->isPartial(),
        );
        $this->assertTrue(
            BuildIntentFactory::fromFlags(false, true, 10, $budget, partial: true)->isPartial(),
            '--restart is still a scoped build when the gather is scoped.',
        );

        // Resume carries no scope of its own; the manifest supplies it.
        $this->assertFalse(
            BuildIntentFactory::fromFlags(true, false, 10, $budget, partial: true)->isPartial(),
        );
    }
}
