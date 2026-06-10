<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Exception\MemoryThresholdExceededException;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Single authoritative implementation of the chunk-loop indexing pipeline.
 *
 * Framework adapters gather content items, construct a BuildIntent, supply
 * a LoggerInterface and ProgressReporterInterface, then call build(). All
 * chunking, committing, merging, and atomic swap logic lives here.
 *
 * Previously this logic was duplicated across scolta-laravel, scolta-drupal,
 * and scolta-wp (~85 lines each). Those adapters are now thin wrappers.
 */
final class IndexBuildOrchestrator
{
    private const VERSION = '1.0.0';

    /**
     * Fraction of the effective memory limit at which the build voluntarily
     * yields (defers the merge / pauses the chunk loop) to avoid OOM.
     */
    private const MEMORY_PRESSURE_RATIO = 0.75;

    private readonly BuildCoordinator $coordinator;
    private readonly InvertedIndexBuilder $builder;
    private readonly IndexMerger $merger;
    private readonly StorageDriverInterface $storage;
    private readonly PageWordCache $cache;
    private readonly TimestampManifest $tsManifest;
    private readonly string $outputDir;
    /** Warning message emitted when output_dir was already suffixed with /pagefind. */
    private readonly ?string $outputDirNormalizationWarning;

    public function __construct(
        private readonly string $stateDir,
        string $outputDir,
        private readonly ?string $hmacSecret = null,
        private readonly string $language = 'en',
        ?StorageDriverInterface $storage = null,
        /** @var (\Closure(): bool)|null Injected in tests to force voluntary yield without real RSS pressure. */
        private readonly ?\Closure $memoryPressureProbe = null,
    ) {
        // Strip a trailing /pagefind suffix if already present. atomicSwap()
        // always appends /pagefind internally, so a doubly-suffixed path would
        // write the index one directory deeper than the browser expects.
        $normalized = rtrim($outputDir, '/');
        if (str_ends_with($normalized, '/pagefind')) {
            $normalized = substr($normalized, 0, -strlen('/pagefind'));
            $this->outputDirNormalizationWarning = "[scolta] output_dir already ends with '/pagefind'."
                . " The '/pagefind' suffix is appended automatically — set output_dir to the parent directory to silence this warning.";
        } else {
            $this->outputDirNormalizationWarning = null;
        }
        $this->outputDir = $normalized;

        $this->coordinator = new BuildCoordinator($stateDir, $hmacSecret);
        // TODO: Per-document language stemming. Currently the entire index uses
        // one language's stemming rules. Multilingual content is indexed and
        // searchable but stemming quality degrades for non-primary languages.
        // The binary/Pagefind path handles this correctly via <html lang="...">.
        $this->builder     = new InvertedIndexBuilder(new Tokenizer(), new Stemmer($language));
        $this->merger      = new IndexMerger();
        $this->storage     = $storage ?? new FilesystemDriver();
        $this->cache       = new PageWordCache(
            $stateDir,
            $this->storage,
            maxWriteBufferBytes: MemoryBudget::default()->tokenCacheChunkBytes(),
        );
        $this->tsManifest  = new TimestampManifest($stateDir, $this->storage);
    }

    /**
     * Expose the timestamp manifest so gatherers can check changed timestamps
     * before deciding whether to load entity bodies or yield CachedContentReferences.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function getTimestampManifest(): TimestampManifest
    {
        return $this->tsManifest;
    }

    /**
     * Run a complete index build.
     *
     * Items whose content hash is already in the page-word cache are re-indexed
     * from cached token data, skipping HTML cleaning and tokenization. Pass
     * $force = true to bypass cache lookups while still populating the cache
     * (used when --force is passed from a CLI command).
     *
     * @param BuildIntent                                  $intent   Mode and memory budget.
     * @param iterable<ContentItem|CachedContentReference> $pages    Content items or cached references.
     * @param LoggerInterface                              $logger   PSR-3 logger (optional).
     * @param ProgressReporterInterface                    $progress Progress callback (optional).
     * @param bool                                         $force    Skip cache lookups (still populates cache).
     * @since 1.0.0
     * @stability stable
     */
    public function build(
        BuildIntent $intent,
        iterable $pages,
        ?LoggerInterface $logger = null,
        ?ProgressReporterInterface $progress = null,
        bool $force = false,
    ): StatusReport {
        $logger   = $logger   ?? new NullLogger();
        $progress = $progress ?? new NullProgressReporter();
        if ($this->outputDirNormalizationWarning !== null) {
            $logger->warning($this->outputDirNormalizationWarning);
        }
        $logger->notice('[scolta] Using PHP indexer.');
        $startTime = microtime(true);
        $telemetry = new MemoryTelemetry($logger, $intent->memoryBudget());

        try {
            $manifest = $this->coordinator->prepare($intent);
            $telemetry->emit('build_start', ['mode' => $intent->mode()]);

            $budget    = $intent->memoryBudget();
            $chunkSize = $budget->chunkSize();
            $totalPages = $intent->totalPages() ?? (int) ($manifest['total_pages'] ?? 0);

            // On resume, pick up from where we left off.
            $startChunk    = 0;
            $currentOffset = 0;
            if ($intent->mode() === 'resume') {
                $startChunk    = (int) ($manifest['chunks_written'] ?? 0);
                $currentOffset = (int) ($manifest['pages_processed'] ?? 0);
                $logger->info("[scolta] Resuming from chunk {$startChunk}, page offset {$currentOffset}.");
            }

            $totalChunks = $totalPages > 0 ? (int) ceil($totalPages / $chunkSize) : 1;
            $progress->start($totalChunks, 'Indexing');

            $chunk       = [];
            $chunkNum    = $startChunk;
            $pagesInRun  = 0;

            foreach ($pages as $page) {
                if ($page instanceof CachedContentReference) {
                    $tokenData = $this->cache->get($page->contentHash);
                    if ($tokenData !== null) {
                        $this->tsManifest->markSeen($page->entityKey);
                        $chunk[] = ['item' => $this->makeSlimProxy($page), 'tokenData' => $tokenData];
                    }
                    // On cache miss: skip markSeen → manifest entry is pruned →
                    // entity is treated as changed on the next build.
                } else {
                    $hash = PhpIndexer::contentHash($page);
                    $tokenData = (!$force) ? $this->cache->get($hash) : null;
                    if ($tokenData === null) {
                        $tokenData = $this->builder->tokenizeItem($page);
                        if ($tokenData !== null) {
                            $this->cache->put($hash, $tokenData);
                        }
                    }

                    if ($tokenData !== null) {
                        $chunk[] = ['item' => $this->makeSlimProxy($page), 'tokenData' => $tokenData];
                    }
                }

                if (count($chunk) >= $chunkSize) {
                    $this->flushChunk($chunk, $chunkNum, $currentOffset, $pagesInRun, $telemetry, $progress);
                    $chunkNum++;
                    $chunk = [];

                    // Voluntary yield: exit cleanly when heap pressure is high after cleanup.
                    // State is already committed; the next invocation resumes from here.
                    if ($this->isUnderMemoryPressure($telemetry)) {
                        $committedChunks = count($this->coordinator->chunkFiles());
                        $committedPages  = $this->coordinator->buildState()->getPagesProcessed();
                        $this->cache->pruneAndSave();
                        $this->tsManifest->pruneAndSave();
                        $this->coordinator->releaseLockOnly();
                        $logger->info(sprintf(
                            '[scolta] Memory pressure detected after chunk %d — yielding for restart (%d pages committed).',
                            $chunkNum - 1,
                            $committedPages,
                        ));
                        return $this->makeStatusReport(
                            $telemetry,
                            $budget,
                            $startTime,
                            pagesProcessed: $committedPages,
                            chunksWritten: $committedChunks,
                            success: false,
                            error: 'memory_abort',
                        );
                    }
                }
            }

            // Tail chunk.
            if (!empty($chunk)) {
                $this->flushChunk($chunk, $chunkNum, $currentOffset, $pagesInRun, $telemetry, $progress);
                $chunk = [];
            }

            $progress->finish("{$pagesInRun} pages indexed");

            // If RSS is at ≥75% of the effective memory limit after indexing, the
            // heap is too fragmented to run the merge in this process — even small
            // allocations may trigger OOM. Return early so the caller can restart
            // in a fresh process (e.g. via `drush scolta:finalize`).
            $limitBytes   = $telemetry->effectiveLimitBytes();
            $segmentBytes = $telemetry->getCurrentRssBytes();
            if ($limitBytes > 0 && $segmentBytes >= (int) ($limitBytes * self::MEMORY_PRESSURE_RATIO)) {
                $this->cache->pruneAndSave();
                $this->tsManifest->pruneAndSave();
                $this->coordinator->releaseLockOnly();
                $telemetry->emit('finalize_deferred', ['heap_pct' => round($segmentBytes / $limitBytes * 100, 1)]);
                $logger->warning('[scolta] RSS at ' . round($segmentBytes / $limitBytes * 100, 1) . '% of memory limit after indexing. Merge deferred — run `drush scolta:finalize` to complete.');
                return $this->makeStatusReport(
                    $telemetry,
                    $budget,
                    $startTime,
                    pagesProcessed: $pagesInRun,
                    chunksWritten: $chunkNum,
                    success: false,
                    error: 'index_only_complete',
                );
            }

            // Merge and write.
            $telemetry->emit('merge_start');
            $chunkFiles   = $this->coordinator->chunkFiles();
            $streamWriter = new StreamingFormatWriter(new CborEncoder(), budget: $budget);
            $telemetry->emit('writer_start');
            $streamWriter->beginWrite($this->outputDir);
            $this->merger->mergeStreaming($chunkFiles, $streamWriter, $budget);
            $streamWriter->endWrite();
            $telemetry->emit('writer_complete');

            $this->atomicSwap();
            $telemetry->emit('swap_complete');

            $totalPagesProcessed = $this->coordinator->pagesProcessed();
            $pagesForReport      = $totalPagesProcessed > 0 ? $totalPagesProcessed : $pagesInRun;
            $chunksWritten       = count($chunkFiles);

            $this->verifyOutputHasFragments($pagesForReport);

            $this->coordinator->release();

            $this->cache->pruneAndSave();
            $this->tsManifest->pruneAndSave();

            return $this->makeStatusReport(
                $telemetry,
                $budget,
                $startTime,
                pagesProcessed: $pagesForReport,
                chunksWritten: $chunksWritten,
                success: true,
            );
        } catch (\Throwable $e) {
            try {
                $this->coordinator->releaseLockOnly();
            } catch (\Throwable) {
            }

            // MemoryTelemetry throws MemoryThresholdExceededException when RSS
            // crosses the abort percentage. Return a structured error so
            // framework adapters can spawn a fresh --resume process rather than
            // treating this as a hard failure.
            $isMemoryAbort = $e instanceof MemoryThresholdExceededException;

            $committedChunks = 0;
            $committedPages  = 0;
            if ($isMemoryAbort) {
                try {
                    $committedChunks = count($this->coordinator->chunkFiles());
                    $committedPages  = $this->coordinator->buildState()->getPagesProcessed();
                } catch (\Throwable) {
                }
            }

            return $this->makeStatusReport(
                $telemetry,
                $intent->memoryBudget(),
                $startTime,
                pagesProcessed: $committedPages,
                chunksWritten: $committedChunks,
                success: false,
                error: $isMemoryAbort ? 'memory_abort' : $e->getMessage(),
            );
        }
    }

    /**
     * Expose the coordinator for framework adapters that need per-chunk control
     * (e.g. Drupal Batch API, Laravel queue jobs).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function coordinator(): BuildCoordinator
    {
        return $this->coordinator;
    }

    /**
     * Slim proxy: only the fields the index builder needs, dropping bodyHtml
     * so it's freed as soon as the source generator advances instead of being
     * held for the full chunk duration.
     */
    private function makeSlimProxy(object $page): object
    {
        return (object) [
            'id'       => $page->id,
            'url'      => $page->url,
            'date'     => $page->date,
            'siteName' => $page->siteName,
            'language' => $page->language,
            'filters'  => $page->filters,
            'sortable' => $page->sortable,
        ];
    }

    /**
     * Build, commit, and release one chunk of token data, advancing the page
     * offset and run counter. GC runs after the partial is freed so
     * chunk-sized allocations don't accumulate across the loop.
     */
    private function flushChunk(
        array $chunk,
        int $chunkNum,
        int &$currentOffset,
        int &$pagesInRun,
        MemoryTelemetry $telemetry,
        ProgressReporterInterface $progress,
    ): void {
        $telemetry->emit("chunk_start({$chunkNum})");
        $partial = $this->builder->buildFromTokenData($chunk, $currentOffset);
        $currentOffset += count($partial['pages']);
        $pagesInRun    += count($partial['pages']);
        $this->coordinator->commitChunk($chunkNum, $partial);
        $telemetry->emit("chunk_committed({$chunkNum})", ['pages' => count($partial['pages'])]);
        $progress->advance(1, "Chunk {$chunkNum} ({$pagesInRun} pages)");
        unset($partial);
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }

    /**
     * Assemble a StatusReport, filling in the fields that are identical for
     * every report this orchestrator produces (version/indexer identity,
     * memory figures, output dir).
     *
     * @param float|null $durationSeconds Explicit duration override; computed
     *                                    from $startTime when null.
     */
    private function makeStatusReport(
        MemoryTelemetry $telemetry,
        MemoryBudget $budget,
        float $startTime,
        int $pagesProcessed,
        int $chunksWritten,
        bool $success,
        ?string $error = null,
        ?float $durationSeconds = null,
    ): StatusReport {
        return new StatusReport(
            version: self::VERSION,
            pagefindVersion: SupportedVersions::getVersionForMetadata(),
            resolvedIndexer: 'php',
            pagesProcessed: $pagesProcessed,
            chunksWritten: $chunksWritten,
            peakMemoryBytes: $telemetry->getPeakRssBytes(),
            memoryBudgetBytes: $budget->totalBudgetBytes(),
            durationSeconds: $durationSeconds ?? round(microtime(true) - $startTime, 3),
            outputDir: $this->outputDir,
            success: $success,
            error: $error,
        );
    }

    /**
     * Perform the merge + write + swap phases from pre-committed chunks.
     *
     * Called by framework adapters after all ProcessIndexChunk jobs complete.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function finalize(
        MemoryBudget $budget,
        ?LoggerInterface $logger = null,
    ): StatusReport {
        $logger    = $logger ?? new NullLogger();
        if ($this->outputDirNormalizationWarning !== null) {
            $logger->warning($this->outputDirNormalizationWarning);
        }
        $telemetry = new MemoryTelemetry($logger, $budget);
        $startTime = microtime(true);

        try {
            $chunkFiles = $this->coordinator->chunkFiles();
            if (count($chunkFiles) === 0) {
                return $this->makeStatusReport(
                    $telemetry,
                    $budget,
                    $startTime,
                    pagesProcessed: 0,
                    chunksWritten: 0,
                    success: false,
                    error: 'No chunk files found in state directory.',
                    durationSeconds: 0.0,
                );
            }

            $telemetry->emit('merge_start');
            $streamWriter = new StreamingFormatWriter(new CborEncoder(), budget: $budget);
            $streamWriter->beginWrite($this->outputDir);
            $this->merger->mergeStreaming($chunkFiles, $streamWriter, $budget);
            $streamWriter->endWrite();
            $telemetry->emit('writer_complete');

            $this->atomicSwap();
            $telemetry->emit('swap_complete');

            $pagesProcessed = $this->coordinator->pagesProcessed();
            $chunksFinalized = count($chunkFiles);

            $this->verifyOutputHasFragments($pagesProcessed);

            $this->coordinator->release();

            return $this->makeStatusReport(
                $telemetry,
                $budget,
                $startTime,
                pagesProcessed: $pagesProcessed,
                chunksWritten: $chunksFinalized,
                success: true,
            );
        } catch (\Throwable $e) {
            try {
                $this->coordinator->releaseLockOnly();
            } catch (\Throwable) {
            }

            return $this->makeStatusReport(
                $telemetry,
                $budget,
                $startTime,
                pagesProcessed: 0,
                chunksWritten: 0,
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    private function atomicSwap(): void
    {
        $buildDir = $this->outputDir . '/.scolta-building';
        $finalDir = $this->outputDir . '/pagefind';
        $oldDir   = $this->outputDir . '/.scolta-old';
        $newDir   = $this->outputDir . '/.scolta-new';

        if (!$this->storage->exists($buildDir)) {
            throw new \RuntimeException('Build directory does not exist: ' . $buildDir);
        }

        if (!$this->storage->move($buildDir, $newDir)) {
            throw new \RuntimeException("Failed to stage build directory: {$buildDir} → {$newDir}");
        }

        if ($this->storage->exists($finalDir)) {
            if (!$this->storage->move($finalDir, $oldDir)) {
                throw new \RuntimeException("Failed to retire previous index: {$finalDir} → {$oldDir}");
            }
        }

        if (!$this->storage->move($newDir, $finalDir)) {
            throw new \RuntimeException("Failed to publish new index: {$newDir} → {$finalDir}");
        }

        if ($this->storage->exists($oldDir)) {
            $this->storage->deleteDirectory($oldDir);
        }
    }

    /**
     * Return true when the process should yield to avoid OOM.
     *
     * In production: checks whether current RSS has reached 75% of the effective
     * memory limit (PHP limit or cgroup limit, whichever is lower).
     *
     * In tests: delegates to the injected $memoryPressureProbe closure so tests
     * can trigger the yield path without actual memory pressure.
     */
    private function isUnderMemoryPressure(MemoryTelemetry $telemetry): bool
    {
        if ($this->memoryPressureProbe !== null) {
            return ($this->memoryPressureProbe)();
        }

        $limit = $telemetry->effectiveLimitBytes();
        if ($limit <= 0) {
            return false;
        }

        return $telemetry->getCurrentRssBytes() >= (int) ($limit * self::MEMORY_PRESSURE_RATIO);
    }

    /**
     * Verify the output directory contains at least one fragment file.
     *
     * A successful build with pages to index MUST produce fragment files.
     * Zero fragments with non-zero page count indicates a silent write failure.
     *
     * @throws \RuntimeException If pages were indexed but the index is empty.
     */
    private function verifyOutputHasFragments(int $pagesProcessed): void
    {
        if ($pagesProcessed === 0) {
            return;
        }

        $fragmentDir   = $this->outputDir . '/pagefind/fragment';
        $fragmentCount = is_dir($fragmentDir)
            ? count(glob($fragmentDir . '/*.pf_fragment') ?: [])
            : 0;

        if ($fragmentCount === 0) {
            throw new \RuntimeException(
                "Build processed {$pagesProcessed} pages but the output index contains zero fragment files. "
                . 'The write may have failed silently. Check filesystem permissions and available space.',
            );
        }

        self::verifyIndexComplete($this->outputDir);
    }

    /**
     * Verify that a completed index is usable: pagefind-entry.json exists and parses.
     *
     * Framework adapters MUST call this (or check StatusReport::$success) before
     * exiting 0. A build that aborts without producing a valid pagefind-entry.json
     * must exit non-zero — otherwise deploy pipelines route traffic to dead search.
     *
     * @param string $outputDir The base output directory (pagefind/ will be appended).
     * @throws \RuntimeException If the index is missing or malformed.
     * @since 1.0.0
     * @stability stable
     */
    public static function verifyIndexComplete(string $outputDir): void
    {
        $entryPath = $outputDir . '/pagefind/pagefind-entry.json';
        if (!file_exists($entryPath)) {
            throw new \RuntimeException(
                "Index verification failed: pagefind-entry.json not found at {$entryPath}. "
                . 'The build did not produce a usable index. Do not exit 0.',
            );
        }

        $content = file_get_contents($entryPath);
        if ($content === false) {
            throw new \RuntimeException(
                "Index verification failed: cannot read pagefind-entry.json at {$entryPath}.",
            );
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['version']) || !isset($data['languages'])) {
            throw new \RuntimeException(
                "Index verification failed: pagefind-entry.json is malformed (missing 'version' or 'languages' key). "
                . 'The index may be incomplete or corrupted.',
            );
        }
    }

}
