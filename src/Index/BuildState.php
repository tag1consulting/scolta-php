<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Manage build state across chunk invocations.
 *
 * Supports resumable indexing: state persists on disk between process
 * invocations so each chunk can run in a separate queue job.
 */
class BuildState
{
    private const LOCK_FILE = 'lock';
    private const MANIFEST_FILE = 'manifest.json';

    /** Maximum lock age before considering it stale (1 hour). */
    private const STALE_LOCK_SECONDS = 3600;

    /** Temp-file suffix used for atomic manifest writes. */
    private const MANIFEST_TMP_SUFFIX = '.tmp';

    /** @var resource|null Open file handle holding the exclusive flock. */
    private $lockHandle = null;

    public function __construct(
        private readonly string $stateDir,
        private readonly ?string $hmacSecret = null,
    ) {
        if (!is_dir($stateDir) && !mkdir($stateDir, 0755, true)) {
            throw new \RuntimeException("Failed to create state directory: {$stateDir}");
        }
    }

    /**
     * Initiate a new build.
     *
     * Uses flock(LOCK_EX | LOCK_NB) for atomic lock acquisition, eliminating
     * the TOCTOU race between check and write. The file handle is kept open
     * (and locked) until releaseLock() is called.
     *
     * @param array $manifest Initial manifest data (total_pages, chunk_size, language, etc.).
     * @return bool True if lock acquired, false if build already running.
     */
    public function initiateBuild(array $manifest): bool
    {
        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;

        // Release a stale lock before attempting acquisition. This handles the
        // case where a prior process died (segfault, OOM) and left the lock
        // file behind. If the OS already released the flock, flock() below
        // would succeed regardless — but we also clear the file so subsequent
        // isRunning()/shouldResume() calls see a clean state.
        if (file_exists($lockFile)) {
            $lockData = file_get_contents($lockFile);
            if ($lockData !== false && $this->isLockStale($lockData)) {
                // Suppress: file may already be removed by concurrent process (TOCTOU-safe cleanup).
                @unlink($lockFile);
            }
        }

        // Open the lock file for writing (creates it if missing).
        $fp = fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        // Attempt a non-blocking exclusive lock.
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }

        // We hold the lock — write PID and timestamp for diagnostics.
        ftruncate($fp, 0);
        fwrite($fp, getmypid() . ':' . time());
        fflush($fp);

        // Keep the handle open; releaseLock() will flock(LOCK_UN) + fclose().
        $this->lockHandle = $fp;

        // Write manifest.
        $manifest = array_merge([
            'version' => '1.0.0',
            'language' => 'en',
            'pagefind_version' => SupportedVersions::BUNDLED_VERSION,
            'total_pages' => 0,
            'pages_processed' => 0,
            'chunk_size' => 100,
            'chunks_written' => 0,
            'started_at' => gmdate('c'),
            'fingerprint' => '',
            'status' => 'building',
        ], $manifest);

        $this->commitManifest($manifest);

        return true;
    }

    /**
     * Record a completed chunk in v2 streaming format.
     *
     * Writes a ChunkWriter v2 file so finalize() can stream pages and terms
     * without loading the full chunk into RAM.
     *
     * @param int   $chunkNumber Chunk number (0-based).
     * @param array $partialData Partial index data from InvertedIndexBuilder.
     */
    public function recordChunk(int $chunkNumber, array $partialData): void
    {
        $filename = sprintf('chunk-%03d.dat', $chunkNumber);
        $path     = $this->stateDir . '/' . $filename;

        (new ChunkWriter())->write($path, $partialData, $this->hmacSecret);

        // Update manifest.
        $manifest = $this->readManifest();
        if ($manifest !== null) {
            $manifest['chunks_written']  = $chunkNumber + 1;
            $manifest['pages_processed'] = ($manifest['pages_processed'] ?? 0) + count($partialData['pages'] ?? []);
            $this->commitManifest($manifest);
        }
    }

    /**
     * Read a chunk from disk (v2 streaming format only).
     *
     * @param int $chunkNumber Chunk number (0-based).
     * @return array The chunk data as {pages: ..., index: ...}.
     * @throws \RuntimeException If file missing, HMAC invalid, or data malformed.
     */
    public function readChunk(int $chunkNumber): array
    {
        $filename = sprintf('chunk-%03d.dat', $chunkNumber);
        $path     = $this->stateDir . '/' . $filename;

        if (!file_exists($path)) {
            throw new \RuntimeException("Chunk file not found: {$filename}");
        }

        if ($this->hmacSecret !== null) {
            $reader = new ChunkReader($path);
            if (!$reader->verifyHmac($this->hmacSecret)) {
                throw new \RuntimeException("HMAC verification failed for chunk: {$filename}");
            }
        }

        // CRC32 is always written (0.3.3+). Validates data integrity without
        // a shared secret — detects disk corruption or partial writes.
        // Pre-0.3.3 chunks have no CRC32 in the footer; verifyCrc32() returns
        // true for those (backward-compatible).
        $reader = new ChunkReader($path);
        if (!$reader->verifyCrc32()) {
            throw new \RuntimeException(
                "CRC32 validation failed for chunk: {$filename}. "
                . 'The chunk may be corrupted — delete the state directory and re-run a fresh build.'
            );
        }

        $reader = new ChunkReader($path);
        $pages  = [];
        foreach ($reader->openPages() as $pageNum => $pageData) {
            $pages[$pageNum] = $pageData;
        }
        $index = [];
        foreach ($reader->openIndex() as [$term, $termData]) {
            $index[$term] = $termData;
        }

        return ['pages' => $pages, 'index' => $index];
    }

    /**
     * Release the build lock.
     */
    public function releaseLock(): void
    {
        $this->dropLockFileOnly();

        $manifest = $this->readManifest();
        if ($manifest !== null) {
            $manifest['status'] = 'idle';
            $this->commitManifest($manifest);
        }
    }

    /**
     * Release the lock handle and delete the lock file without touching
     * the manifest status, leaving the build resumable.
     */
    public function releaseLockOnly(): void
    {
        $this->dropLockFileOnly();
    }

    private function dropLockFileOnly(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }

        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;
        if (file_exists($lockFile)) {
            // Suppress: file may already be removed by concurrent process (TOCTOU-safe cleanup).
            @unlink($lockFile);
        }
    }

    /**
     * Check if a partial build exists that can be resumed.
     *
     * @return array|null Manifest if resumable, null if fresh start needed.
     */
    public function shouldResume(): ?array
    {
        $manifest = $this->readManifest();
        if ($manifest === null || ($manifest['status'] ?? '') !== 'building') {
            return null;
        }

        // Clear stale locks.
        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;
        if (file_exists($lockFile)) {
            $lockData = file_get_contents($lockFile);
            if ($lockData !== false && $this->isLockStale($lockData)) {
                unlink($lockFile);
            }
        }

        return $manifest;
    }

    /**
     * Get paths to all chunk files written so far.
     *
     * @return string[]
     */
    public function getChunkFiles(): array
    {
        $manifest = $this->readManifest();
        $chunksWritten = $manifest['chunks_written'] ?? 0;
        $files = [];

        for ($i = 0; $i < $chunksWritten; $i++) {
            $path = $this->stateDir . '/' . sprintf('chunk-%03d.dat', $i);
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Check whether a build is currently in progress.
     *
     * Returns true when the manifest shows status = 'building' AND the lock
     * file exists (i.e. a live process holds the lock). A stale lock (PID
     * dead or older than STALE_LOCK_SECONDS) is not considered running.
     *
     * @since 0.2.0
     * @stability experimental
     */
    public function isRunning(): bool
    {
        $manifest = $this->readManifest();
        if ($manifest === null || ($manifest['status'] ?? '') !== 'building') {
            return false;
        }

        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;
        if (!file_exists($lockFile)) {
            return false;
        }

        $lockData = file_get_contents($lockFile);
        if ($lockData === false) {
            return false;
        }

        return !$this->isLockStale($lockData);
    }

    /**
     * Return build progress as a fraction between 0.0 and 1.0.
     *
     * Computed as chunks_written / max(1, ceil(total_pages / chunk_size)).
     * Returns 0.0 when no manifest is present.
     *
     * @since 0.2.0
     * @stability experimental
     */
    public function getProgress(): float
    {
        $manifest = $this->readManifest();
        if ($manifest === null) {
            return 0.0;
        }

        $totalPages  = (int) ($manifest['total_pages'] ?? 0);
        $chunkSize   = (int) ($manifest['chunk_size'] ?? 100);
        $chunksWritten = (int) ($manifest['chunks_written'] ?? 0);

        $totalChunks = $totalPages > 0 ? (int) ceil($totalPages / max(1, $chunkSize)) : 1;

        return min(1.0, $chunksWritten / $totalChunks);
    }

    /**
     * Return the ISO 8601 timestamp when the current build started.
     *
     * Returns null when no manifest is present.
     *
     * @since 0.2.0
     * @stability experimental
     */
    public function getStartTime(): ?string
    {
        $manifest = $this->readManifest();

        return $manifest['started_at'] ?? null;
    }

    /**
     * Return the number of pages processed so far in the current build.
     *
     * Returns 0 when no manifest is present.
     *
     * @since 0.2.0
     * @stability experimental
     */
    public function getPagesProcessed(): int
    {
        $manifest = $this->readManifest();

        return (int) ($manifest['pages_processed'] ?? 0);
    }

    /**
     * Return the ISO 8601 timestamp of the last completed build.
     *
     * Derived from the manifest file's mtime when the build status is 'idle'.
     * Returns null when no completed build record exists.
     *
     * @since 0.2.0
     * @stability experimental
     */
    public function getLastBuildTime(): ?string
    {
        $manifest = $this->readManifest();
        if ($manifest === null || ($manifest['status'] ?? '') !== 'idle') {
            return null;
        }

        $path = $this->stateDir . '/' . self::MANIFEST_FILE;
        $mtime = @filemtime($path);

        return $mtime !== false ? gmdate('c', $mtime) : null;
    }

    /**
     * Clean up all state files.
     */
    public function cleanup(): void
    {
        if (!is_dir($this->stateDir)) {
            return;
        }

        $files = glob($this->stateDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Write the manifest atomically: write to .tmp, then rename.
     *
     * A process crash during the write leaves at most a .tmp file, which
     * readManifest() reads as a fallback. After rename() succeeds the write is
     * durable — rename() on POSIX is atomic; on Windows it is best-effort
     * (falls back to copy+delete).
     *
     * @throws \RuntimeException on I/O failure.
     */
    private function commitManifest(array $manifest): void
    {
        $manifestPath = $this->stateDir . '/' . self::MANIFEST_FILE;
        $tempPath     = $manifestPath . self::MANIFEST_TMP_SUFFIX;

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write manifest temp file: {$tempPath}");
        }

        if (!rename($tempPath, $manifestPath)) {
            // Suppress: temp file may already be removed by concurrent process (TOCTOU-safe cleanup).
            @unlink($tempPath);
            throw new \RuntimeException("Failed to atomic-rename manifest: {$tempPath} → {$manifestPath}");
        }
    }

    /**
     * Read the manifest file.
     *
     * Primary path: manifest.json. If it is absent or contains invalid JSON
     * (e.g. partial write during a crash), falls back to manifest.json.tmp —
     * which may be a complete write that never got renamed. If neither file
     * yields valid JSON, returns null (fresh build).
     */
    private function readManifest(): ?array
    {
        $path    = $this->stateDir . '/' . self::MANIFEST_FILE;
        $tmpPath = $path . self::MANIFEST_TMP_SUFFIX;

        foreach ([$path, $tmpPath] as $candidate) {
            if (!file_exists($candidate)) {
                continue;
            }
            $data = file_get_contents($candidate);
            if ($data === false) {
                continue;
            }
            $manifest = json_decode($data, true);
            if (is_array($manifest)) {
                return $manifest;
            }
        }

        return null;
    }

    /**
     * Check if a lock is stale (PID dead or too old).
     *
     * Primary check: PID + timestamp written into the lock file content.
     * Fallback: if the content is malformed (e.g. corrupted write), falls back
     * to lock file mtime — platform-independent and unaffected by PID reuse.
     */
    private function isLockStale(string $lockData): bool
    {
        $parts = explode(':', $lockData, 2);
        if (count($parts) === 2) {
            [$pid, $timestamp] = $parts;

            // Check age from embedded timestamp.
            if (time() - (int) $timestamp > self::STALE_LOCK_SECONDS) {
                return true;
            }

            // Check if PID is still alive (POSIX only).
            if (function_exists('posix_kill')) {
                return !posix_kill((int) $pid, 0);
            }

            return false;
        }

        // Malformed content — fall back to lock file mtime.
        $lockPath = $this->stateDir . '/' . self::LOCK_FILE;
        $mtime    = @filemtime($lockPath);

        return $mtime === false || (time() - $mtime > self::STALE_LOCK_SECONDS);
    }
}
