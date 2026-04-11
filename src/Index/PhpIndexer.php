<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Orchestrate the PHP indexing pipeline.
 *
 * Three-phase pipeline:
 * 1. processChunk() — tokenize and index N pages, write partial to disk
 * 2. (repeat for all chunks)
 * 3. finalize() — merge partials, write Pagefind format, atomic swap
 *
 * State persists on disk between invocations, so each chunk can run
 * in a separate queue job (WP Action Scheduler, Drupal Batch, Laravel Bus).
 */
class PhpIndexer
{
    private BuildState $state;
    private InvertedIndexBuilder $builder;
    private IndexMerger $merger;
    private PagefindFormatWriter $writer;

    public function __construct(
        private readonly string $stateDir,
        private readonly string $outputDir,
        ?string $hmacSecret = null,
        string $language = 'en',
    ) {
        $this->state = new BuildState($stateDir, $hmacSecret);

        $tokenizer = new Tokenizer();
        $stemmer = new Stemmer($language);
        $this->builder = new InvertedIndexBuilder($tokenizer, $stemmer);
        $this->merger = new IndexMerger();
        $this->writer = new PagefindFormatWriter(new CborEncoder());
    }

    /**
     * Process a chunk of content items.
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

        // Write to disk.
        $this->state->recordChunk($chunkNumber, $partial);

        return count($partial['pages']);
    }

    /**
     * Finalize the build: merge chunks, write Pagefind format, atomic swap.
     *
     * @return BuildResult
     */
    public function finalize(): BuildResult
    {
        $startTime = microtime(true);

        try {
            // Read all chunks via BuildState (handles HMAC verification).
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

            // Merge all partials.
            $merged = $this->merger->merge($partials);
            $pageCount = count($merged['pages']);

            // Write Pagefind format to .scolta-building.
            $this->writer->write($merged['index'], $merged['pages'], $this->outputDir);

            // Atomic swap.
            $this->atomicSwap();

            // Count output files.
            $fileCount = $this->countFiles($this->outputDir . '/pagefind');

            // Cleanup state.
            $this->state->releaseLock();
            $this->state->cleanup();

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
        if (file_exists($stateFile)) {
            $stored = trim(file_get_contents($stateFile) ?: '');
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

        return hash('sha256', json_encode($data));
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

        if (!is_dir($buildDir)) {
            throw new \RuntimeException('Build directory does not exist');
        }

        // Move build dir to staging.
        rename($buildDir, $newDir);

        // Move current index to old (if exists).
        if (is_dir($finalDir)) {
            rename($finalDir, $oldDir);
        }

        // Move new to final.
        rename($newDir, $finalDir);

        // Remove old.
        if (is_dir($oldDir)) {
            $this->removeDir($oldDir);
        }
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDir(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    /**
     * Count files in a directory recursively.
     */
    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
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
