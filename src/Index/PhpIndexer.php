<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Orchestrate the PHP indexing pipeline.
 *
 * Public API is preserved for backward compatibility; internals delegate to
 * BuildCoordinator and IndexBuildOrchestrator. Framework adapters should
 * prefer IndexBuildOrchestrator::build() directly for new code.
 *
 * Bug fixed (0.3.0): processChunk() no longer calls cleanup() + initiateBuild()
 * unconditionally on chunk 0. That wiped resume state. Initialization is now
 * handled by BuildCoordinator::prepare(), which only fires on a fresh/restart
 * intent and is called at most once per build.
 */
class PhpIndexer
{
    private readonly BuildCoordinator $coordinator;
    private readonly InvertedIndexBuilder $builder;
    private readonly IndexMerger $merger;
    private readonly StorageDriverInterface $storage;
    private readonly MemoryBudget $budget;
    private readonly PageWordCache $cache;

    /** Global page offset for sequential page numbering across chunks. */
    private int $currentPageOffset = 0;

    /** Whether prepare() has been called for this build session. */
    private bool $prepared = false;

    public function __construct(
        private readonly string $stateDir,
        private readonly string $outputDir,
        ?string $hmacSecret = null,
        string $language = 'en',
        ?StorageDriverInterface $storage = null,
        ?MemoryBudget $budget = null,
    ) {
        $this->storage     = $storage ?? new FilesystemDriver();
        $this->coordinator = new BuildCoordinator($stateDir, $hmacSecret);
        $this->budget      = $budget ?? MemoryBudget::default();

        $tokenizer = new Tokenizer();
        $stemmer   = new Stemmer($language);
        $this->builder = new InvertedIndexBuilder($tokenizer, $stemmer);
        $this->merger  = new IndexMerger();
        $this->cache   = new PageWordCache(
            $stateDir,
            $this->storage,
            chunkSize: $this->budget->chunkSize(),
            maxWriteBufferBytes: $this->budget->tokenCacheChunkBytes(),
        );
    }

    /**
     * Process a chunk of content items.
     *
     * Items whose content hash is already in the page-word cache are re-indexed
     * from cached token data, skipping the expensive HTML cleaning and tokenization
     * step. Items not in the cache are tokenized and their token data is stored for
     * future builds. Pass $force = true to bypass cache lookups while still
     * populating the cache (useful when --force is passed from a CLI command).
     *
     * Uses a generator internally so that token arrays (the largest per-item
     * allocation) are freed after each item is indexed, rather than holding all
     * items' token data in memory simultaneously.
     *
     * @param \Tag1\Scolta\Export\ContentItem[] $items
     * @param int $chunkNumber Chunk number (0-based).
     * @param int|null $totalPages Total pages across all chunks.
     * @param bool $force Skip cache lookups (still populates cache on tokenization).
     * @return int Number of pages processed in this chunk.
     * @since 1.0.0
     * @stability stable
     */
    public function processChunk(array $items, int $chunkNumber, ?int $totalPages = null, bool $force = false): int
    {
        // Prepare once on the first chunk — fixes the resume-state wipe bug.
        if (!$this->prepared) {
            $intent = BuildIntent::fresh(
                $totalPages ?? count($items),
                $this->budget,
                ['language' => 'en'],
            );
            $this->coordinator->prepare($intent);
            $this->prepared = true;
        }

        $itemStream = $this->tokenizeItems($items, $force);
        $partial = $this->builder->buildFromItemStream($itemStream, $this->currentPageOffset);
        $this->currentPageOffset += count($partial['pages']);

        $this->coordinator->commitChunk($chunkNumber, $partial);

        return count($partial['pages']);
    }

    /**
     * Yield tokenized items one at a time so token arrays are freed after indexing.
     *
     * @param \Tag1\Scolta\Export\ContentItem[] $items
     * @return \Generator<int, array{item: object, tokenData: array}>
     */
    private function tokenizeItems(array $items, bool $force): \Generator
    {
        foreach ($items as $item) {
            $hash = self::contentHash($item);
            $tokenData = (!$force) ? $this->cache->get($hash) : null;
            if ($tokenData === null) {
                $tokenData = $this->builder->tokenizeItem($item);
                if ($tokenData !== null) {
                    $this->cache->put($hash, $tokenData);
                }
            }

            if ($tokenData !== null) {
                yield ['item' => (object) [
                    'id'       => $item->id,
                    'url'      => $item->url,
                    'date'     => $item->date,
                    'siteName' => $item->siteName,
                    'language' => $item->language,
                    'filters'  => $item->filters,
                    'sortable' => $item->sortable,
                    // See IndexBuildOrchestrator::makeSlimProxy(): the proxy has
                    // to carry metadata or ContentItem::$metadata is dead on the
                    // PHP indexer path.
                    'metadata' => $item->metadata,
                ], 'tokenData' => $tokenData];
            }
        }
    }

    /**
     * Finalize the build: stream-merge chunks, write Pagefind format, atomic swap.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function finalize(): BuildResult
    {
        $startTime = microtime(true);
        $telemetry = new MemoryTelemetry(new \Psr\Log\NullLogger(), $this->budget);

        try {
            $chunkFiles = $this->coordinator->chunkFiles();
            if (count($chunkFiles) === 0) {
                return new BuildResult(
                    success: false,
                    message: 'No chunks to merge',
                    pageCount: 0,
                    fileCount: 0,
                    elapsedSeconds: 0,
                    error: 'No chunk files found in state directory',
                );
            }

            $streamWriter = new StreamingFormatWriter(new CborEncoder(), budget: $this->budget);
            $streamWriter->beginWrite($this->outputDir);
            $this->merger->mergeStreaming($chunkFiles, $streamWriter, $this->budget);
            $streamWriter->endWrite();

            $peakMb    = round($telemetry->getPeakRssBytes() / 1_048_576, 1);
            $pageCount = $this->coordinator->pagesProcessed();

            $this->atomicSwap();

            IndexBuildOrchestrator::verifyIndexComplete($this->outputDir);

            $fileCount = $this->countFiles($this->outputDir . '/pagefind');

            $this->coordinator->release();
            $this->prepared = false;

            $this->cache->pruneAndSave();

            $elapsed = microtime(true) - $startTime;

            return new BuildResult(
                success: true,
                message: "Built index for {$pageCount} pages ({$fileCount} files, peak {$peakMb} MB)",
                pageCount: $pageCount,
                fileCount: $fileCount,
                elapsedSeconds: round($elapsed, 3),
            );
        } catch (\Throwable $e) {
            $this->coordinator->releaseLockOnly();
            $elapsed = microtime(true) - $startTime;

            return new BuildResult(
                success: false,
                message: 'Build failed',
                pageCount: 0,
                fileCount: 0,
                elapsedSeconds: round($elapsed, 3),
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Check if a build is needed by comparing content fingerprints.
     *
     * @param \Tag1\Scolta\Export\ContentItem[] $items
     * @return string|null New fingerprint if build needed, null if up to date.
     * @since 1.0.0
     * @stability stable
     */
    public function shouldBuild(array $items): ?string
    {
        $fingerprint = self::computeFingerprint($items);

        $stateFile = $this->outputDir . '/.scolta-state';
        if ($this->storage->exists($stateFile)) {
            $stored = trim($this->storage->get($stateFile));
            if ($stored === $fingerprint) {
                return null;
            }
        }

        return $fingerprint;
    }

    /**
     * Compute a deterministic fingerprint for a set of content items.
     *
     * @param \Tag1\Scolta\Export\ContentItem[] $items
     * @since 1.0.0
     * @stability stable
     */
    public static function computeFingerprint(array $items): string
    {
        $data = array_map(fn($item) => $item->id . ':' . hash('sha256', $item->bodyHtml), $items);
        sort($data);

        return hash('sha256', 'php-indexer-v1:' . json_encode($data));
    }

    /**
     * Key identifying one item's cached tokenization.
     *
     * Must cover every input the cached value depends on. PageWordCache stores
     * `titleTokens`, `cleanTitle`, `bodyTokens`, `urlTokens`, `wordCount` and
     * `content`, so:
     *
     *  - the **title** belongs in the key. It produces `cleanTitle` and
     *    `titleTokens` outright, and `HtmlCleaner::clean()` strips a leading
     *    title match from the body, so it reaches `content` too. Until 1.2.0 it
     *    was absent, and a title-only edit silently reindexed the old title.
     *  - the **language** belongs in the key. It selects the Snowball stemmer,
     *    so the same bytes tokenized as English and as Spanish are different
     *    cached values.
     *  - the date, filters, sortable values and metadata do NOT belong: they
     *    reach the fragment straight from the ContentItem and never touch the
     *    cached tokens. Including them would miss the cache on every build for
     *    no benefit.
     *
     * The `v2:` prefix is a format version. It changes every key, so the first
     * build after upgrading repopulates the token cache from scratch — on a
     * 109,308-page corpus that is a 965 MB cache rebuilt once. That is the
     * price of the two correctness fixes above and there is no cheaper one:
     * the old keys cannot be distinguished from correct ones.
     *
     * @since 1.0.0
     * @stability stable
     */
    public static function contentHash(\Tag1\Scolta\Export\ContentItem $item): string
    {
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';

        return hash($algo, implode("\0", [
            // v3 adds attachmentText. The bump is the invalidation: every
            // cached token array predates the attachmentTokens field, and a
            // stale hit would index a page short that whole bucket.
            'v3:',
            $item->language,
            $item->title,
            $item->url,
            $item->bodyHtml,
            $item->attachmentText,
        ]));
    }

    private function atomicSwap(): void
    {
        $buildDir = $this->outputDir . '/.scolta-building';
        $finalDir = $this->outputDir . '/pagefind';
        $oldDir   = $this->outputDir . '/.scolta-old';
        $newDir   = $this->outputDir . '/.scolta-new';

        if (!$this->storage->exists($buildDir)) {
            throw new \RuntimeException('Build directory does not exist');
        }

        // Both staging paths are rename() targets, and rename() onto an
        // existing non-empty directory fails with ENOTEMPTY. A swap that died
        // partway leaves one of them populated, so clearing them is what keeps
        // that failure from wedging every later build. Neither can hold
        // anything but a corpse from a previous run: the index being published
        // is in $buildDir and the live one is at $finalDir.
        $this->clearStagingDir($oldDir);
        $this->clearStagingDir($newDir);

        $this->storage->move($buildDir, $newDir);

        if ($this->storage->exists($finalDir)) {
            $this->storage->move($finalDir, $oldDir);
        }

        $this->storage->move($newDir, $finalDir);

        if ($this->storage->exists($oldDir)) {
            $this->storage->deleteDirectory($oldDir);
        }
    }

    /**
     * Remove a staging directory left behind by an interrupted swap.
     *
     * Failing loudly rather than pressing on: a staging path that cannot be
     * cleared is a rename() target that is about to fail anyway, and the
     * message names the directory an operator has to remove by hand.
     */
    private function clearStagingDir(string $dir): void
    {
        if (!$this->storage->exists($dir)) {
            return;
        }

        if (!$this->storage->deleteDirectory($dir)) {
            throw new \RuntimeException("Failed to clear stale staging directory: {$dir}");
        }
    }

    private function countFiles(string $dir): int
    {
        if (!$this->storage->exists($dir)) {
            return 0;
        }

        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

}
