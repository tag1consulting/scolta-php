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

        file_put_contents(
            $this->stateDir . '/' . self::MANIFEST_FILE,
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        return true;
    }

    /**
     * Record a completed chunk.
     *
     * @param int $chunkNumber Chunk number (0-based).
     * @param array $partialData Partial index data from InvertedIndexBuilder.
     */
    public function recordChunk(int $chunkNumber, array $partialData): void
    {
        $serialized = serialize($partialData);

        if ($this->hmacSecret !== null) {
            $hmac = hash_hmac('sha256', $serialized, $this->hmacSecret, true);
            $serialized = $hmac . $serialized;
        }

        $filename = sprintf('chunk-%03d.dat', $chunkNumber);
        file_put_contents($this->stateDir . '/' . $filename, $serialized);

        // Update manifest.
        $manifest = $this->readManifest();
        if ($manifest !== null) {
            $manifest['chunks_written'] = $chunkNumber + 1;
            $manifest['pages_processed'] = ($manifest['pages_processed'] ?? 0) + count($partialData['pages'] ?? []);
            file_put_contents(
                $this->stateDir . '/' . self::MANIFEST_FILE,
                json_encode($manifest, JSON_PRETTY_PRINT)
            );
        }
    }

    /**
     * Read a chunk from disk.
     *
     * @param int $chunkNumber Chunk number (0-based).
     * @return array The unserialized chunk data.
     * @throws \RuntimeException If file missing or HMAC invalid.
     */
    public function readChunk(int $chunkNumber): array
    {
        $filename = sprintf('chunk-%03d.dat', $chunkNumber);
        $path = $this->stateDir . '/' . $filename;

        if (!file_exists($path)) {
            throw new \RuntimeException("Chunk file not found: {$filename}");
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Failed to read chunk file: {$filename}");
        }

        if ($this->hmacSecret !== null) {
            if (strlen($data) < 32) {
                throw new \RuntimeException("Chunk file too small for HMAC: {$filename}");
            }

            $storedHmac = substr($data, 0, 32);
            $serialized = substr($data, 32);

            $expectedHmac = hash_hmac('sha256', $serialized, $this->hmacSecret, true);
            if (!hash_equals($storedHmac, $expectedHmac)) {
                throw new \RuntimeException("HMAC verification failed for chunk: {$filename}");
            }

            $data = $serialized;
        }

        $result = unserialize($data);
        if (!is_array($result)) {
            throw new \RuntimeException("Invalid chunk data in: {$filename}");
        }

        return $result;
    }

    /**
     * Release the build lock.
     */
    public function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }

        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        $manifest = $this->readManifest();
        if ($manifest !== null) {
            $manifest['status'] = 'idle';
            file_put_contents(
                $this->stateDir . '/' . self::MANIFEST_FILE,
                json_encode($manifest, JSON_PRETTY_PRINT)
            );
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
     * Read the manifest file.
     */
    private function readManifest(): ?array
    {
        $path = $this->stateDir . '/' . self::MANIFEST_FILE;
        if (!file_exists($path)) {
            return null;
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return null;
        }

        $manifest = json_decode($data, true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Check if a lock is stale (PID dead or too old).
     */
    private function isLockStale(string $lockData): bool
    {
        $parts = explode(':', $lockData, 2);
        if (count($parts) !== 2) {
            return true;
        }

        [$pid, $timestamp] = $parts;

        // Check age.
        if (time() - (int) $timestamp > self::STALE_LOCK_SECONDS) {
            return true;
        }

        // Check if PID is still alive (POSIX only).
        if (function_exists('posix_kill')) {
            return !posix_kill((int) $pid, 0);
        }

        return false;
    }
}
