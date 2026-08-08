<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\TimestampManifest;

/**
 * A body-less entity must be gathered once, not once per build.
 *
 * The gatherer writes every entity it loads into the manifest, because it
 * filters on changed timestamps and never sees a body. ContentExporter then
 * drops the ones whose cleaned text is too short to index. So on the next build
 * those entities come back as CachedContentReferences whose token cache lookup
 * can only miss — they never had tokens — and a miss used to prune the manifest
 * entry, which made the build after that re-load the entity to drop it again.
 * On a production corpus that was 11% of the documents re-gathered every warm
 * build, forever, under a warning that reads like data loss.
 *
 * The distinction this pins is between the two things a miss can mean: an
 * entity known to produce no page (keep the entry, gather nothing) and an
 * evicted token cache entry (prune, re-gather). Collapsing them in the safe
 * direction is the churn; collapsing them in the other direction would drop a
 * real page out of the index, so both halves are asserted here.
 */
#[CoversClass(IndexBuildOrchestrator::class)]
#[CoversClass(TimestampManifest::class)]
final class KnownEmptyEntityRebuildTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $base            = sys_get_temp_dir() . '/scolta-known-empty-' . uniqid('', true);
        $this->stateDir  = $base . '/state';
        $this->outputDir = $base . '/out';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir(dirname($this->stateDir));
    }

    // -------------------------------------------------------------------------
    // The regression
    // -------------------------------------------------------------------------

    /**
     * Two consecutive warm builds: the body-less entity is gathered on the
     * first and nothing on the second, because its manifest entry survives.
     */
    public function test_a_body_less_entity_keeps_its_manifest_entry_across_builds(): void
    {
        $this->firstBuild();

        // Warm build: the gatherer sees both timestamps unchanged, so it loads
        // neither body and yields cached references built from the manifest.
        $logger       = new RecordingLogger();
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $manifest     = $orchestrator->getTimestampManifest();

        $this->assertNotNull($manifest->get('short'), 'First build did not record the short entity.');

        $result = $orchestrator->build(
            BuildIntent::fresh(2, MemoryBudget::conservative()),
            [
                $this->cachedReference($manifest, 'article'),
                $this->cachedReference($manifest, 'short'),
            ],
            $logger,
        );
        $this->assertTrue($result->success, 'Warm build failed: ' . ($result->error ?? ''));

        // Survives pruneAndSave — read from disk, not from the live object.
        $reloaded = new TimestampManifest($this->stateDir, new \Tag1\Scolta\Storage\FilesystemDriver());
        $this->assertNotNull(
            $reloaded->get('short'),
            'The body-less entity was pruned from the manifest, so the next build re-gathers it.',
        );
        $this->assertNotNull($reloaded->get('article'), 'The indexed entity was pruned from the manifest.');
    }

    /**
     * The warning is for lost work. An entity that never had an indexable page
     * is not lost work, and 13k lines of it hide the misses that are.
     */
    public function test_a_body_less_entity_does_not_warn(): void
    {
        $this->firstBuild();

        $logger       = new RecordingLogger();
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $manifest     = $orchestrator->getTimestampManifest();

        $orchestrator->build(
            BuildIntent::fresh(2, MemoryBudget::conservative()),
            [
                $this->cachedReference($manifest, 'article'),
                $this->cachedReference($manifest, 'short'),
            ],
            $logger,
        );

        $this->assertSame(
            [],
            $logger->matching('warning', 'produced no indexable page'),
            'A known-empty entity was reported as a skipped document.',
        );
        $this->assertNotSame(
            [],
            $logger->matching('info', 'produce no indexable page'),
            'The expected-empty count was not reported at all.',
        );
    }

    /**
     * The other half: a real page whose token data was evicted must still be
     * pruned and re-gathered. Marking every miss as seen would quietly drop it
     * from the index instead.
     */
    public function test_a_genuine_token_cache_miss_is_still_pruned_and_reported(): void
    {
        $this->firstBuild();

        $logger       = new RecordingLogger();
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $manifest     = $orchestrator->getTimestampManifest();

        // Same entity, hash the token cache has never heard of — what an
        // eviction looks like from here.
        $evicted = new CachedContentReference(
            entityKey: 'article',
            contentHash: 'evicted-hash-not-in-the-token-cache',
            id: 'article',
            url: '/article',
            date: '2026-01-01',
            siteName: 'Test',
            language: 'en',
            filters: [],
        );

        $orchestrator->build(
            BuildIntent::fresh(2, MemoryBudget::conservative()),
            [$evicted, $this->cachedReference($manifest, 'short')],
            $logger,
        );

        $reloaded = new TimestampManifest($this->stateDir, new \Tag1\Scolta\Storage\FilesystemDriver());
        $this->assertNull(
            $reloaded->get('article'),
            'An evicted entity kept its manifest entry, so its page is gone until a --force build.',
        );
        $this->assertNotSame(
            [],
            $logger->matching('warning', 'produced no indexable page'),
            'An evicted entity was silently absorbed into the expected-empty count.',
        );
    }

    // -------------------------------------------------------------------------
    // The exporter side of the contract
    // -------------------------------------------------------------------------

    public function test_filter_items_records_the_hash_it_drops(): void
    {
        $manifest = new TimestampManifest($this->stateDir, new \Tag1\Scolta\Storage\FilesystemDriver());
        $exporter = new ContentExporter($this->outputDir);

        $kept = iterator_to_array($exporter->filterItems([self::article(), self::short()], $manifest), false);

        $this->assertCount(1, $kept);
        $this->assertSame('article', $kept[0]->id);
        $this->assertTrue($manifest->isKnownEmpty(PhpIndexer::contentHash(self::short())));
        $this->assertFalse($manifest->isKnownEmpty(PhpIndexer::contentHash(self::article())));
    }

    public function test_filter_items_records_nothing_without_a_manifest(): void
    {
        $exporter = new ContentExporter($this->outputDir);
        $manifest = new TimestampManifest($this->stateDir, new \Tag1\Scolta\Storage\FilesystemDriver());

        $kept = iterator_to_array($exporter->filterItems([self::article(), self::short()]), false);

        $this->assertCount(1, $kept);
        $this->assertSame(0, $manifest->knownEmptyCount());
    }

    /**
     * filterItems() and hasIndexableText() must be one decision, or the drop
     * and the record can disagree about which items exist downstream.
     */
    public function test_the_drop_gate_and_the_predicate_agree(): void
    {
        $exporter = new ContentExporter($this->outputDir);

        foreach ([self::article(), self::short()] as $item) {
            $kept = iterator_to_array($exporter->filterItems([$item]), false);
            $this->assertSame(
                $exporter->hasIndexableText($item),
                $kept !== [],
                sprintf('filterItems() and hasIndexableText() disagree about item %s.', $item->id),
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The build that puts both entities in the manifest and indexes only one —
     * a gatherer's put() followed by the exporter's filter, as the adapters run
     * them.
     */
    private function firstBuild(): void
    {
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $manifest     = $orchestrator->getTimestampManifest();
        $exporter     = new ContentExporter($this->outputDir);

        foreach ([self::article(), self::short()] as $item) {
            $manifest->put($item->id, 1_700_000_000, [[
                'hash'     => PhpIndexer::contentHash($item),
                'id'       => $item->id,
                'url'      => $item->url,
                'date'     => $item->date,
                'siteName' => $item->siteName,
                'language' => $item->language,
                'filters'  => $item->filters,
                'sortable' => $item->sortable,
                'metadata' => $item->metadata,
            ]]);
        }

        $items  = $exporter->filterItems([self::article(), self::short()], $manifest);
        $result = $orchestrator->build(BuildIntent::fresh(1, MemoryBudget::conservative()), $items);

        $this->assertTrue($result->success, 'First build failed: ' . ($result->error ?? ''));
    }

    /**
     * Rebuild the reference the gatherer would yield for an unchanged entity.
     */
    private function cachedReference(TimestampManifest $manifest, string $entityKey): CachedContentReference
    {
        $entry = $manifest->get($entityKey);
        $this->assertNotNull($entry, "No manifest entry for {$entityKey}.");
        $item = $entry['items'][0];

        return new CachedContentReference(
            entityKey: $entityKey,
            contentHash: $item['hash'],
            id: $item['id'],
            url: $item['url'],
            date: $item['date'],
            siteName: $item['siteName'],
            language: $item['language'],
            filters: $item['filters'],
            sortable: $item['sortable'] ?? [],
            metadata: $item['metadata'] ?? [],
        );
    }

    private static function article(): ContentItem
    {
        return new ContentItem(
            id: 'article',
            title: 'Cardiology Overview',
            bodyHtml: '<p>' . str_repeat('The heart pumps blood through the vascular system. ', 12) . '</p>',
            url: '/article',
            date: '2026-01-01',
            siteName: 'Test',
        );
    }

    /**
     * A real shape from the reported corpus: a title and a body that survives
     * cleaning as far less than the 50-character minimum.
     */
    private static function short(): ContentItem
    {
        return new ContentItem(
            id: 'short',
            title: 'Untitled',
            bodyHtml: '<p>See link.</p>',
            url: '/short',
            date: '2026-01-01',
            siteName: 'Test',
        );
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

/**
 * Minimal PSR-3 sink — the assertions are about which level a message lands on,
 * so the level has to be preserved rather than flattened into one list.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /**
     * @return list<string>
     */
    public function matching(string $level, string $needle): array
    {
        $hits = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $needle)) {
                $hits[] = $record['message'];
            }
        }

        return $hits;
    }
}
