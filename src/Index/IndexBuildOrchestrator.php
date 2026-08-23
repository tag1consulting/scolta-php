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

    /**
     * Fraction of the items in a run that may miss the token cache on a
     * cached reference before the build refuses to publish.
     *
     * A cached reference carries no body: the whole point is that the
     * gatherer did not load one, because the token cache already holds the
     * page. A miss therefore cannot be recovered in this run — the page is
     * dropped, its ledger row is released as stale, and the merge writes a
     * tombstone in its place. One or two are ordinary (an evicted entry past
     * the manifest cap). A thousand mean the cache is gone, and the index the
     * build is about to swap in is empty.
     *
     * Ten percent is well above the eviction rate a manifest cap produces and
     * well below anything a lost cache looks like.
     */
    private const MAX_CACHED_REFERENCE_MISS_RATIO = 0.10;

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
        $effective = $budget ?? MemoryBudget::default();

        return $this->cache ??= new PageWordCache(
            $this->stateDir,
            $this->storage,
            chunkSize: $effective->chunkSize(),
            maxWriteBufferBytes: $effective->tokenCacheChunkBytes(),
            maxManifestEntries: $effective->tokenCacheManifestEntries(),
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

            // A fresh build takes a new generation; a resumed one inherits the
            // generation its earlier segments stamped, so coverage is tracked
            // across the whole build rather than per process.
            $this->ledger->beginBuild($intent->isFresh());

            $chunkSize = $budget->chunkSize();
            $totalPages = $intent->totalPages() ?? (int) ($manifest['total_pages'] ?? 0);

            // On resume, pick up from where we left off.
            $startChunk    = 0;
            $currentOffset = 0;
            $isResume      = $intent->mode() === 'resume';
            if ($isResume) {
                $startChunk    = (int) ($manifest['chunks_written'] ?? 0);
                $currentOffset = (int) ($manifest['pages_processed'] ?? 0);
                $this->assertResumableLedger($startChunk, $currentOffset);
                $logger->info("[scolta] Resuming from chunk {$startChunk}, page offset {$currentOffset}.");
            }

            $totalChunks = $totalPages > 0 ? (int) ceil($totalPages / $chunkSize) : 1;
            $progress->start($totalChunks, 'Indexing');

            $chunk       = [];
            $chunkNum    = $startChunk;
            $pagesInRun  = 0;
            /** @var list<array{id: string, reason: string}> Items given to the build that produced no page. */
            $skipped     = [];
            $resumeSkips = 0;
            /** @var int Cached references whose entity is already known to produce no page. */
            $expectedEmpty = 0;
            /** @var int Items the run actually considered, resume skips excluded. */
            $itemsSeen = 0;
            /** @var int Cached references whose token data was gone. */
            $cachedRefMisses = 0;

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
                // A resumed build is handed the whole corpus again, because no
                // adapter can reliably translate "pages committed" into a
                // position in its own source query — the offset that used to
                // do this counted pages against a cursor that walks entities,
                // so a corpus with translations skipped the wrong rows. The
                // ledger already records precisely which ids this build
                // committed, so ask it instead.
                if ($isResume && $this->ledger->wasSeenThisBuild((string) $page->id)) {
                    $resumeSkips++;
                    continue;
                }

                $itemsSeen++;

                if ($page instanceof CachedContentReference) {
                    $t0        = hrtime(true);
                    $tokenData = $this->cache()->get($page->contentHash);
                    $telemetry->recordSubTimer('token_cache_get', (hrtime(true) - $t0) / 1e9, 1);
                    if ($tokenData !== null) {
                        $this->tsManifest->markSeen($page->entityKey);
                        $chunk[] = $this->makeChunkEntry($page, $tokenData, $page->contentHash);
                    } elseif ($this->tsManifest->isKnownEmpty($page->contentHash)) {
                        // A body the exporter has already dropped for being too
                        // short. It never had token data and never will, so the
                        // miss is the expected outcome rather than an eviction:
                        // keep the manifest entry (markSeen) and let the next
                        // build skip the entity instead of re-gathering it to
                        // drop it again. Not a warning — nothing is lost.
                        $this->tsManifest->markSeen($page->entityKey);
                        $expectedEmpty++;
                    } else {
                        // On cache miss: skip markSeen → manifest entry is pruned →
                        // entity is treated as changed on the next build.
                        $cachedRefMisses++;
                        $skipped[] = ['id' => (string) $page->id, 'reason' => 'token cache miss on a cached reference'];
                    }
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
                        $chunk[] = $this->makeChunkEntry($page, $tokenData, $hash);
                    } else {
                        $skipped[] = ['id' => (string) $page->id, 'reason' => 'no indexable text after HTML cleaning'];
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

            if ($resumeSkips > 0) {
                $logger->info('[scolta] {count} pages were already committed by an earlier segment of this build.', [
                    'count' => $resumeSkips,
                ]);
            }
            if ($expectedEmpty > 0) {
                $logger->info(
                    '[scolta] {count} unchanged documents produce no indexable page; they were kept in the manifest and not re-gathered.',
                    ['count' => $expectedEmpty],
                );
            }
            $this->logSkippedItems($skipped, $logger);

            // Refuse to publish an index the token cache emptied.
            //
            // A cached reference that misses is a page this run cannot index:
            // it was handed no body to fall back to. The pipeline downstream
            // treats that as "the entity is gone" — releaseStaleRows() frees
            // its ordinal, the merge fills the hole with a tombstone — and the
            // swap publishes the result. That is correct for a page deleted at
            // the source and catastrophic for a page whose cache entry was
            // evicted, and nothing downstream can tell the two apart, because
            // both look like an id the build never committed.
            //
            // So it is decided here, on the only evidence that separates them:
            // how many. A wiped cache misses on nearly every reference, and a
            // build in that state must not reach the merge at all.
            $missBudget = (int) ($itemsSeen * self::MAX_CACHED_REFERENCE_MISS_RATIO);
            if ($cachedRefMisses > 0 && ($pagesInRun === 0 || $cachedRefMisses > $missBudget)) {
                // Nothing has been swapped, so the currently published index is
                // still the last good one. Save the token cache WITHOUT pruning:
                // prepare() unlinked the manifest file at the top of this build
                // and only the in-memory copy is left, so pruning here — or
                // simply returning — is how a recoverable cache becomes an
                // unrecoverable one.
                $this->cache()->saveWithoutPruning();
                $this->coordinator->releaseLockOnly();
                $error = sprintf(
                    'token cache lost; re-run with `--force`: %d of %d unchanged pages had no token data, '
                    . 'so this build would have published %d empty fragments in place of them. '
                    . 'The existing index has been left in place.',
                    $cachedRefMisses,
                    $itemsSeen,
                    $cachedRefMisses,
                );
                $logger->error('[scolta] ' . $error);

                return $this->makeStatusReport(
                    $telemetry,
                    $budget,
                    $startTime,
                    pagesProcessed: $pagesInRun,
                    chunksWritten: $chunkNum,
                    success: false,
                    error: $error,
                );
            }

            // Merge and write.
            // Ids the ledger still holds but this build never yielded have been
            // deleted at the source. Release them so their ordinals are
            // reusable, and tombstone the rows so the page table stays dense.
            $released = $this->ledger->releaseStaleRows();
            if ($released !== []) {
                $logger->info('[scolta] {count} pages removed since the last build; their ordinals are now tombstoned.', [
                    'count' => count($released),
                ]);
            }

            $this->assertLedgerHasLivePages();

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
     * @return array{item: object, tokenData: array<string, mixed>, ordinal: int}
     */
    private function makeChunkEntry(object $page, array $tokenData, string $contentHash): array
    {
        $proxy = $this->makeSlimProxy($page);

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

        // Ordinals reach disk before the chunk that references them. The
        // reverse order is the corruption: a chunk whose ordinals no resumed
        // process can see gets those same numbers handed to different pages,
        // and the merge keeps one page per ordinal.
        $this->ledger->checkpoint();

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

            // The same tail work build() does. Finalize used to skip it, so a
            // deferred merge published an index with an unfilled page table and
            // left the ledger unsaved — the next build then renumbered from
            // whatever survived.
            $released = $this->ledger->releaseStaleRows();
            if ($released !== []) {
                $logger->info('[scolta] {count} pages removed since the last build; their ordinals are now tombstoned.', [
                    'count' => count($released),
                ]);
            }

            $this->assertLedgerHasLivePages();

            $telemetry->emit('merge_start');
            $streamWriter = new StreamingFormatWriter(new CborEncoder(), budget: $budget);
            $streamWriter->setTelemetry($telemetry);
            $this->merger->setTelemetry($telemetry);
            $streamWriter->beginWrite($this->outputDir);
            $this->merger->mergeStreaming($chunkFiles, $streamWriter, $budget);
            $streamWriter->fillTombstones($this->ledger->pageTableSize());
            $streamWriter->endWrite();
            $telemetry->emit('writer_complete');

            $this->atomicSwap();
            $telemetry->emit('swap_complete');

            $pagesProcessed = $this->coordinator->pagesProcessed();
            $chunksFinalized = count($chunkFiles);

            $this->verifyOutputHasFragments($pagesProcessed);

            $this->coordinator->release();

            $this->cache()->pruneAndSave();
            $this->tsManifest->pruneAndSave();
            $this->ledger->save();

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

        // Both staging paths are rename() targets, and rename() onto an
        // existing non-empty directory fails with ENOTEMPTY. A swap that died
        // partway leaves one of them populated, so clearing them is what keeps
        // that failure from wedging every later build. Neither can hold
        // anything but a corpse from a previous run: the index being published
        // is in $buildDir and the live one is at $finalDir.
        $this->clearStagingDir($oldDir);
        $this->clearStagingDir($newDir);

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
     * Refuse a page table in which nothing is alive.
     *
     * A page whose content this build could not read looks exactly like a page
     * that was deleted at the source: neither is committed, so releaseStaleRows()
     * frees the ordinal and the merge pads the hole with a tombstone. One at a
     * time that is correct. All of them at once is a corpus that vanished
     * between two builds, and the difference between "the site was emptied" and
     * "the build could not read the site" is not one this code can make — so it
     * makes neither, and refuses to publish an index in which every document is
     * a tombstone.
     *
     * Called before the merge, where refusing still leaves the previous index
     * in place, and again from the integrity check afterwards for the paths
     * that reach it another way.
     *
     * @throws \RuntimeException When the page table holds ordinals and no live page.
     */
    private function assertLedgerHasLivePages(): void
    {
        $pageTableSize = $this->ledger->pageTableSize();
        if ($pageTableSize === 0 || $this->ledger->liveCount() > 0) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Index integrity check failed: the page table has %d ordinals and none of them is live, '
            . 'so the index would contain nothing but tombstones. Either every page was deleted at '
            . 'the source, or the build could not read the content it was handed. '
            . 'The index must not be served. Re-run with --force to rebuild from the source content.',
            $pageTableSize,
        ));
    }

    /**
     * Verify the finished index accounts for every page the build indexed.
     *
     * The count that matters is the ledger's live rows: one row per id this
     * build kept, and one fragment per row. Comparing against it rather than
     * against "more than zero" is what turns a dropped page into a failed
     * build. An index that is short here has almost always lost pages to
     * colliding ordinals, so the message says so.
     *
     * @throws \RuntimeException If the index does not match the ledger.
     */
    private function verifyOutputHasFragments(int $pagesProcessed): void
    {
        // Before the zero-page exit, not after it: zero is the symptom here,
        // not the exemption. Held back until after the exit, a build that lost
        // every page reports success and serves an index of pure tombstones.
        $this->assertLedgerHasLivePages();

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

        // One fragment per ordinal in the page table: live pages plus the
        // tombstones fillTombstones() pads it out with. A collision shows up
        // here as a page table shorter than the fragments written, because two
        // pages sharing an ordinal still produce two files (the filename
        // hashes the url) but only ever one page-table row.
        $pageTableSize = $this->ledger->pageTableSize();
        if ($pageTableSize > 0 && $fragmentCount !== $pageTableSize) {
            throw new \RuntimeException(sprintf(
                'Index integrity check failed: the page table has %d ordinals but the index contains %d fragments. '
                . 'Pages have shared an ordinal, so posting lists point at the wrong documents. '
                . 'The index must not be served. Re-run with --restart to rebuild from scratch.',
                $pageTableSize,
                $fragmentCount,
            ));
        }

        // Independent bookkeeping: the manifest counts pages as chunks commit,
        // the ledger counts ids it kept. They are written by different code at
        // different times, so a page indexed twice or lost between the two
        // shows up as a disagreement rather than as a quietly short index.
        $liveCount = $this->ledger->liveCount();
        if ($liveCount > 0 && $pagesProcessed !== $liveCount) {
            throw new \RuntimeException(sprintf(
                'Index integrity check failed: the build committed %d pages but the page table holds %d live pages. '
                . 'Documents have been indexed twice or dropped. '
                . 'The index must not be served. Re-run with --restart to rebuild from scratch.',
                $pagesProcessed,
                $liveCount,
            ));
        }

        self::verifyIndexComplete($this->outputDir);
    }

    /**
     * Fail unless the ledger can actually account for the committed chunks.
     *
     * Resuming onto a ledger that has lost its ordinals is the exact condition
     * that used to produce a wrong index in silence: the chunks on disk hold
     * ordinals 0..n, and a ledger that cannot see them hands the same numbers
     * to different pages. State written by a pre-journal build looks like this,
     * so it is refused rather than resumed.
     *
     * @throws \RuntimeException When the state directory cannot be resumed safely.
     */
    private function assertResumableLedger(int $chunksWritten, int $pagesProcessed): void
    {
        if ($chunksWritten === 0 || $pagesProcessed === 0) {
            return;
        }

        if ($this->ledger->pageTableSize() === 0) {
            throw new \RuntimeException(sprintf(
                'Cannot resume: %d chunks holding %d pages are on disk, but the page-table ledger has no '
                . 'ordinal assignments for them. Resuming would hand those ordinals to different pages and '
                . 'produce an index that returns the wrong results. Re-run with --restart to rebuild from scratch.',
                $chunksWritten,
                $pagesProcessed,
            ));
        }
    }

    /**
     * Itemise the documents that reached the build but produced no page.
     *
     * The integrity check subtracts these from the expected fragment count, so
     * they are named rather than absorbed: an unexplained gap is a bug, and an
     * explained one has to be readable in the build log to stay that way.
     *
     * @param list<array{id: string, reason: string}> $skipped
     */
    private function logSkippedItems(array $skipped, LoggerInterface $logger): void
    {
        if ($skipped === []) {
            return;
        }

        $shown = array_slice($skipped, 0, 20);
        $lines = array_map(static fn(array $s): string => "{$s['id']} ({$s['reason']})", $shown);
        $more  = count($skipped) - count($shown);

        $logger->warning('[scolta] {count} documents produced no indexable page and were skipped: {list}{more}', [
            'count' => count($skipped),
            'list'  => implode(', ', $lines),
            'more'  => $more > 0 ? ", and {$more} more" : '',
        ]);
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
