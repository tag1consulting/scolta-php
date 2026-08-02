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
    /**
     * Built lazily: the token cache's write-buffer size comes from the
     * MemoryBudget, and the budget arrives with the BuildIntent, which the
     * constructor never sees. Constructing it eagerly meant it always used
     * MemoryBudget::default() — so on the shipped Drupal path
     * `--memory-budget=aggressive` widened every buffer in the pipeline except
     * this one. PhpIndexer always passed the budget correctly, which is why
     * the two paths behaved differently under the same flag.
     */
    private ?PageWordCache $cache = null;
    private readonly TimestampManifest $tsManifest;
    private readonly PageTableLedger $ledger;
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
        $this->tsManifest  = new TimestampManifest($stateDir, $this->storage);
        $this->ledger      = new PageTableLedger($stateDir, $this->storage);
    }

    /**
     * The token cache, sized from $budget the first time it is asked for.
     *
     * @param MemoryBudget|null $budget Used only on the first call.
     */
    private function cache(?MemoryBudget $budget = null): PageWordCache
    {
        return $this->cache ??= new PageWordCache(
            $this->stateDir,
            $this->storage,
            chunkSize: ($budget ?? MemoryBudget::default())->chunkSize(),
            maxWriteBufferBytes: ($budget ?? MemoryBudget::default())->tokenCacheChunkBytes(),
        );
    }

    /**
     * The durable ordinal assignment this build reads and writes.
     *
     * Exposed so an adapter can report the tombstone ratio, and so a
     * compaction can reset it before running a full build.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function pageTableLedger(): PageTableLedger
    {
        return $this->ledger;
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

        $budget = $intent->memoryBudget();

        // Order matters, and it is not obvious. A fresh build's prepare() calls
        // BuildState::cleanup(), which unlinks every *file* in the state
        // directory — including token-cache-manifest.php, while leaving the
        // token-cache/ subdirectory of chunk files intact. The cache is usable
        // afterwards only because its manifest was already read into memory.
        // TimestampManifest and PageTableLedger survive the same wipe the same
        // way, both loading in their constructors. Touch the cache here, before
        // prepare(), or every fresh build silently starts with an empty one and
        // re-tokenizes the entire corpus.
        $this->cache($budget);

        try {
            $manifest = $this->coordinator->prepare($intent);
            $telemetry->emit('build_start', ['mode' => $intent->mode()]);

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
            /** @var list<string> Ids seen this build, for pruning the ledger. */
            $seenIds     = [];

            // The generator that yields $pages does the CMS-side gathering, so
            // the time it spends is only visible as the gap between one
            // iteration ending and the next value arriving. Measure it
            // explicitly: without this it hides inside the chunk-loop span
            // along with tokenization and GC, which on a real corpus is 98% of
            // the build and therefore the only number that matters.
            $iter = (function () use ($pages, $telemetry) {
                $it = is_array($pages) ? new \ArrayIterator($pages) : $pages;
                $t0 = hrtime(true);
                foreach ($it as $value) {
                    $telemetry->recordSubTimer('gather_wait', (hrtime(true) - $t0) / 1e9, 1);
                    yield $value;
                    $t0 = hrtime(true);
                }
            })();

            foreach ($iter as $page) {
                if ($page instanceof CachedContentReference) {
                    $t0        = hrtime(true);
                    $tokenData = $this->cache()->get($page->contentHash);
                    $telemetry->recordSubTimer('token_cache_get', (hrtime(true) - $t0) / 1e9, 1);
                    if ($tokenData !== null) {
                        $this->tsManifest->markSeen($page->entityKey);
                        $chunk[] = $this->makeChunkEntry($page, $tokenData, $page->contentHash, $seenIds);
                    }
                    // On cache miss: skip markSeen → manifest entry is pruned →
                    // entity is treated as changed on the next build.
                } else {
                    $hash = PhpIndexer::contentHash($page);
                    if (!$force) {
                        $t0        = hrtime(true);
                        $tokenData = $this->cache()->get($hash);
                        $telemetry->recordSubTimer('token_cache_get', (hrtime(true) - $t0) / 1e9, 1);
                    } else {
                        $tokenData = null;
                    }
                    if ($tokenData === null) {
                        $t0        = hrtime(true);
                        $tokenData = $this->builder->tokenizeItem($page);
                        $telemetry->recordSubTimer('tokenize', (hrtime(true) - $t0) / 1e9, 1);
                        if ($tokenData !== null) {
                            $t0 = hrtime(true);
                            $this->cache()->put($hash, $tokenData);
                            $telemetry->recordSubTimer('token_cache_put', (hrtime(true) - $t0) / 1e9, 1);
                        }
                    }

                    if ($tokenData !== null) {
                        $chunk[] = $this->makeChunkEntry($page, $tokenData, $hash, $seenIds);
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
                        $this->cache()->pruneAndSave();
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
                $this->cache()->pruneAndSave();
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
            // Ids the ledger still holds but the corpus no longer contains have
            // been deleted at the source. Release them so their ordinals are
            // reusable, and tombstone the rows so the page table stays dense.
            $released = $this->ledger->releaseAllExcept($seenIds);
            if ($released !== []) {
                $logger->info('[scolta] {count} pages removed since the last build; their ordinals are now tombstoned.', [
                    'count' => count($released),
                ]);
            }

            $telemetry->emit('merge_start');
            $chunkFiles   = $this->coordinator->chunkFiles();
            $streamWriter = new StreamingFormatWriter(new CborEncoder(), budget: $budget);
            $streamWriter->setTelemetry($telemetry);
            $this->merger->setTelemetry($telemetry);
            $telemetry->emit('writer_start');
            $streamWriter->beginWrite($this->outputDir);
            $this->merger->mergeStreaming($chunkFiles, $streamWriter, $budget);
            $streamWriter->fillTombstones($this->ledger->pageTableSize());
            $streamWriter->endWrite();
            $telemetry->emit('writer_complete');

            $this->atomicSwap();
            $telemetry->emit('swap_complete');

            $totalPagesProcessed = $this->coordinator->pagesProcessed();
            $pagesForReport      = $totalPagesProcessed > 0 ? $totalPagesProcessed : $pagesInRun;
            $chunksWritten       = count($chunkFiles);

            $this->verifyOutputHasFragments($pagesForReport);

            $this->coordinator->release();

            $this->cache()->pruneAndSave();
            $this->tsManifest->pruneAndSave();
            $this->ledger->save();

            // gc_status() gained runs/collected in PHP 7.3; the array shape
            // PHPStan knows does not list them, so read them defensively.
            /** @var array<string, int|bool> $gc */
            $gc = gc_status();
            $telemetry->emit('build_complete', [
                'items'        => $pagesForReport,
                'gc_runs'      => (int) ($gc['runs'] ?? -1),
                'gc_collected' => (int) ($gc['collected'] ?? -1),
            ]);
            $telemetry->emitPhaseSummary();

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
    /**
     * Build one chunk entry, assigning the item's ordinal from the ledger.
     *
     * On a ledger that has never been written, allocate() hands out 0, 1, 2 …
     * in arrival order, which is exactly the running counter this replaced —
     * so a first build produces byte-identical output while populating the
     * ledger for later incremental updates. No mode flag distinguishes the two
     * cases because there is only one case.
     *
     * Allocation happens here rather than at the top of the loop because items
     * whose cleaned body is too short produce no page at all, and an ordinal
     * burned on a page that is never written would leave a permanent tombstone.
     *
     * @return array{item: object, tokenData: array, ordinal: int}
     */
    /**
     * @param array<string, mixed> $tokenData
     * @param list<string>         $seenIds Collects ids present in this build, for pruning.
     * @param-out non-empty-list<string> $seenIds
     * @return array{item: object, tokenData: array<string, mixed>, ordinal: int}
     */
    private function makeChunkEntry(object $page, array $tokenData, string $contentHash, array &$seenIds): array
    {
        $proxy     = $this->makeSlimProxy($page);
        $seenIds[] = (string) $proxy->id;

        return [
            'item'      => $proxy,
            'tokenData' => $tokenData,
            'ordinal'   => $this->ledger->allocate(
                $proxy->id,
                $proxy->url,
                InvertedIndexBuilder::effectiveFilters($proxy),
                InvertedIndexBuilder::effectiveSortable($proxy),
                $contentHash,
            ),
        ];
    }

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
            // Carried so ContentItem::$metadata reaches the fragment. Without
            // it the proxy silently dropped the field and the only route to an
            // arbitrary per-item meta key was `sortable`, which also writes a
            // corpus-wide entry into the eagerly loaded pf_meta sorts table.
            'metadata' => $page->metadata ?? [],
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

        $t0      = hrtime(true);
        $partial = $this->builder->buildFromTokenDataWithOrdinals($chunk);
        $telemetry->recordSubTimer('build_partial', (hrtime(true) - $t0) / 1e9, count($partial['pages']));

        $currentOffset += count($partial['pages']);
        $pagesInRun    += count($partial['pages']);

        $t0 = hrtime(true);
        $this->coordinator->commitChunk($chunkNum, $partial);
        $telemetry->recordSubTimer('commit_chunk', (hrtime(true) - $t0) / 1e9, count($partial['pages']));

        $telemetry->emit("chunk_committed({$chunkNum})", ['items' => count($partial['pages'])]);
        $progress->advance(1, "Chunk {$chunkNum} ({$pagesInRun} pages)");
        unset($partial);

        // Measured, not assumed: PHP's cycle collector fires on its own at a
        // 10,001-entry root buffer and acyclic data is freed at refcount zero
        // without it, so these two calls may be buying nothing. gc_status()
        // runs/collected are reported alongside the timing so the question is
        // answerable from one build log.
        $t0 = hrtime(true);
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        $telemetry->recordSubTimer('gc', (hrtime(true) - $t0) / 1e9, 1);
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
            $streamWriter->setTelemetry($telemetry);
            $this->merger->setTelemetry($telemetry);
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

            $telemetry->emit('build_complete', ['items' => $pagesProcessed]);
            $telemetry->emitPhaseSummary();

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
