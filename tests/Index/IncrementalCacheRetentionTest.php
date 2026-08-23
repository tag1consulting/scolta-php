<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageWordCache;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * An incremental update must leave the token cache belonging to the corpus.
 *
 * {@see PageWordCache::pruneAndSave()} drops every hash the process did not
 * look up, which is right at the end of a full build — it looked up all of
 * them, so what is left over is the pages that are gone — and wrong after an
 * update that looked up one. The updater called it anyway, so a single edit to
 * a 2,000-page corpus left a two-entry cache: the hash of the page it edited,
 * before and after.
 *
 * That is not merely a cold cache next time. The updater needs the *previous*
 * token data of a changed page to locate the postings it must remove, so the
 * next edit to any other page had nowhere to read it from and refused, and the
 * full build it told the caller to run started with nothing cached.
 */
#[CoversClass(IncrementalIndexUpdater::class)]
#[CoversClass(PageWordCache::class)]
final class IncrementalCacheRetentionTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $base            = sys_get_temp_dir() . '/scolta-cache-retention-' . uniqid('', true);
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
     * The manifest as it sits on disk: content hash => chunk number.
     *
     * @return array<string, int>
     */
    private function manifest(): array
    {
        $file = $this->stateDir . '/token-cache-manifest.php';
        if (!is_file($file)) {
            return [];
        }
        $data = @unserialize((string) file_get_contents($file), ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }

    /**
     * @param list<ContentItem> $items
     */
    private function fullBuild(array $items): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $result       = $orchestrator->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
        );
        $this->assertTrue($result->success, 'Full build failed: ' . ($result->error ?? ''));
    }

    public function testOneIncrementalEditKeepsEveryOtherEntryInTheTokenCache(): void
    {
        $items = SyntheticCorpus::generate(60, seed: 11);
        $this->fullBuild($items);

        $afterBuild = $this->manifest();
        $this->assertCount(60, $afterBuild, 'A full build caches one entry per page.');

        $updater = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $updater->stageUpsert(SyntheticCorpus::item(30, seed: 11, revision: 1));
        $this->assertSame(1, $updater->commit()->pagesUpdated);

        $afterEdit = $this->manifest();

        // Every hash the build cached is still there. The edited page's old
        // hash included: it is what a later update of that page would need,
        // and it is a full build's job to sweep it, not this one's.
        $this->assertSame(
            [],
            array_diff_key($afterBuild, $afterEdit),
            'An incremental update must not drop token-cache entries for pages it never touched.',
        );

        // Plus the edited page's new hash.
        $this->assertCount(61, $afterEdit);

        // Values are plain chunk numbers, never the negative in-memory marker.
        foreach ($afterEdit as $hash => $chunk) {
            $this->assertGreaterThanOrEqual(0, $chunk, "Manifest entry {$hash} was saved as a seen marker.");
        }
    }

    public function testASecondEditOnAnotherPageStillSucceeds(): void
    {
        $items = SyntheticCorpus::generate(60, seed: 11);
        $this->fullBuild($items);

        $first = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $first->stageUpsert(SyntheticCorpus::item(30, seed: 11, revision: 1));
        $first->commit();

        // The one that used to throw IncrementalUpdateUnavailable, because
        // page 12's previous token data had been pruned by the update to 30.
        $second = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $second->stageUpsert(SyntheticCorpus::item(12, seed: 11, revision: 1));
        $result = $second->commit();

        $this->assertSame(1, $result->pagesUpdated);
        $this->assertGreaterThan(0, $result->chunksRewritten);
        $this->assertCount(62, $this->manifest(), 'Both editions of both edited pages stay cached.');
    }

    /**
     * The consequence that reaches an operator: after an edit, the next full
     * build the adapter runs is still warm.
     */
    public function testAFullBuildAfterAnEditStillFindsEveryUnchangedPageCached(): void
    {
        $items = SyntheticCorpus::generate(60, seed: 11);
        $this->fullBuild($items);

        $edited     = $items;
        $edited[29] = SyntheticCorpus::item(30, seed: 11, revision: 1);

        $updater = new IncrementalIndexUpdater($this->stateDir, $this->outputDir);
        $updater->stageUpsert($edited[29]);
        $updater->commit();

        $cache = new PageWordCache($this->stateDir, new FilesystemDriver());
        $hits  = 0;
        foreach ($edited as $item) {
            if ($cache->get(PhpIndexer::contentHash($item)) !== null) {
                $hits++;
            }
        }

        $this->assertSame(60, $hits, 'Every page of the post-edit corpus must still be a cache hit.');
    }
}
