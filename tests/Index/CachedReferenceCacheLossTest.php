<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\TimestampManifest;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * A build that cannot read what it was handed must fail, not publish.
 *
 * A warm build hands the orchestrator one {@see CachedContentReference} per
 * unchanged page: an id, a url, its filters, and the content hash under which
 * its token data is cached. There is no body — not loading one is the entire
 * saving. So a reference that misses the token cache is a page this run cannot
 * index at all, and every stage after the miss reads it as a page that no
 * longer exists: the ledger releases its ordinal as stale, the merge fills the
 * hole with a tombstone, and the swap publishes the result.
 *
 * With an intact cache the misses are a handful. With a cache that was cleared
 * — an operator wiping state/, a restore that missed a directory, an
 * incremental update that pruned it — every reference misses, and the build
 * reported success while replacing a whole index with tombstones.
 *
 * The other half of the same subject is where those cleared caches came from,
 * and most of them were self-inflicted: a build that did not run to completion
 * in one process destroyed the token cache and the timestamp manifest itself.
 * The two halves have to be tested together, because the obvious way to stop
 * the wipe is to stop refusing, and that is the one thing this file must never
 * let happen: a cache emptied by a real loss still has to refuse to publish.
 */
#[CoversClass(IndexBuildOrchestrator::class)]
final class CachedReferenceCacheLossTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $base            = sys_get_temp_dir() . '/scolta-cache-loss-' . uniqid('', true);
        $this->stateDir  = $base . '/state';
        $this->outputDir = $base . '/out';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir(dirname($this->stateDir));
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
     * @param list<ContentItem|CachedContentReference> $pages
     */
    private function build(array $pages): \Tag1\Scolta\Index\StatusReport
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);

        return $orchestrator->build(
            BuildIntent::fresh(count($pages), MemoryBudget::conservative()),
            $pages,
        );
    }

    private static function reference(ContentItem $item): CachedContentReference
    {
        return new CachedContentReference(
            entityKey: $item->id,
            contentHash: PhpIndexer::contentHash($item),
            id: $item->id,
            url: $item->url,
            date: $item->date,
            siteName: $item->siteName,
            language: $item->language,
            filters: $item->filters,
            sortable: $item->sortable,
            metadata: $item->metadata,
        );
    }

    /** Wipe the token cache the way clearing the state directory does. */
    private function clearTokenCache(): void
    {
        foreach (glob($this->stateDir . '/token-cache/*') ?: [] as $file) {
            unlink($file);
        }
        $manifest = $this->stateDir . '/token-cache-manifest.php';
        if (is_file($manifest)) {
            unlink($manifest);
        }
    }

    /** @return list<string> Fragment file names, sorted. */
    private function fragmentNames(): array
    {
        $names = array_map('basename', glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: []);
        sort($names);

        return $names;
    }

    /** @return list<string> Index chunk file names, sorted. */
    private function indexNames(): array
    {
        $names = array_map('basename', glob($this->outputDir . '/pagefind/index/*.pf_index') ?: []);
        sort($names);

        return $names;
    }

    /**
     * Seed the timestamp manifest the way an adapter's gatherer does.
     *
     * Nothing in this package writes it — the gatherer that decides an entity
     * is unchanged does — so a test about losing it has to put it there.
     *
     * @param list<ContentItem> $items
     */
    private function seedTimestampManifest(array $items): void
    {
        $manifest = new TimestampManifest($this->stateDir, new FilesystemDriver());
        foreach ($items as $i => $item) {
            $manifest->put((string) $item->id, 1_700_000_000 + $i, [[
                'hash' => PhpIndexer::contentHash($item),
                'id'   => $item->id,
                'url'  => $item->url,
            ]]);
        }
        $manifest->pruneAndSave();
    }

    /**
     * The token-cache manifest as it sits on disk: content hash => chunk number.
     *
     * @return array<string, int>
     */
    private function tokenCacheManifest(): array
    {
        return self::readSerialized($this->stateDir . '/token-cache-manifest.php');
    }

    /**
     * The timestamp manifest as it sits on disk: entity key => entry.
     *
     * @return array<string, mixed>
     */
    private function timestampManifest(): array
    {
        return self::readSerialized($this->stateDir . '/timestamp-manifest.php');
    }

    /** @return array<array-key, mixed> */
    private static function readSerialized(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = @unserialize((string) file_get_contents($path), ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }

    /** How many chunk files the token cache still has on disk. */
    private function tokenCacheChunkCount(): int
    {
        return count(glob($this->stateDir . '/token-cache/chunk-*.php') ?: []);
    }

    public function testABuildWhoseCachedReferencesAllMissRefusesToPublish(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $cold  = $this->build($items);
        $this->assertTrue($cold->success, 'Cold build failed: ' . ($cold->error ?? ''));

        $indexBefore    = $this->indexNames();
        $fragmentBefore = $this->fragmentNames();

        $this->clearTokenCache();

        $warm = $this->build(array_map(self::reference(...), $items));

        $this->assertFalse($warm->success, 'A build that lost the token cache must not report success.');
        $this->assertNotNull($warm->error);
        $this->assertStringContainsString('token cache lost', (string) $warm->error);
        $this->assertStringContainsString('--force', (string) $warm->error);

        // Refused before the merge, so the index that was already published is
        // still there, byte for byte.
        $this->assertSame($indexBefore, $this->indexNames(), 'The published index must not have been swapped.');
        $this->assertSame($fragmentBefore, $this->fragmentNames(), 'The published fragments must not have changed.');
    }

    /**
     * The abort must leave the cache recoverable rather than finish it off.
     *
     * prepare() no longer unlinks the manifest, so the last good copy is on
     * disk throughout the run and this save writes a superset of it. It is
     * kept because the in-memory copy is the newer one — it holds whatever
     * this run cached before the misses were counted — and because saving
     * without pruning is the only correct way to write a run that did not look
     * up every live page.
     */
    public function testTheAbortWritesTheTokenCacheManifestBackToDisk(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $this->build($items);

        // Half the corpus keeps its cache entry; the other half is handed as
        // references whose hashes were never cached, so they miss.
        $strangers = [];
        foreach (SyntheticCorpus::generate(40, seed: 99) as $item) {
            $strangers[] = self::reference($item);
        }

        $warm = $this->build($strangers);
        $this->assertFalse($warm->success);

        $manifest = $this->stateDir . '/token-cache-manifest.php';
        $this->assertFileExists($manifest, 'The aborting build must save the manifest it inherited.');
        $data = @unserialize((string) file_get_contents($manifest), ['allowed_classes' => false]);
        $this->assertIsArray($data);
        $this->assertCount(40, $data, 'Every entry the cold build wrote must still be there.');
    }

    /**
     * A handful of misses is ordinary — an entry evicted past the manifest cap
     * — and must not stop a build that indexed everything else.
     */
    public function testAFewMissesDoNotAbortTheBuild(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $this->build($items);

        $pages = array_map(self::reference(...), $items);
        // Two references pointing at token data that was never cached.
        $pages[7]  = new CachedContentReference(
            entityKey: $pages[7]->entityKey,
            contentHash: str_repeat('0', 40),
            id: $pages[7]->id,
            url: $pages[7]->url,
            date: $pages[7]->date,
            siteName: $pages[7]->siteName,
            language: $pages[7]->language,
            filters: $pages[7]->filters,
        );
        $pages[19] = new CachedContentReference(
            entityKey: $pages[19]->entityKey,
            contentHash: str_repeat('1', 40),
            id: $pages[19]->id,
            url: $pages[19]->url,
            date: $pages[19]->date,
            siteName: $pages[19]->siteName,
            language: $pages[19]->language,
            filters: $pages[19]->filters,
        );

        $warm = $this->build($pages);

        $this->assertTrue($warm->success, 'Two misses in forty is under the budget: ' . ($warm->error ?? ''));
        $this->assertSame(38, $warm->pagesProcessed);
    }

    /**
     * The second line of the same defence, on the path the miss budget cannot
     * see: a run that yields nothing at all against a ledger full of pages.
     *
     * Every row goes stale, the merge pads the page table out with tombstones,
     * and the integrity check used to return early because zero pages were
     * processed — the one case where "zero" is the symptom rather than the
     * exemption.
     */
    public function testABuildThatYieldsNothingRefusesToPublishAnAllTombstoneIndex(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $cold  = $this->build($items);
        $this->assertTrue($cold->success);

        $indexBefore = $this->indexNames();

        $empty = $this->build([]);

        $this->assertFalse($empty->success, 'An index of nothing but tombstones must not be reported as built.');
        $this->assertStringContainsString('none of them is live', (string) $empty->error);
        $this->assertSame($indexBefore, $this->indexNames(), 'The published index must not have been replaced.');
    }

    /**
     * The genuinely empty case still has to work: no ledger, no pages, no
     * failure invented for a site that simply has no content yet.
     */
    public function testAnEmptyCorpusOnAnEmptyLedgerStillSucceeds(): void
    {
        $result = $this->build([]);

        $this->assertTrue($result->success, 'A first build of an empty site must not fail: ' . ($result->error ?? ''));
        $this->assertSame(0, $result->pagesProcessed);
    }

    // -------------------------------------------------------------------
    // Build state survives an interrupted build
    // -------------------------------------------------------------------

    /**
     * A build killed before its completion save leaves the last good state.
     *
     * This is the hard-crash case — an OOM kill, a forced pod eviction, a
     * segfault — reproduced by dying after prepare() and before any save. It
     * used to leave nothing: prepare() had already unlinked
     * token-cache-manifest.php and both timestamp manifests, and the copies
     * that were keeping the build alive were in memory in a process that is
     * gone. The next build then read no manifest, treated the whole corpus as
     * changed, and re-tokenized all of it.
     */
    public function testABuildKilledBeforeItsCompletionSaveLeavesTheLastGoodBuildState(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $cold  = $this->build($items);
        $this->assertTrue($cold->success, 'Cold build failed: ' . ($cold->error ?? ''));
        $this->seedTimestampManifest($items);

        $tokenCacheBefore = $this->tokenCacheManifest();
        $timestampsBefore = $this->timestampManifest();
        $chunksBefore     = $this->tokenCacheChunkCount();
        $this->assertCount(40, $tokenCacheBefore, 'A cold build caches one entry per page.');
        $this->assertCount(40, $timestampsBefore);
        $this->assertGreaterThan(0, $chunksBefore);

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report       = $orchestrator->build(
            BuildIntent::fresh(40, MemoryBudget::conservative()),
            (static function () use ($items): \Generator {
                yield $items[0];

                throw new \RuntimeException('the process died here');
            })(),
        );

        $this->assertFalse($report->success, 'A build that died mid-corpus must not report success.');

        $manifest = $this->stateDir . '/token-cache-manifest.php';
        $this->assertFileExists($manifest, 'The last good token-cache manifest must still be on disk.');
        $this->assertGreaterThan(
            strlen(serialize([])),
            (int) filesize($manifest),
            'The manifest must not have been reduced to the 6-byte empty array.',
        );
        $this->assertSame($tokenCacheBefore, $this->tokenCacheManifest());
        $this->assertSame($timestampsBefore, $this->timestampManifest());
        $this->assertSame($chunksBefore, $this->tokenCacheChunkCount(), 'No cache chunk file may be deleted.');
    }

    /**
     * The voluntary memory yield has not looked up the tail, so it must not
     * prune.
     *
     * pruneAndSave() drops every hash the process did not look up, which is
     * only ever true of the pages that are gone at the end of a run that
     * looked all of them up. A yield happens by definition part-way through:
     * every page after the yield point reads as deleted, its manifest entry
     * goes, and pruneAndSave() deletes the chunk files that then have no live
     * entries — so the state the resumed segment needs is destroyed by the
     * yield that scheduled it.
     */
    public function testTheMemoryYieldSavesWithoutPruningTheTailItNeverLookedUp(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $this->assertTrue($this->build($items)->success);
        $this->seedTimestampManifest($items);

        $report = $this->buildYieldingOnce($items);
        $this->assertSame('memory_abort', $report->error, 'The probe must have forced a yield.');

        $this->assertCount(
            40,
            $this->tokenCacheManifest(),
            'The yield dropped the token-cache entries of the pages it had not reached yet.',
        );
        $this->assertCount(
            40,
            $this->timestampManifest(),
            'The yield dropped the timestamp-manifest entries of the pages it had not reached yet.',
        );

        // Saved without pruning means saved as plain chunk numbers, never the
        // negative in-memory seen marker.
        foreach ($this->tokenCacheManifest() as $hash => $chunk) {
            $this->assertGreaterThanOrEqual(0, $chunk, "Manifest entry {$hash} was saved as a seen marker.");
        }
    }

    /**
     * finalize() never gathered, so it has nothing to prune with.
     *
     * A merge-only pass — `drush scolta:finalize`, the deferred-merge recovery
     * the pipeline itself recommends — loads the manifest from disk in a fresh
     * process and looks up not one page. pruneAndSave() there keeps nothing at
     * all: it wrote the 6-byte a:0:{} manifest and deleted every file in
     * token-cache/, which is exactly the state observed on the real corpus
     * after a crash followed by a finalize.
     */
    public function testAFinalizeOnlyRunDoesNotPruneTheStateItNeverGathered(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $this->assertTrue($this->build($items)->success);
        $this->seedTimestampManifest($items);

        // Leave committed chunks and no merge behind, the way a deferred merge
        // does. The yield's own save is asserted by the test above; here it is
        // only the setup.
        $this->assertSame('memory_abort', $this->buildYieldingOnce($items)->error);
        $this->assertCount(40, $this->tokenCacheManifest(), 'Precondition: the yield kept the token cache.');
        $this->assertCount(40, $this->timestampManifest(), 'Precondition: the yield kept the timestamp manifest.');
        $chunksBefore = $this->tokenCacheChunkCount();
        $this->assertGreaterThan(0, $chunksBefore);

        $finalizer = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report    = $finalizer->finalize(MemoryBudget::conservative());
        $this->assertTrue($report->success, 'Finalize failed: ' . ($report->error ?? ''));

        $manifest = $this->stateDir . '/token-cache-manifest.php';
        $this->assertFileExists($manifest);
        $this->assertGreaterThan(
            strlen(serialize([])),
            (int) filesize($manifest),
            'finalize() reduced the token-cache manifest to the 6-byte empty array.',
        );
        $this->assertCount(40, $this->tokenCacheManifest(), 'finalize() must not prune a cache it never read.');
        $this->assertCount(40, $this->timestampManifest(), 'finalize() must not prune a manifest it never read.');
        $this->assertSame($chunksBefore, $this->tokenCacheChunkCount(), 'finalize() must not delete cache chunk files.');
    }

    /**
     * A build completed across resume segments must not prune either.
     *
     * The final segment is handed the whole corpus again — no adapter can
     * translate "pages committed" into a position in its own query — and skips
     * every id the ledger says an earlier segment already committed, before it
     * would have looked the page up. So on the success path at the end of a
     * resumed segment, "not looked up" covers every page the earlier segments
     * did, and pruning drops all of them: a build that succeeded across three
     * segments left a token cache holding only the third one's pages.
     */
    public function testABuildCompletedAcrossResumeSegmentsKeepsTheWholeCorpusCached(): void
    {
        $items = SyntheticCorpus::generate(40, seed: 3);
        $this->assertTrue($this->build($items)->success);
        $this->seedTimestampManifest($items);

        $segments = 0;
        do {
            $report = $this->buildYieldingOnce($items, fresh: $segments === 0);
            $segments++;
        } while ($report->error === 'memory_abort' && $segments < 20);

        $this->assertTrue($report->success, 'The segmented build must ultimately succeed: ' . ($report->error ?? ''));
        $this->assertGreaterThan(1, $segments, 'The test is meaningless unless the build actually resumed.');

        $this->assertCount(
            40,
            $this->tokenCacheManifest(),
            'The final segment pruned the token-cache entries of the pages earlier segments committed.',
        );
        $this->assertCount(
            40,
            $this->timestampManifest(),
            'The final segment pruned the timestamp-manifest entries of the pages earlier segments committed.',
        );
    }

    /**
     * Run a build that yields for memory pressure after its first chunk.
     *
     * @param list<ContentItem> $items
     */
    private function buildYieldingOnce(array $items, bool $fresh = true): \Tag1\Scolta\Index\StatusReport
    {
        $yielded      = false;
        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            memoryPressureProbe: static function () use (&$yielded): bool {
                if ($yielded) {
                    return false;
                }

                return $yielded = true;
            },
        );

        $budget = MemoryBudget::conservative()->withChunkSize(5);

        return $orchestrator->build(
            $fresh ? BuildIntent::fresh(count($items), $budget) : BuildIntent::resume($budget),
            $items,
        );
    }
}
