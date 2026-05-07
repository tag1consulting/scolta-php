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
     * @param \Tag1\Scolta\Export\ContentItem[] $items
     * @param int $chunkNumber Chunk number (0-based).
     * @param int|null $totalPages Total pages across all chunks.
     * @param bool $force Skip cache lookups (still populates cache on tokenization).
     * @return int Number of pages processed in this chunk.
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

        $tokenDataList = [];
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
                $tokenDataList[] = ['item' => (object) [
                    'id'       => $item->id,
                    'url'      => $item->url,
                    'date'     => $item->date,
                    'siteName' => $item->siteName,
                    'language' => $item->language,
                    'filters'  => $item->filters,
                ], 'tokenData' => $tokenData];
            }
        }

        $partial = $this->builder->buildFromTokenData($tokenDataList, $this->currentPageOffset);
        $this->currentPageOffset += count($partial['pages']);

        $this->coordinator->commitChunk($chunkNumber, $partial);

        return count($partial['pages']);
    }

    /**
     * Finalize the build: stream-merge chunks, write Pagefind format, atomic swap.
     */
    public function finalize(): BuildResult
    {
        $startTime = microtime(true);

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

            $peakMb    = round(memory_get_peak_usage(true) / 1_048_576, 1);
            $pageCount = $this->coordinator->pagesProcessed();

            $this->atomicSwap();

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
     */
    public static function computeFingerprint(array $items): string
    {
        $data = array_map(fn ($item) => $item->id . ':' . hash('sha256', $item->bodyHtml), $items);
        sort($data);

        return hash('sha256', 'php-indexer-v1:' . json_encode($data));
    }

    /**
     * Compute a content hash for a single item (for smart rebuild cache).
     */
    public static function contentHash(\Tag1\Scolta\Export\ContentItem $item): string
    {
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';

        return hash($algo, $item->url . "\0" . $item->bodyHtml);
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

        $this->storage->move($buildDir, $newDir);

        if ($this->storage->exists($finalDir)) {
            $this->storage->move($finalDir, $oldDir);
        }

        $this->storage->move($newDir, $finalDir);

        if ($this->storage->exists($oldDir)) {
            $this->storage->deleteDirectory($oldDir);
        }
    }

    private function countFiles(string $dir): int
    {
        if (!$this->storage->exists($dir)) {
            return 0;
        }

        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

}
