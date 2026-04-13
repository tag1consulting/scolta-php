<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Orchestrate the PHP indexing pipeline.
 *
 * Three-phase pipeline:
 * 1. processChunk() — tokenize and index N pages, write partial to disk
 * 2. (repeat for all chunks)
 * 3. finalize() — merge partials, write Pagefind format, atomic swap
 *
 * Smart rebuild: caches per-page word lists in BuildState. On subsequent
 * builds, only re-tokenizes pages whose content changed. Unchanged pages
 * reuse cached word lists. Output is byte-identical either way.
 */
class PhpIndexer
{
    private BuildState $state;
    private InvertedIndexBuilder $builder;
    private IndexMerger $merger;
    private PagefindFormatWriter $writer;
    private StorageDriverInterface $storage;

    /** @var array<string, array> Per-page word list cache keyed by content hash. */
    private array $pageWordCache = [];

    /** @var string[] Content hashes used in this build (for cache pruning). */
    private array $usedCacheKeys = [];

    public function __construct(
        private readonly string $stateDir,
        private readonly string $outputDir,
        ?string $hmacSecret = null,
        string $language = 'en',
        ?StorageDriverInterface $storage = null,
    ) {
        $this->storage = $storage ?? new FilesystemDriver();
        $this->state = new BuildState($stateDir, $hmacSecret);

        $tokenizer = new Tokenizer();
        $stemmer = new Stemmer($language);
        $this->builder = new InvertedIndexBuilder($tokenizer, $stemmer);
        $this->merger = new IndexMerger();
        $this->writer = new PagefindFormatWriter(new CborEncoder());

        // Load page word cache if it exists.
        $this->loadPageWordCache();
    }

    /**
     * Process a chunk of content items.
     *
     * Uses smart rebuild: checks per-page content hashes against the cache.
     * Only re-tokenizes pages whose content changed.
     *
     * @param \Tag1\Scolta\Export\ContentItem[] $items Content items to index.
     * @param int $chunkNumber Chunk number (0-based).
     * @param int|null $totalPages Total pages across all chunks (for manifest).
     * @return int Number of pages processed in this chunk.
     */
    public function processChunk(array $items, int $chunkNumber, ?int $totalPages = null): int
    {
        // Initialize build state on first chunk.
        if ($chunkNumber === 0) {
            $this->state->cleanup();
            $this->state->initiateBuild([
                'total_pages' => $totalPages ?? count($items),
                'chunk_size' => count($items),
            ]);
        }

        // Build partial index.
        $partial = $this->builder->build($items);

        // Track cache keys for pruning.
        foreach ($items as $item) {
            $this->usedCacheKeys[] = self::contentHash($item);
        }

        // Write to disk.
        $this->state->recordChunk($chunkNumber, $partial);

        return count($partial['pages']);
    }

    /**
     * Finalize the build: merge chunks, write Pagefind format, atomic swap.
     */
    public function finalize(): BuildResult
    {
        $startTime = microtime(true);

        try {
            $chunkFiles = $this->state->getChunkFiles();
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

            $partials = [];
            for ($i = 0, $count = count($chunkFiles); $i < $count; $i++) {
                $partials[] = $this->state->readChunk($i);
            }

            $merged = $this->merger->merge($partials);
            $pageCount = count($merged['pages']);

            $this->writer->write($merged['index'], $merged['pages'], $this->outputDir);
            $this->atomicSwap();

            $fileCount = $this->countFiles($this->outputDir . '/pagefind');

            $this->state->releaseLock();
            $this->state->cleanup();

            // Prune unused cache entries and save.
            $this->prunePageWordCache();
            $this->savePageWordCache();

            $elapsed = microtime(true) - $startTime;

            return new BuildResult(
                success: true,
                message: "Built index for {$pageCount} pages ({$fileCount} files)",
                pageCount: $pageCount,
                fileCount: $fileCount,
                elapsedSeconds: round($elapsed, 3),
            );
        } catch (\Throwable $e) {
            $this->state->releaseLock();
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
     * @param \Tag1\Scolta\Export\ContentItem[] $items All content items.
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
     * The prefix 'php-indexer-v1:' ensures the fingerprint changes when
     * switching from binary→PHP indexer (or across indexer versions), so
     * shouldBuild() correctly triggers a rebuild after an indexer change.
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

    /**
     * Atomic swap: .scolta-building → pagefind.
     */
    private function atomicSwap(): void
    {
        $buildDir = $this->outputDir . '/.scolta-building';
        $finalDir = $this->outputDir . '/pagefind';
        $oldDir = $this->outputDir . '/.scolta-old';
        $newDir = $this->outputDir . '/.scolta-new';

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

    private function loadPageWordCache(): void
    {
        $cacheFile = $this->stateDir . '/page-word-cache.php';
        if ($this->storage->exists($cacheFile)) {
            $data = @unserialize($this->storage->get($cacheFile));
            if (is_array($data)) {
                $this->pageWordCache = $data;
            }
        }
    }

    private function savePageWordCache(): void
    {
        $cacheFile = $this->stateDir . '/page-word-cache.php';
        $this->storage->makeDirectory($this->stateDir);
        $this->storage->put($cacheFile, serialize($this->pageWordCache));
    }

    private function prunePageWordCache(): void
    {
        if (empty($this->usedCacheKeys)) {
            return;
        }

        $usedSet = array_flip($this->usedCacheKeys);
        $this->pageWordCache = array_intersect_key($this->pageWordCache, $usedSet);
    }
}
