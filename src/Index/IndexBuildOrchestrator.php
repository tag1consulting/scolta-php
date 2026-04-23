<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    private readonly BuildCoordinator $coordinator;
    private readonly InvertedIndexBuilder $builder;
    private readonly IndexMerger $merger;
    private readonly StorageDriverInterface $storage;

    public function __construct(
        private readonly string $stateDir,
        private readonly string $outputDir,
        private readonly ?string $hmacSecret = null,
        private readonly string $language = 'en',
        ?StorageDriverInterface $storage = null,
    ) {
        $this->coordinator = new BuildCoordinator($stateDir, $hmacSecret);
        $this->builder     = new InvertedIndexBuilder(new Tokenizer(), new Stemmer($language));
        $this->merger      = new IndexMerger();
        $this->storage     = $storage ?? new FilesystemDriver();
    }

    /**
     * Run a complete index build.
     *
     * @param BuildIntent               $intent   Mode and memory budget.
     * @param iterable<ContentItem>     $pages    All content items to index.
     * @param LoggerInterface           $logger   PSR-3 logger (optional).
     * @param ProgressReporterInterface $progress Progress callback (optional).
     */
    public function build(
        BuildIntent $intent,
        iterable $pages,
        ?LoggerInterface $logger = null,
        ?ProgressReporterInterface $progress = null,
    ): StatusReport {
        $logger   = $logger   ?? new NullLogger();
        $progress = $progress ?? new NullProgressReporter();
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
                $chunk[] = $page;

                if (count($chunk) >= $chunkSize) {
                    $telemetry->emit("chunk_start({$chunkNum})");
                    $partial = $this->builder->build($chunk, $currentOffset);
                    $currentOffset += count($partial['pages']);
                    $pagesInRun    += count($partial['pages']);
                    $this->coordinator->commitChunk($chunkNum, $partial);
                    $telemetry->emit("chunk_committed({$chunkNum})", ['pages' => count($partial['pages'])]);
                    $progress->advance(1, "Chunk {$chunkNum} ({$pagesInRun} pages)");
                    $chunkNum++;
                    $chunk = [];
                    unset($partial);
                }
            }

            // Tail chunk.
            if (!empty($chunk)) {
                $telemetry->emit("chunk_start({$chunkNum})");
                $partial = $this->builder->build($chunk, $currentOffset);
                $pagesInRun += count($partial['pages']);
                $this->coordinator->commitChunk($chunkNum, $partial);
                $telemetry->emit("chunk_committed({$chunkNum})", ['pages' => count($partial['pages'])]);
                $progress->advance(1, "Chunk {$chunkNum} ({$pagesInRun} pages)");
                unset($partial, $chunk);
            }

            $progress->finish("{$pagesInRun} pages indexed");

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
            $chunksWritten       = count($chunkFiles);
            $this->coordinator->release();

            return new StatusReport(
                version: '0.3.0',
                pagefindVersion: SupportedVersions::getVersionForMetadata(),
                resolvedIndexer: 'php',
                pagesProcessed: $totalPagesProcessed > 0 ? $totalPagesProcessed : $pagesInRun,
                chunksWritten: $chunksWritten,
                peakMemoryBytes: memory_get_peak_usage(true),
                memoryBudgetBytes: $budget->totalBudgetBytes(),
                durationSeconds: round(microtime(true) - $startTime, 3),
                outputDir: $this->outputDir,
                success: true,
            );
        } catch (\Throwable $e) {
            try {
                $this->coordinator->releaseLockOnly();
            } catch (\Throwable) {
            }

            return new StatusReport(
                version: '0.3.0',
                pagefindVersion: SupportedVersions::getVersionForMetadata(),
                resolvedIndexer: 'php',
                pagesProcessed: 0,
                chunksWritten: 0,
                peakMemoryBytes: memory_get_peak_usage(true),
                memoryBudgetBytes: $intent->memoryBudget()->totalBudgetBytes(),
                durationSeconds: round(microtime(true) - $startTime, 3),
                outputDir: $this->outputDir,
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Expose the coordinator for framework adapters that need per-chunk control
     * (e.g. Drupal Batch API, Laravel queue jobs).
     */
    public function coordinator(): BuildCoordinator
    {
        return $this->coordinator;
    }

    /**
     * Perform the merge + write + swap phases from pre-committed chunks.
     *
     * Called by framework adapters after all ProcessIndexChunk jobs complete.
     */
    public function finalize(
        MemoryBudget $budget,
        ?LoggerInterface $logger = null,
    ): StatusReport {
        $logger    = $logger ?? new NullLogger();
        $telemetry = new MemoryTelemetry($logger, $budget);
        $startTime = microtime(true);

        try {
            $chunkFiles = $this->coordinator->chunkFiles();
            if (count($chunkFiles) === 0) {
                return new StatusReport(
                    version: '0.3.0',
                    pagefindVersion: SupportedVersions::getVersionForMetadata(),
                    resolvedIndexer: 'php',
                    pagesProcessed: 0,
                    chunksWritten: 0,
                    peakMemoryBytes: memory_get_peak_usage(true),
                    memoryBudgetBytes: $budget->totalBudgetBytes(),
                    durationSeconds: 0.0,
                    outputDir: $this->outputDir,
                    success: false,
                    error: 'No chunk files found in state directory.',
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
            $this->coordinator->release();

            return new StatusReport(
                version: '0.3.0',
                pagefindVersion: SupportedVersions::getVersionForMetadata(),
                resolvedIndexer: 'php',
                pagesProcessed: $pagesProcessed,
                chunksWritten: count($chunkFiles),
                peakMemoryBytes: memory_get_peak_usage(true),
                memoryBudgetBytes: $budget->totalBudgetBytes(),
                durationSeconds: round(microtime(true) - $startTime, 3),
                outputDir: $this->outputDir,
                success: true,
            );
        } catch (\Throwable $e) {
            try {
                $this->coordinator->releaseLockOnly();
            } catch (\Throwable) {
            }

            return new StatusReport(
                version: '0.3.0',
                pagefindVersion: SupportedVersions::getVersionForMetadata(),
                resolvedIndexer: 'php',
                pagesProcessed: 0,
                chunksWritten: 0,
                peakMemoryBytes: memory_get_peak_usage(true),
                memoryBudgetBytes: $budget->totalBudgetBytes(),
                durationSeconds: round(microtime(true) - $startTime, 3),
                outputDir: $this->outputDir,
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

        $this->storage->move($buildDir, $newDir);

        if ($this->storage->exists($finalDir)) {
            $this->storage->move($finalDir, $oldDir);
        }

        $this->storage->move($newDir, $finalDir);

        if ($this->storage->exists($oldDir)) {
            $this->storage->deleteDirectory($oldDir);
        }
    }
}
