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
     * A fresh build's prepare() unlinks every file in the state directory,
     * token-cache-manifest.php included, and the cache goes on working only
     * because its manifest is already in memory. Returning without writing it
     * back therefore turns "the manifest was missing" into "the manifest and
     * every chunk are orphaned".
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
}
