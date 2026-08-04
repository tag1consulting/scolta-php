<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageWordCache;
use Tag1\Scolta\Index\Token;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * The token cache's memory is a function of the budget, not of the corpus.
 *
 * It used to be neither. Two hash tables — the lookup manifest and a second
 * set of every hash the build had touched — both grew one entry per page and
 * were never released between chunks, so `--chunk-size=20` on a large corpus
 * changed how much was read at a time and nothing about what was retained.
 *
 * @since 1.1.1
 * @stability experimental
 */
class PageWordCacheMemoryBoundTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-cache-bound-' . uniqid('', true);
        mkdir($this->stateDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
    }

    public function testTheCacheStopsAdmittingPagesAtItsManifestBudget(): void
    {
        $cache = $this->makeCache(maxManifestEntries: 100);

        for ($i = 0; $i < 2000; $i++) {
            $cache->put($this->hash($i), $this->tokenData($i));
        }
        $cache->pruneAndSave();

        $retained = $this->countRetained($cache, 2000);

        $this->assertGreaterThan(0, $retained, 'A budgeted cache must still cache something');
        $this->assertLessThanOrEqual(
            100,
            $retained,
            'The cache retained more entries than its budget allows, so peak memory is set by the corpus',
        );
    }

    /**
     * Ten times the corpus must not cost ten times the memory.
     *
     * Measured rather than asserted structurally: the failure being locked out
     * is a retained-per-page allocation, and any of them shows up here whatever
     * shape it takes.
     */
    public function testCacheMemoryDoesNotScaleWithCorpusSize(): void
    {
        $small = $this->measurePutCost(1_000);
        $large = $this->measurePutCost(10_000);

        // Some growth is legitimate — the cache fills toward its budget — but
        // the 10x corpus must not buy 10x the resident bytes.
        $this->assertLessThan(
            $small * 3 + 512 * 1024,
            $large,
            sprintf(
                'Cache memory grew with the corpus: %d bytes for 1k pages, %d bytes for 10k',
                $small,
                $large,
            ),
        );
    }

    public function testTheBudgetSizesTheManifestCap(): void
    {
        $this->assertGreaterThan(
            MemoryBudget::conservative()->tokenCacheManifestEntries(),
            MemoryBudget::aggressive()->tokenCacheManifestEntries(),
            'A larger memory budget must buy a larger cache, or the setting does nothing',
        );

        $this->assertGreaterThanOrEqual(1000, MemoryBudget::fromBytes(1)->tokenCacheManifestEntries());
    }

    public function testAnEntryThisBuildNeverTouchedIsPrunedAndOneItReadIsKept(): void
    {
        $cache = $this->makeCache(maxManifestEntries: 1000);
        $cache->put($this->hash(1), $this->tokenData(1));
        $cache->put($this->hash(2), $this->tokenData(2));
        $cache->pruneAndSave();

        // Second build: read only entry 1. Entry 2 is now stale.
        $second = $this->makeCache(maxManifestEntries: 1000);
        $this->assertNotNull($second->get($this->hash(1)));
        $second->pruneAndSave();

        $third = $this->makeCache(maxManifestEntries: 1000);
        $this->assertNotNull($third->get($this->hash(1)), 'A hash read during a build must survive its prune');
        $this->assertNull($third->get($this->hash(2)), 'A hash no build touched must be pruned');
    }

    public function testAFreshlyWrittenEntrySurvivesThePruneInTheSameBuild(): void
    {
        // The write buffer flushes into the manifest during pruneAndSave(), so
        // an encoding that marked those entries "unseen" would drop every page
        // the build had just cached.
        $cache = $this->makeCache(maxManifestEntries: 1000);
        for ($i = 0; $i < 5; $i++) {
            $cache->put($this->hash($i), $this->tokenData($i));
        }
        $cache->pruneAndSave();

        $reloaded = $this->makeCache(maxManifestEntries: 1000);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotNull($reloaded->get($this->hash($i)), "Entry {$i} was written then immediately pruned");
        }
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeCache(int $maxManifestEntries): PageWordCache
    {
        return new PageWordCache(
            $this->stateDir,
            new FilesystemDriver(),
            chunkSize: 200,
            maxManifestEntries: $maxManifestEntries,
        );
    }

    /**
     * Bytes still held by a cache after writing $count pages through it.
     */
    private function measurePutCost(int $count): int
    {
        $dir = sys_get_temp_dir() . '/scolta-cache-measure-' . uniqid('', true);
        mkdir($dir, 0755, true);

        gc_collect_cycles();
        $before = memory_get_usage();

        $cache = new PageWordCache(
            $dir,
            new FilesystemDriver(),
            chunkSize: 200,
            maxManifestEntries: 500,
        );
        for ($i = 0; $i < $count; $i++) {
            $cache->put($this->hash($i), $this->tokenData($i));
        }

        gc_collect_cycles();
        $cost = memory_get_usage() - $before;

        unset($cache);
        $this->removeDir($dir);

        return $cost;
    }

    /**
     * How many of the first $count hashes the cache can still answer for.
     */
    private function countRetained(PageWordCache $cache, int $count): int
    {
        $reloaded = $this->makeCache(maxManifestEntries: 100);
        $retained = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($reloaded->get($this->hash($i)) !== null) {
                $retained++;
            }
        }

        return $retained;
    }

    private function hash(int $i): string
    {
        return hash('sha256', 'page-' . $i);
    }

    /** @return array<string, mixed> */
    private function tokenData(int $i): array
    {
        return [
            'cleanTitle'  => 'Page ' . $i,
            'content'     => 'content for page ' . $i,
            'wordCount'   => 4,
            'titleTokens' => [new Token('page', 'page', 0)],
            'bodyTokens'  => [new Token('content', 'content', 0)],
            'urlTokens'   => [],
        ];
    }

    private function removeDir(string $dir): void
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
