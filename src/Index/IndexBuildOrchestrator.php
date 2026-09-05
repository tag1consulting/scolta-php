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

    /**
     * Chunks between two gc_mem_caches() calls.
     *
     * Trimming the allocator's caches is worth doing on a long build and is
     * not worth doing 2,187 times: it measured 5.35 ms per call against a
     * 198 MB heap, so once per chunk spent 2.3 s of a 14.4 s warm build
     * returning blocks that the next chunk immediately asked for again.
     */
    private const MEM_CACHES_EVERY_CHUNKS = 20;

    /**
     * Wall-clock ceiling on the trash sweep a failed build runs on its way out.
     *
     * A ceiling under the CLI too, where an ordinary sweep is given none (see
     * RetiredIndexTrash::defaultBudget()). No budget is the right answer after
     * a publish — the new index is already live and the build may take as long
     * as deletion takes — and the wrong one here, where the caller is waiting
     * to be told the build failed and a serial NFS unlink loop can sit on that
     * report for hours. Two seconds clears a lot on the parallel path and is
     * not felt on the serial one; whatever is left still matches the trash
     * pattern, so the next build or scheduled cleanup resumes it.
     */
    private const FAILED_BUILD_SWEEP_SECONDS = 2.0;

    /** Warning for a report whose sweep left trash on disk. */
    private const TRASH_LEFT_WARNING = 'Retired index cleanup left directories on disk; see the log for which. The next build or sweep will retry.';

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

    /** Chunks committed since the last gc_mem_caches(). */
    private int $chunksSinceMemCaches = 0;
    private readonly TimestampManifest $tsManifest;
    private readonly PageTableLedger $ledger;
    private readonly RetiredIndexTrash $trash;
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
        /**
         * Link unchanged fragments out of the live index instead of re-encoding
         * them. null, the default, decides from the filesystem, because whether
         * a hard link beats a write is a property of the filesystem and the sign
         * flips between the ones this package runs on; see
         * {@see StreamingFormatWriter::linkBeatsWrite()} for the measurements.
         * true forces it on, false forces it off — off is the reference path the
         * differential tests compare against.
         */
        private readonly ?bool $reuseFragments = null,
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
        $this->merger->setStateDir($stateDir);
        $this->storage     = $storage ?? new FilesystemDriver();
        $this->tsManifest  = new TimestampManifest($stateDir, $this->storage);
        $this->ledger      = new PageTableLedger($stateDir, $this->storage);
        $this->trash       = new RetiredIndexTrash($this->storage, $this->outputDir);
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

        // Build the cache here, because this is the only place the budget is
        // in scope: cache() sizes it from the first budget it is handed, and
        // every later call in the loop passes none. Asking for it after
        // prepare() would size it from MemoryBudget::default() instead.
        //
        // It used to have to be here for a second reason, which no longer
        // holds: BuildState::cleanup() unlinked every file in the state
        // directory, token-cache-manifest.php included, and the cache was
        // usable afterwards only because its manifest had already been read
        // into memory. cleanup() now removes only the files BuildState owns,
        // so the last good manifest stays on disk until a completed build
        // atomically replaces it.
        $this->cache($budget);

        try {
            $manifest = $this->coordinator->prepare($intent);
            $telemetry->emit('build_start', ['mode' => $intent->mode()]);

            // A restart means "rebuild from scratch", and the page table is
            // the one piece of state a fresh build deliberately carries
            // forward. Carrying it into a restart made the advice printed by
            // the merge's duplicate-ordinal check unfollowable: the duplicate
            // lives in the ledger's journal, so the restart inherited it,
            // re-indexed the whole corpus, and refused to merge again — the
            // only way out was deleting the journal by hand. Reset before
            // beginBuild(), so the new generation is stamped on an empty
            // table.
            if ($intent->resetsPageTable()) {
                $logger->notice(sprintf(
                    '[scolta] %s: discarding the page-table ledger (%d ordinals) and renumbering from zero.',
                    $intent->mode() === 'restart' ? 'Restart' : 'Ledger reset requested',
                    $this->ledger->pageTableSize(),
                ));
                $this->ledger->reset();
            }

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

            // A resumed segment carries no scope of its own — BuildIntent::resume()
            // is handed nothing but a memory budget — so it inherits the scope
            // the manifest recorded when the build was started.
            $isPartial = $intent->isPartial()
                || ($manifest['scope'] ?? BuildIntent::SCOPE_FULL) === BuildIntent::SCOPE_PARTIAL;
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
                        // Without pruning: this run has not reached the tail of
                        // the corpus, so a page it never looked up is a page it
                        // has not got to yet, not a page that is gone. Pruning
                        // here deleted the token-cache entry and the manifest
                        // entry of every page after the yield point — the exact
                        // state the segment this yield schedules needs.
                        $this->cache()->saveWithoutPruning();
                        $this->tsManifest->saveWithoutPruning();
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
                            error: StatusReport::MEMORY_ABORT,
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
                // The merge has not run, so this build is not over: finalize()
                // completes it in a fresh process. Saving without pruning for
                // the same reason as the yield above — and because the process
                // that picks this up gathers nothing, so whatever is written
                // here is what the next build gets.
                $this->cache()->saveWithoutPruning();
                $this->tsManifest->saveWithoutPruning();
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
                // still the last good one. The two manifests go opposite ways
                // here, and both keep the state recoverable.
                //
                // The token cache is saved WITHOUT pruning: pruning drops the
                // hashes this run did not look up and deletes the chunk files
                // that then have no live entries, which is how a cache the
                // re-run could still have used becomes one it cannot.
                //
                // The timestamp manifest IS pruned, when this run gathered the
                // whole corpus. Its entry is the promise that a page's token
                // data exists, and for the pages that just missed the promise
                // is false: leave it and the next build yields the same
                // unreadable references and aborts again, forever, until
                // somebody passes --force. Dropping it is what lets the next
                // build re-gather those pages from source and self-heal. A
                // resumed segment did not gather the whole corpus, so it is in
                // no position to say whose promise is false.
                $this->cache()->saveWithoutPruning();
                if ($isResume || $isPartial) {
                    $this->tsManifest->saveWithoutPruning();
                } else {
                    $this->tsManifest->pruneAndSave();
                }
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
            //
            // For a full build, ids the ledger still holds but this build never
            // yielded have been deleted at the source. Release them so their
            // ordinals are reusable, and tombstone the rows so the page table
            // stays dense.
            //
            // For a partial build that inference is false and its consequences
            // are total, so the release is replaced by a refusal to publish.
            // See partialScopeRefusal() for why there is no third option.
            if ($isPartial) {
                $refusal = $this->partialScopeRefusal($logger);
                if ($refusal !== null) {
                    $this->cache()->saveWithoutPruning();
                    $this->tsManifest->saveWithoutPruning();
                    $this->coordinator->release();

                    return $this->makeStatusReport(
                        $telemetry,
                        $budget,
                        $startTime,
                        pagesProcessed: $pagesInRun,
                        chunksWritten: $chunkNum,
                        success: false,
                        error: $refusal,
                    );
                }
            } else {
                $released = $this->ledger->releaseStaleRows();
                if ($released !== []) {
                    $logger->info('[scolta] {count} pages removed since the last build; their ordinals are now tombstoned.', [
                        'count' => count($released),
                    ]);
                }
            }

            $this->assertLedgerHasLivePages();

            $telemetry->emit('merge_start');
            $chunkFiles   = $this->coordinator->chunkFiles();
            $streamWriter = new StreamingFormatWriter(new CborEncoder(), budget: $budget);
            $streamWriter->setTelemetry($telemetry);
            $streamWriter->setFragmentReuse($this->reuseFragments);
            $this->merger->setTelemetry($telemetry);
            $telemetry->emit('writer_start');
            $this->clearStagingDir($this->stagedIndexDir());
            $streamWriter->beginWrite($this->outputDir);
            $this->merger->mergeStreaming($chunkFiles, $streamWriter, $budget);
            $streamWriter->fillTombstones($this->ledger->pageTableSize());
            $streamWriter->endWrite();
            $telemetry->emit('writer_complete');

            $totalPagesProcessed = $this->coordinator->pagesProcessed();
            $pagesForReport      = $totalPagesProcessed > 0 ? $totalPagesProcessed : $pagesInRun;
            $chunksWritten       = count($chunkFiles);

            // Checked against the staged directory, before the swap. This used
            // to run after atomicSwap(), so a build that failed the check had
            // already replaced a working index with the one it was declaring
            // unservable — the state observed on production, where a 16166
            // fragment index with 1518 live pages went live and then failed.
            // Refusing here leaves the previously published index serving.
            $this->verifyOutputHasFragments($pagesForReport, $this->stagedIndexDir());

            $this->atomicSwap($logger);
            $telemetry->emit('swap_complete');
            $sweptClean = $this->trash->sweep($logger);

            $this->coordinator->release();

            // The one path that legitimately looked up every live page: a fresh
            // build that reached the end of the corpus in this process, so a
            // hash nobody asked for belongs to a page that is gone.
            //
            // A resumed segment is not that path even when it succeeds. It is
            // handed the whole corpus again — no adapter can translate "pages
            // committed" into a position in its own query — and skips every id
            // the ledger says an earlier segment committed, before it would
            // have looked the page up. So "not looked up" there covers almost
            // the whole corpus, and pruning dropped it: a build that succeeded
            // across three segments kept only the third one's pages.
            //
            // A partial build is not that path either, for the same reason and
            // more plainly: it was handed a subset and told so. Pruning there
            // deletes the token data and the timestamps of every page outside
            // the scope, which makes the next full build a cold one.
            if ($isResume || $isPartial) {
                $this->cache()->saveWithoutPruning();
                $this->tsManifest->saveWithoutPruning();
            } else {
                $this->cache()->pruneAndSave();
                $this->tsManifest->pruneAndSave();
            }
            $this->ledger->save();

            // gc_status() gained runs/collected in PHP 7.3; the array shape
            // PHPStan knows does not list them, so read them defensively.
            /** @var array<string, int|bool> $gc */
            $gc = gc_status();
            $telemetry->emit('build_complete', [
                'items'             => $pagesForReport,
                'gc_runs'           => (int) ($gc['runs'] ?? -1),
                'gc_collected'      => (int) ($gc['collected'] ?? -1),
                'fragments_reused'  => $streamWriter->fragmentsReused(),
                'fragments_written' => $streamWriter->fragmentsWritten(),
            ]);
            if ($streamWriter->fragmentsReused() > 0) {
                $logger->info(
                    '[scolta] {reused} of {total} fragments were unchanged and linked from the previous index.',
                    [
                        'reused' => $streamWriter->fragmentsReused(),
                        'total'  => $streamWriter->fragmentsReused() + $streamWriter->fragmentsWritten(),
                    ],
                );
            }
            $telemetry->emitPhaseSummary();

            return $this->makeStatusReport(
                $telemetry,
                $budget,
                $startTime,
                pagesProcessed: $pagesForReport,
                chunksWritten: $chunksWritten,
                success: true,
                warnings: $sweptClean ? null : self::TRASH_LEFT_WARNING,
            );
        } catch (\Throwable $e) {
            // Sweep before the lock goes: the success path sweeps under it too,
            // and a retry that starts the moment the lock is free would walk
            // the same trash tree this sweep is deleting.
            $warnings = $this->sweepAfterFailure($e, $logger);

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
                error: $isMemoryAbort ? StatusReport::MEMORY_ABORT : $e->getMessage(),
                warnings: $warnings,
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
     * offset and run counter.
     *
     * The partial is freed here rather than at the next loop iteration, and
     * the allocator's caches are trimmed periodically afterwards; see the
     * comment at the trim itself for why no cycle collection happens.
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

        // The question the previous comment here left open is now answered, so
        // the forced collection is gone. Probed on a 20,000-page SML-shaped
        // build, 800 calls across a cold and a warm run: the root buffer sat
        // at a median of 4,092 entries against the engine's own 10,001 trigger,
        // and gc_collect_cycles() returned 0 on every single call — it never
        // once found a cycle to break, because this pipeline's per-chunk data
        // is acyclic and already freed at refcount zero.
        //
        // What it did cost is a scan proportional to the resident heap: 0.68 ms
        // per call below the cold run's median heap against 3.27 ms at or above
        // it, and 5.25 ms per call on the warm run's 198 MB heap. That was 2.1 s
        // of a 14.4 s warm build, buying nothing. The engine collects on its own
        // threshold and raises that threshold after a run that frees little,
        // which is the adaptive behaviour a fixed per-chunk call overrides.
        //
        // gc_mem_caches() stays, because it does something different — it
        // returns unused allocator blocks to the system rather than hunting
        // cycles — but it is not free either (5.35 ms median per call on the
        // warm heap, 2.3 s across the build), so it runs once per
        // MEM_CACHES_EVERY_CHUNKS instead of once per chunk. The sub-timer and
        // the gc_runs/gc_collected fields in build_complete stay so the
        // decision remains answerable from a build log rather than from this
        // comment.
        $t0 = hrtime(true);
        $this->chunksSinceMemCaches++;
        if ($this->chunksSinceMemCaches >= self::MEM_CACHES_EVERY_CHUNKS && function_exists('gc_mem_caches')) {
            gc_mem_caches();
            $this->chunksSinceMemCaches = 0;
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
        ?string $warnings = null,
    ): StatusReport {
        // Every terminal report goes through here, so this is where the run
        // leaves a note for whoever is driving it from another process. See
        // BuildState::recordOutcome(): an exit status cannot tell a voluntary
        // memory yield apart from a merge that found the index corrupt.
        try {
            $this->coordinator->buildState()->recordOutcome($success, $error, $pagesProcessed);
        } catch (\Throwable) {
        }

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
            warnings: $warnings,
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
            // Scope read back off the manifest, because this process never saw
            // the BuildIntent. Without it the deferred merge is a way to get
            // the release build() just refused.
            if ($this->coordinator->declaredScope() === BuildIntent::SCOPE_PARTIAL) {
                $refusal = $this->partialScopeRefusal($logger);
                if ($refusal !== null) {
                    $this->coordinator->release();

                    return $this->makeStatusReport(
                        $telemetry,
                        $budget,
                        $startTime,
                        pagesProcessed: 0,
                        chunksWritten: count($chunkFiles),
                        success: false,
                        error: $refusal,
                    );
                }
            } else {
                $released = $this->ledger->releaseStaleRows();
                if ($released !== []) {
                    $logger->info('[scolta] {count} pages removed since the last build; their ordinals are now tombstoned.', [
                        'count' => count($released),
                    ]);
                }
            }

            $this->assertLedgerHasLivePages();

            $telemetry->emit('merge_start');
            $streamWriter = new StreamingFormatWriter(new CborEncoder(), budget: $budget);
            $streamWriter->setTelemetry($telemetry);
            $streamWriter->setFragmentReuse($this->reuseFragments);
            $this->merger->setTelemetry($telemetry);
            $this->clearStagingDir($this->stagedIndexDir());
            $streamWriter->beginWrite($this->outputDir);
            $this->merger->mergeStreaming($chunkFiles, $streamWriter, $budget);
            $streamWriter->fillTombstones($this->ledger->pageTableSize());
            $streamWriter->endWrite();
            $telemetry->emit('writer_complete');

            $pagesProcessed = $this->coordinator->pagesProcessed();
            $chunksFinalized = count($chunkFiles);

            // Pre-swap, for the reason build() states.
            $this->verifyOutputHasFragments($pagesProcessed, $this->stagedIndexDir());

            $this->atomicSwap($logger);
            $telemetry->emit('swap_complete');
            $sweptClean = $this->trash->sweep($logger);

            $this->coordinator->release();

            // The token cache and the timestamp manifest are left exactly as
            // the gathering segments left them. This pass only merges chunk
            // files: it looked up not one page, so its in-memory copies hold
            // the on-disk manifests with nothing marked as seen, and pruning
            // them keeps nothing at all — it wrote a 6-byte a:0:{} manifest
            // and deleted every file in token-cache/. That is the state a
            // crash followed by `drush scolta:finalize` was leaving behind,
            // and it made the next build fully cold. The ledger is saved
            // because releaseStaleRows() above genuinely changed it.
            $this->ledger->save();

            $telemetry->emit('build_complete', [
                'items'             => $pagesProcessed,
                'fragments_reused'  => $streamWriter->fragmentsReused(),
                'fragments_written' => $streamWriter->fragmentsWritten(),
            ]);
            $telemetry->emitPhaseSummary();

            return $this->makeStatusReport(
                $telemetry,
                $budget,
                $startTime,
                pagesProcessed: $pagesProcessed,
                chunksWritten: $chunksFinalized,
                success: true,
                warnings: $sweptClean ? null : self::TRASH_LEFT_WARNING,
            );
        } catch (\Throwable $e) {
            // Under the lock, as in build().
            $warnings = $this->sweepAfterFailure($e, $logger);

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
                warnings: $warnings,
            );
        }
    }

    private function atomicSwap(LoggerInterface $logger): void
    {
        $buildDir = $this->stagedIndexDir();
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

        // Rename, never delete: the serial unlink loop that used to run here
        // took hours on NFS after the new index was already live, and it
        // read as a hang. The caller sweeps trash right after this swap —
        // post-publish, announced at notice level, and parallelized where
        // the platform allows — with cron/scolta:cleanup as the backstop
        // for builds that die before their sweep.
        if ($this->storage->exists($oldDir) && !$this->trash->retire($oldDir)) {
            // Not fatal: the new index is published. clearStagingDir() will
            // move it aside (or delete it) before the next swap.
            $logger->warning('[scolta] Could not move retired index {dir} aside; the next build will remove it.', [
                'dir' => $oldDir,
            ]);
        }
    }

    /**
     * The directory StreamingFormatWriter writes into, before the swap.
     *
     * atomicSwap() renames it to pagefind/, so it holds the same layout the
     * live index does — which is what lets the integrity checks run against it
     * while the previous index is still the one being served.
     */
    private function stagedIndexDir(): string
    {
        return $this->outputDir . '/.scolta-building';
    }

    /**
     * Delete retired-index trash on the way out of a failed build.
     *
     * clearStagingDir() retires directories before the merge and again inside
     * the swap, but the sweep that collects them used to run only after a
     * successful publish. So every failed merge — OOM, a full disk, the
     * duplicate-ordinal corruption --reset-ledger exists for — left an
     * index-sized tree in trash with nothing to collect it, and each retry
     * added another.
     *
     * Best-effort, in the strict sense: it is bounded
     * (FAILED_BUILD_SWEEP_SECONDS), it never throws, and it never touches the
     * error the caller is being handed. Returns a warning for the failure
     * report when trash is still on disk when it returns, or null when there
     * is nothing left to say.
     */
    private function sweepAfterFailure(\Throwable $failure, LoggerInterface $logger): ?string
    {
        try {
            $pending = $this->trash->trashDirs();
            if ($pending === []) {
                // Nothing was retired — the common case for a failure before
                // the merge, which is most of the try block both callers wrap.
                return null;
            }

            // The one failure where doing more work on the way out is itself
            // harmful. A memory abort is a deliberate yield, not a broken
            // build: the caller answers it by starting a fresh --resume
            // process, and that process sweeps after its own swap. Spending
            // what headroom is left on deletion — sixteen forked children on
            // the parallel path — delays the resume to do work the resume
            // does anyway.
            if ($failure instanceof MemoryThresholdExceededException) {
                $logger->notice(
                    '[scolta] Leaving {count} retired index director(ies) in place: this run stopped on memory pressure, so it is not spending its remaining headroom on deletion. The resumed build or the next scheduled cleanup deletes them: {dirs}.',
                    ['count' => count($pending), 'dirs' => implode(', ', $pending)],
                );

                return 'Retired index cleanup was skipped because this run stopped on memory pressure. The resumed build or the next scheduled cleanup will delete the directories named in the log.';
            }

            if ($this->trash->sweep($logger, self::FAILED_BUILD_SWEEP_SECONDS)) {
                return null;
            }

            $logger->notice(
                '[scolta] Retired index cleanup did not finish before this failed build exited; the next build or scheduled cleanup deletes what is left.',
            );

            return self::TRASH_LEFT_WARNING;
        } catch (\Throwable $sweepFailure) {
            // sweep() is documented not to throw and trashDirs() only lists a
            // directory, but this runs while a build failure is already on its
            // way to the caller. That report is the one that matters, so
            // anything from here is logged and swallowed rather than replacing
            // it.
            $logger->warning(
                '[scolta] Retired index cleanup failed while handling a failed build: {message}. The build error is reported separately.',
                ['message' => $sweepFailure->getMessage()],
            );

            return 'Retired index cleanup failed; see the log. The next build or sweep will retry.';
        }
    }

    /**
     * Clear a staging directory left behind by an interrupted swap.
     *
     * Retiring by rename keeps this O(1) on NFS; inline deletion is only the
     * fallback. Failing loudly rather than pressing on: a staging path that
     * cannot be cleared is a rename() target that is about to fail anyway,
     * and the message names the directory an operator has to remove by hand.
     */
    private function clearStagingDir(string $dir): void
    {
        if (!$this->storage->exists($dir)) {
            return;
        }

        if ($this->trash->retire($dir)) {
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
     * Decide whether a partial build may publish, and say why not if it may not.
     *
     * A scoped build is safe only when its scope happens to cover everything
     * the index already holds. Otherwise there is no correct index for it to
     * publish, and that is a property of the format rather than a gap here:
     *
     *  - The postings come from this run's chunk files alone. mergeStreaming()
     *    reads nothing from the live index, so a page the run did not yield has
     *    no term entries in the output at all.
     *  - Its fragment is not carried over either. Fragment reuse is decided per
     *    writePage() call, and no such call is made for a page the run never
     *    gathered, so fillTombstones() pads the ordinal with an empty row.
     *
     * So the three ways out of a scoped build are: delete the rest of the site
     * (what used to happen — releaseStaleRows() freed 14,648 ordinals and the
     * merge published 1,518 live pages inside a 16,166-row page table); keep the
     * rows live and publish empty fragments under them, which is the same data
     * loss with the ledger now lying about it; or refuse. The refusal leaves the
     * previously published index serving, untouched.
     *
     * The scope-aware path for a small change is IncrementalIndexUpdater, which
     * edits the published index in place instead of rebuilding it.
     *
     * @return string|null Error for the StatusReport, or null when publishing is safe.
     */
    private function partialScopeRefusal(LoggerInterface $logger): ?string
    {
        $stale = $this->ledger->staleRowIds();
        if ($stale === []) {
            // The scope covered every id the ledger holds — a site that only
            // ever indexes one bundle, say. Nothing is out of scope, so there
            // is nothing to protect and nothing to release.
            $logger->info('[scolta] Scoped build covered every page the index holds; publishing normally.');

            return null;
        }

        $error = sprintf(
            'scoped build refused: it gathered %d pages, but the index holds %d more that were outside its '
            . 'scope and it cannot republish them — a merge only carries the pages this run yielded. '
            . 'Publishing would have removed those %d pages from the index. The existing index has been left '
            . 'in place. Re-run without --bundle/--entity-ids for a full rebuild, or let the queue apply the '
            . 'change incrementally.',
            $this->ledger->liveCount() - count($stale),
            count($stale),
            count($stale),
        );
        $logger->error('[scolta] ' . $error);

        return $error;
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
     * $indexRoot is the directory that IS the index — the staging directory
     * pre-swap, or the published pagefind/ directory. Callers pass the staged
     * one: a check that runs after publication cannot protect anything.
     *
     * @throws \RuntimeException If the index does not match the ledger.
     */
    private function verifyOutputHasFragments(int $pagesProcessed, string $indexRoot): void
    {
        // Before the zero-page exit, not after it: zero is the symptom here,
        // not the exemption. Held back until after the exit, a build that lost
        // every page reports success and serves an index of pure tombstones.
        $this->assertLedgerHasLivePages();

        if ($pagesProcessed === 0) {
            return;
        }

        $fragmentDir   = $indexRoot . '/fragment';
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

        self::verifyIndexRootComplete($indexRoot);
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
        self::verifyIndexRootComplete($outputDir . '/pagefind');
    }

    /**
     * The same check, against a directory that is itself an index.
     *
     * Split out so a build can verify its staged output before the swap
     * publishes it, while the public entry point keeps taking the base output
     * directory framework adapters pass.
     *
     * @throws \RuntimeException If the index is missing or malformed.
     */
    private static function verifyIndexRootComplete(string $indexRoot): void
    {
        $entryPath = $indexRoot . '/pagefind-entry.json';
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
