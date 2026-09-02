<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Manage build state across chunk invocations.
 *
 * Supports resumable indexing: state persists on disk between process
 * invocations so each chunk can run in a separate queue job.
 *
 * ## Mutual exclusion
 *
 * Two builds pointed at one state directory must not both proceed. Three
 * things enforce that, because on the NFS-backed state directories this runs
 * on in production, no single one of them holds:
 *
 * 1. `flock(LOCK_EX | LOCK_NB)` on a lock file at a *stable* path. The path is
 *    what makes it work: the file is created once and never unlinked, so every
 *    process locks the same inode. The previous implementation unlinked the
 *    lock file whenever it judged it stale and again from cleanup(), and an
 *    unlinked inode keeps its flock while the next process creates a fresh
 *    file underneath and locks that instead — two holders, one path.
 * 2. An ownership record written into the lock file (owner token, host, pid,
 *    generation, heartbeat). flock() is process-local on an NFS mount without
 *    working lock daemon state, so a lock acquired on one host says nothing
 *    about another; the record is consulted after flock() succeeds and a
 *    live foreign claim still refuses the build.
 * 3. A per-build generation directory. Chunk files live under
 *    `builds/<generation>/`, so even if both guards above were somehow
 *    defeated, two builds cannot write the same chunk filename — the failure
 *    that silently mixed one build's pages into the other's index. The
 *    manifest stays at the state directory root, where adapters already read
 *    it, and names the generation it describes; a writer whose generation no
 *    longer matches it aborts rather than counting its pages into another
 *    build's total.
 *
 * Liveness is decided by heartbeat age first. `posix_kill($pid, 0)` is only
 * corroborating evidence, and only when the record's host is this host: a live
 * process owned by another uid answers EPERM and a process in another
 * container is not in this PID namespace at all — both used to read as "dead",
 * which is how a running cron build's lock was declared stale and deleted
 * while it was 16 minutes into writing chunks.
 */
class BuildState
{
    private const LOCK_FILE = 'lock';
    private const MANIFEST_FILE = 'manifest.json';

    /** Directory holding one subdirectory per build generation. */
    private const BUILDS_DIR = 'builds';

    /** Maximum heartbeat age before considering a lock stale (1 hour). */
    private const STALE_LOCK_SECONDS = 3600;

    /**
     * errno for "no such process", the one answer from kill(pid, 0) that
     * proves the owner is gone. EPERM (1) means alive and owned by another
     * uid, which is why posix_kill()'s plain false is not evidence of death.
     */
    private const ERRNO_ESRCH = 3;

    /** Temp-file suffix used for atomic manifest writes. */
    private const MANIFEST_TMP_SUFFIX = '.tmp';

    /** @var resource|null Open file handle holding the exclusive flock. */
    private $lockHandle = null;

    /** Owner token this instance wrote into the lock file, if it holds it. */
    private ?string $ownerToken = null;

    /** Generation directory name this instance reads and writes. */
    private ?string $generation = null;

    /** Unix time this instance took the lock. */
    private ?int $acquiredAt = null;

    /** Memoised generation lookup for read-only instances. */
    private bool $generationResolved = false;

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
     * Acquires the lock first and only then clears abandoned state, so a
     * caller can no longer delete a live build's files on its way in. The file
     * handle is kept open (and locked) until releaseLock() is called.
     *
     * @param array $manifest Initial manifest data (total_pages, chunk_size, language, etc.).
     * @return bool True if lock acquired, false if a build is already running.
     * @since 1.0.0
     * @stability stable
     */
    public function initiateBuild(array $manifest): bool
    {
        if (!$this->acquireLock()) {
            return false;
        }

        // Safe now, and only now: nothing else can be writing here.
        try {
            $this->purgeAbandonedBuilds();
        } catch (\RuntimeException $e) {
            // Release rather than leave the lock held by a build that never
            // started: a failure here throws before the manifest is written,
            // and a held-but-unstarted lock would refuse every build for up to
            // an hour, until the heartbeat goes stale on its own. Guarded so a
            // failure in the release itself cannot mask the purge failure that
            // is the actual problem here — dropLockFileOnly() cannot throw
            // today, but nothing pins that down, and losing $e to a secondary
            // failure would send the caller chasing the wrong exception.
            try {
                $this->dropLockFileOnly();
            } catch (\Throwable) {
                // Ignored: $e below is the failure worth reporting. Leaving
                // the lock held here is no worse than the outcome this catch
                // exists to avoid — it still expires on its own once the
                // heartbeat goes stale.
            }
            throw $e;
        }

        $this->generation = $this->newGeneration();
        $this->generationResolved = true;
        $buildDir = $this->stateDir . '/' . self::BUILDS_DIR . '/' . $this->generation;
        if (!is_dir($buildDir) && !mkdir($buildDir, 0755, true)) {
            $this->dropLockFileOnly();
            throw new \RuntimeException("Failed to create build directory: {$buildDir}");
        }
        $this->writeLockRecord();

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
        ], $manifest, ['generation' => $this->generation]);

        $this->commitManifest($manifest);

        return true;
    }

    /**
     * Re-acquire the lock for a build that is being resumed.
     *
     * Unlike initiateBuild() this keeps the generation, the manifest and the
     * chunk files the interrupted run left behind — the resumed build carries
     * on writing into the same generation directory.
     *
     * @param array<string, mixed> $manifest The manifest returned by shouldResume().
     * @return bool True if the lock was re-acquired, false if a build is running.
     * @since 1.5.0
     * @stability experimental
     */
    public function resumeBuild(array $manifest): bool
    {
        if (!$this->acquireLock()) {
            return false;
        }

        $this->generation = $this->resolveGeneration();
        $this->generationResolved = true;
        $this->writeLockRecord();

        $manifest['status'] = 'building';
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
     * @throws \RuntimeException If this process no longer owns the build lock.
     * @since 1.0.0
     * @stability stable
     */
    public function recordChunk(int $chunkNumber, array $partialData): void
    {
        $this->assertOwnership();
        $this->assertManifestIsOurs();

        $path = $this->chunkPath($chunkNumber);
        (new ChunkWriter())->write($path, $partialData, $this->hmacSecret);

        // Update manifest.
        $manifest = $this->readManifest();
        if ($manifest !== null) {
            $manifest['chunks_written']  = $chunkNumber + 1;
            $manifest['pages_processed'] = ($manifest['pages_processed'] ?? 0) + count($partialData['pages'] ?? []);
            $this->commitManifest($manifest);
        }

        // Prove liveness for the next process that inspects the lock.
        $this->writeLockRecord();
    }

    /**
     * Read a chunk from disk (v2 streaming format only).
     *
     * @param int $chunkNumber Chunk number (0-based).
     * @return array The chunk data as {pages: ..., index: ...}.
     * @throws \RuntimeException If file missing, HMAC invalid, or data malformed.
     * @since 1.0.0
     * @stability stable
     */
    public function readChunk(int $chunkNumber): array
    {
        $filename = sprintf('chunk-%03d.dat', $chunkNumber);
        $path     = $this->chunkPath($chunkNumber);

        if (!file_exists($path)) {
            throw new \RuntimeException("Chunk file not found: {$filename}");
        }

        // Both footer digests are checked in a single pass over the file
        // (previously two full reads via verifyHmac() + verifyCrc32()).
        // HMAC: only when a secret is configured. CRC32: always written
        // (0.3.3+); validates data integrity without a shared secret —
        // detects disk corruption or partial writes. Pre-0.3.3 chunks have
        // no CRC32 in the footer and report null (backward-compatible).
        // Normalise before the guard, not just before the read: an empty or
        // whitespace-only secret means no secret, so recordChunk() wrote this
        // chunk without a tag. Testing the raw value here would demand a tag
        // that was never written and fail the build on its own chunks.
        $hmacSecret = HmacSecret::normalize($this->hmacSecret);
        $digests    = (new ChunkReader($path))->verifyFooterDigests($hmacSecret);
        if ($hmacSecret !== null && $digests['hmac'] !== true) {
            throw new \RuntimeException("HMAC verification failed for chunk: {$filename}");
        }
        if ($digests['crc32'] === false) {
            throw new \RuntimeException(
                "CRC32 validation failed for chunk: {$filename}. "
                . 'The chunk may be corrupted — delete the state directory and re-run a fresh build.',
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
     *
     * @since 1.0.0
     * @stability stable
     */
    public function releaseLock(): void
    {
        $manifest = $this->readManifest();
        if ($manifest !== null) {
            $manifest['status'] = 'idle';
            $this->commitManifest($manifest);
        }

        $this->dropLockFileOnly();
    }

    /**
     * Release the lock without touching the manifest status, leaving the
     * build resumable.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function releaseLockOnly(): void
    {
        $this->dropLockFileOnly();
    }

    /**
     * Mark the lock record released and drop the flock.
     *
     * The lock file itself stays on disk. Deleting it is what let a second
     * process flock a brand-new inode at the same path while the first still
     * held the old one; the record's `state` field is how a release is
     * communicated instead.
     */
    private function dropLockFileOnly(): void
    {
        $handle = $this->lockHandle;
        if ($handle !== null) {
            $this->writeLockRecord('released');
            flock($handle, LOCK_UN);
            fclose($handle);
            $this->lockHandle = null;
            $this->ownerToken = null;
            $this->acquiredAt = null;
        }
    }

    /**
     * Check if a partial build exists that can be resumed.
     *
     * @return array|null Manifest if resumable, null if fresh start needed.
     * @since 1.0.0
     * @stability stable
     */
    public function shouldResume(): ?array
    {
        $manifest = $this->readManifest();
        if ($manifest === null || ($manifest['status'] ?? '') !== 'building') {
            return null;
        }

        return $manifest;
    }

    /**
     * Get paths to all chunk files written so far.
     *
     * @return string[]
     * @since 1.0.0
     * @stability stable
     */
    public function getChunkFiles(): array
    {
        $manifest = $this->readManifest();
        $chunksWritten = $manifest['chunks_written'] ?? 0;
        $files = [];

        for ($i = 0; $i < $chunksWritten; $i++) {
            $path = $this->chunkPath($i);
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Check whether a build is currently in progress.
     *
     * True when the manifest shows status = 'building' and the lock record
     * names a live owner. See the class docblock for what "live" means here —
     * notably, a PID this process cannot signal is not evidence of death.
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

        $record = $this->readLockRecord();
        if ($record === null) {
            return false;
        }

        return !$this->isLockRecordStale($record);
    }

    /**
     * Describe the current lock holder, for diagnostics.
     *
     * Returns null when no lock file exists or its contents cannot be parsed.
     * The `stale` key says how the liveness question was answered, which is
     * the thing that could not be reconstructed after the fact when two builds
     * shared a state directory in production.
     *
     * @return array{owner: ?string, host: ?string, pid: ?int, generation: ?string,
     *               state: string, acquired_at: ?int, heartbeat_at: ?int,
     *               age_seconds: ?int, stale: bool, liveness: string}|null
     * @since 1.5.0
     * @stability experimental
     */
    public function lockDiagnostics(): ?array
    {
        $record = $this->readLockRecord();
        if ($record === null) {
            return null;
        }

        $heartbeat = $record['heartbeat_at'] ?? null;

        return [
            'owner'        => $record['owner'] ?? null,
            'host'         => $record['host'] ?? null,
            'pid'          => isset($record['pid']) ? (int) $record['pid'] : null,
            'generation'   => $record['generation'] ?? null,
            'state'        => (string) ($record['state'] ?? 'held'),
            'acquired_at'  => isset($record['acquired_at']) ? (int) $record['acquired_at'] : null,
            'heartbeat_at' => $heartbeat !== null ? (int) $heartbeat : null,
            'age_seconds'  => $heartbeat !== null ? time() - (int) $heartbeat : null,
            'stale'        => $this->isLockRecordStale($record),
            'liveness'     => $this->describeLiveness($record),
        ];
    }

    /**
     * The directory this build's chunk files live in.
     *
     * A per-build generation directory under `builds/`, or the state directory
     * root for chunks written before 1.5.0. Callers that need the chunk files
     * themselves should prefer getChunkFiles().
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function buildDirectory(): string
    {
        return $this->buildDir();
    }

    /**
     * Path to the build manifest, at the state directory root.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function manifestFile(): string
    {
        return $this->manifestPath();
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

        $mtime = @filemtime($this->manifestPath());

        return $mtime !== false ? gmdate('c', $mtime) : null;
    }

    /**
     * Clean up the state this class owns: the manifest and the committed chunk
     * files of this build's generation, plus the generation's directory.
     *
     * Deliberately not the whole directory, which is what it used to be. The
     * state directory is shared: PageWordCache keeps token-cache-manifest.php
     * in it, TimestampManifest keeps two files, PageTableLedger keeps a
     * snapshot and a journal. BuildCoordinator::prepare() calls this at the top
     * of every fresh build and release() calls it again after a successful one,
     * so all of that was deleted twice per build, and it survived only because
     * each of those classes loads its state in its own constructor and writes
     * it back at the end. A build that never reached its write — a crash, an
     * OOM kill, a forced pod eviction, a deferred merge, a finalize-only run —
     * left the directory holding none of it, and the next build read an empty
     * manifest, treated the whole corpus as changed, and ran fully cold.
     *
     * Deliberately not the lock file either, and this one is load-bearing: the
     * lock is what tells the next process whether this build is still running,
     * and unlinking it hands the same path to a second flock() holder. A build
     * that called cleanup() while another was mid-run used to delete that
     * build's lock, manifest and chunks out from under it.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function cleanup(): void
    {
        if (!is_dir($this->stateDir)) {
            return;
        }

        $this->assertCleanable();

        foreach ($this->ownedFiles() as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $buildDir = $this->buildDir();
        if ($this->generation !== null && $buildDir !== $this->stateDir && is_dir($buildDir)) {
            @rmdir($buildDir);
        }
    }

    /**
     * Refuse to delete state that belongs to a different live build.
     *
     * A build is allowed to clean the generation it is working on: this
     * instance either holds the lock, or is the segmented-build case where an
     * earlier process gathered the chunks and this one only merges and
     * finalizes them (the Drupal batch UI builds a fresh indexer per step, so
     * the process that finalizes never held the lock). What it must not do is
     * clean a generation some other build took after this one's chunks were
     * written — the incident this guard exists for.
     *
     * Residual gap, deliberately left: a finalize-only process resolves its
     * generation through the pointer file, so a *fresh* build that takes the
     * lock in the gap between the last chunk of a segmented build and its
     * finalize will have its own generation adopted by that finalize. Closing
     * it means the caller carrying the generation across steps, which is an
     * adapter-side protocol change.
     *
     * @throws \RuntimeException When the lock names another live build.
     */
    private function assertCleanable(): void
    {
        $record = $this->readLockRecord();
        if ($record === null || $this->isOurRecord($record) || $this->isLockRecordStale($record)) {
            return;
        }

        $generation = $this->generation ?? $this->resolveGeneration();
        if (($record['generation'] ?? null) === $generation) {
            // Same build, earlier process. Its lock outlived it — a segmented
            // build's process exits without releasing — so release it here,
            // otherwise the next build is refused until the heartbeat ages out.
            $this->markLockReleasedByPath();
            return;
        }

        throw new \RuntimeException(
            'Refusing to clean the state directory ' . $this->stateDir
            . ': a build owned by pid ' . ($record['pid'] ?? 'unknown')
            . ' on host ' . ($record['host'] ?? 'unknown')
            . ' (generation ' . ($record['generation'] ?? 'unknown') . ') is still running there.',
        );
    }

    /**
     * Mark the lock record released without holding the handle.
     *
     * Only for the segmented-build finalize above; every other release goes
     * through the held handle.
     *
     * @throws \RuntimeException If the write fails. A silent failure here
     *     would leave the file claiming the dead process still holds it,
     *     and the caller (assertCleanable()) would go on to delete state on
     *     the strength of a release that never actually happened.
     */
    private function markLockReleasedByPath(): void
    {
        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;
        $record = $this->readLockRecord();
        if ($record === null) {
            return;
        }

        $record['state'] = 'released';
        if (file_put_contents($lockFile, json_encode($record, JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException("Failed to write released lock record: {$lockFile}");
        }
    }

    /**
     * Every path this class writes into the current build's directory.
     *
     * The manifest temp files are included because commitManifest() names them
     * per writer (pid + uniqid), so a writer killed between the write and the
     * rename leaves one behind and readManifest() falls back to the newest of
     * them — which must not outlive the build it describes.
     *
     * The lock file is not here; see cleanup().
     *
     * @return list<string>
     */
    private function ownedFiles(): array
    {
        $files = [$this->manifestPath()];

        foreach (glob($this->manifestPath() . self::MANIFEST_TMP_SUFFIX . '*') ?: [] as $path) {
            $files[] = $path;
        }
        foreach (glob($this->buildDir() . '/chunk-*.dat') ?: [] as $path) {
            $files[] = $path;
        }

        return $files;
    }

    // ------------------------------------------------------------------
    // Locking
    // ------------------------------------------------------------------

    /**
     * Take the exclusive build lock, or report that someone else holds it.
     *
     * @return bool True when this process now owns the lock.
     */
    private function acquireLock(): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }

        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;

        // 'c' creates the file if missing and never truncates, so the inode at
        // this path is stable for the life of the state directory.
        $fp = fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }

        // flock() succeeded, which on a single host settles it. It does not on
        // an NFS mount where locking is process- or client-local, so a live
        // record from someone else still refuses the build.
        $record = $this->readLockRecord();
        if ($record !== null && !$this->isOurRecord($record) && !$this->isLockRecordStale($record)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        $this->lockHandle = $fp;
        $this->ownerToken = bin2hex(random_bytes(16));
        $this->acquiredAt = time();

        return true;
    }

    /**
     * Write (or refresh) this process's ownership record into the lock file.
     *
     * Written through the held handle, so the inode the flock is on is the
     * inode carrying the record.
     */
    private function writeLockRecord(string $state = 'held'): void
    {
        if ($this->lockHandle === null || $this->ownerToken === null) {
            return;
        }

        $now = time();
        $record = [
            'state'        => $state,
            'owner'        => $this->ownerToken,
            'host'         => self::hostname(),
            'pid'          => getmypid(),
            'generation'   => $this->generation,
            'acquired_at'  => $this->acquiredAt ?? $now,
            'heartbeat_at' => $now,
        ];

        $json = json_encode($record, JSON_THROW_ON_ERROR);

        ftruncate($this->lockHandle, 0);
        rewind($this->lockHandle);
        fwrite($this->lockHandle, $json);
        fflush($this->lockHandle);
    }

    /**
     * Read the ownership record from the lock file.
     *
     * Accepts the legacy `<pid>:<timestamp>` format written before 1.5.0, so
     * an upgrade that lands while a build is mid-flight still sees that build.
     *
     * @return array<string, mixed>|null Null when there is no lock file or no parsable content.
     */
    private function readLockRecord(): ?array
    {
        $lockFile = $this->stateDir . '/' . self::LOCK_FILE;
        if (!file_exists($lockFile)) {
            return null;
        }

        $data = @file_get_contents($lockFile);
        if ($data === false || trim($data) === '') {
            return null;
        }

        $decoded = json_decode($data, true);
        if (is_array($decoded) && isset($decoded['owner'])) {
            return $decoded;
        }

        // Legacy "<pid>:<timestamp>".
        $parts = explode(':', trim($data), 2);
        if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
            return [
                'state'        => 'held',
                'owner'        => 'legacy-' . $parts[0],
                'host'         => null,
                'pid'          => (int) $parts[0],
                'generation'   => null,
                'acquired_at'  => (int) $parts[1],
                'heartbeat_at' => (int) $parts[1],
            ];
        }

        // Unparsable content: fall back to the file's mtime, which at least
        // cannot claim a build is dead while it is still writing.
        $mtime = @filemtime($lockFile);

        return [
            'state'        => 'held',
            'owner'        => 'unknown',
            'host'         => null,
            'pid'          => null,
            'generation'   => null,
            'acquired_at'  => $mtime !== false ? $mtime : 0,
            'heartbeat_at' => $mtime !== false ? $mtime : 0,
        ];
    }

    /**
     * Whether a lock record is this instance's own claim.
     *
     * Identity is the owner token and nothing else. Matching on host + PID
     * would make a second BuildState in the same process — or a new process
     * that inherited a recycled PID — mistake somebody else's live claim for
     * its own, which is the class of reasoning that caused the incident this
     * lock was rewritten for.
     *
     * @param array<string, mixed> $record
     */
    private function isOurRecord(array $record): bool
    {
        return $this->ownerToken !== null && ($record['owner'] ?? null) === $this->ownerToken;
    }

    /**
     * Decide whether a lock record still represents a running build.
     *
     * Heartbeat age is the primary and cross-host-valid signal. A PID check is
     * only allowed to *shorten* that window, only on the recording host, and
     * only when the kernel says the process definitively does not exist
     * (ESRCH). EPERM means alive-but-not-ours, and no answer at all is
     * possible for a PID in another container's namespace.
     *
     * @param array<string, mixed> $record
     */
    private function isLockRecordStale(array $record): bool
    {
        if (($record['state'] ?? 'held') !== 'held') {
            return true;
        }

        $heartbeat = (int) ($record['heartbeat_at'] ?? $record['acquired_at'] ?? 0);
        if ($heartbeat <= 0 || time() - $heartbeat > self::STALE_LOCK_SECONDS) {
            return true;
        }

        return $this->isOwnerProvablyGone($record);
    }

    /**
     * Whether the recorded PID is provably gone on this host.
     *
     * Anything short of proof returns false — a build that is running must
     * never be reported as finished.
     *
     * @param array<string, mixed> $record
     */
    private function isOwnerProvablyGone(array $record): bool
    {
        $host = $record['host'] ?? null;
        $pid  = isset($record['pid']) ? (int) $record['pid'] : 0;

        if ($host === null || $host !== self::hostname() || $pid <= 0) {
            return false;
        }
        if (!function_exists('posix_kill') || !function_exists('posix_get_last_error')) {
            return false;
        }
        if (posix_kill($pid, 0)) {
            return false;
        }

        return posix_get_last_error() === self::ERRNO_ESRCH;
    }

    /**
     * Human-readable account of how liveness was decided, for diagnostics.
     *
     * @param array<string, mixed> $record
     */
    private function describeLiveness(array $record): string
    {
        if (($record['state'] ?? 'held') !== 'held') {
            return 'released by owner';
        }

        $heartbeat = (int) ($record['heartbeat_at'] ?? $record['acquired_at'] ?? 0);
        $age = time() - $heartbeat;
        if ($heartbeat <= 0 || $age > self::STALE_LOCK_SECONDS) {
            return sprintf('heartbeat %ds old (limit %ds) — stale', $age, self::STALE_LOCK_SECONDS);
        }

        $host = $record['host'] ?? null;
        if ($host === null) {
            return sprintf('heartbeat %ds old, owner host unknown — assumed live', $age);
        }
        if ($host !== self::hostname()) {
            return sprintf('heartbeat %ds old, owned by host %s — assumed live', $age, $host);
        }
        if ($this->isOwnerProvablyGone($record)) {
            return sprintf('pid %d gone on this host — stale', (int) ($record['pid'] ?? 0));
        }

        return sprintf('heartbeat %ds old, pid %d on this host — live', $age, (int) ($record['pid'] ?? 0));
    }

    /**
     * Fail the build rather than write into a generation this process no
     * longer owns.
     *
     * @throws \RuntimeException When the lock has been taken over or released.
     */
    private function assertOwnership(): void
    {
        if ($this->ownerToken === null) {
            // No lock was ever taken through this instance (legacy callers
            // that write chunks without initiateBuild(), and tests).
            return;
        }

        $record = $this->readLockRecord();
        if ($record !== null && ($record['owner'] ?? null) === $this->ownerToken) {
            return;
        }

        throw new \RuntimeException(
            'Lost the index build lock — another process has taken over the state directory '
            . $this->stateDir . '. Aborting rather than sharing its manifest and chunk files.',
        );
    }

    private static function hostname(): string
    {
        $host = gethostname();

        return $host === false ? 'unknown-host' : $host;
    }

    // ------------------------------------------------------------------
    // Generations
    // ------------------------------------------------------------------

    private function newGeneration(): string
    {
        return gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * The directory holding this build's chunk files.
     *
     * Falls back to the state directory root for chunks written before 1.5.0,
     * so an in-flight build survives the upgrade.
     */
    private function buildDir(): string
    {
        $generation = $this->generation ?? $this->resolveGeneration();
        if ($generation === null) {
            return $this->stateDir;
        }

        return $this->stateDir . '/' . self::BUILDS_DIR . '/' . $generation;
    }

    /**
     * Resolve the generation from the manifest, which names it.
     *
     * The manifest is the pointer: it stays at the state directory root, one
     * per state directory, and the build it describes is the one whose chunk
     * files the next process should read. A manifest without the field was
     * written before 1.5.0 and its chunks are at the root.
     */
    private function resolveGeneration(): ?string
    {
        if ($this->generationResolved) {
            return $this->generation;
        }
        $this->generationResolved = true;

        $manifest = $this->readManifest();
        $generation = $manifest['generation'] ?? null;
        if (!is_string($generation) || preg_match('/^[0-9A-Za-z_.\-]+$/', $generation) !== 1) {
            return null;
        }

        if (!is_dir($this->stateDir . '/' . self::BUILDS_DIR . '/' . $generation)) {
            return null;
        }

        $this->generation = $generation;

        return $generation;
    }

    /**
     * Refuse to update a manifest that now describes a different build.
     *
     * @throws \RuntimeException When the manifest names another generation.
     */
    private function assertManifestIsOurs(): void
    {
        if ($this->generation === null) {
            return;
        }

        $manifest = $this->readManifest();
        $generation = $manifest['generation'] ?? null;
        if ($manifest === null || $generation === null || $generation === $this->generation) {
            return;
        }

        throw new \RuntimeException(
            'The manifest in ' . $this->stateDir . ' now describes build generation '
            . (string) $generation . ', not ' . $this->generation
            . '. Aborting rather than counting this build\'s pages into another\'s.',
        );
    }

    /**
     * Delete the state of builds that are no longer running.
     *
     * Only ever called with the lock held, which is what makes it safe: any
     * generation directory still on disk at that point belongs to a build that
     * is over, however it ended.
     */
    /**
     * @throws \RuntimeException If any abandoned file or directory could not
     *     be removed. A swallowed failure here does not just leave one file
     *     behind — this is the one point that is allowed to assume every
     *     generation directory on disk belongs to a finished build, so a
     *     directory an unlink() failure leaves non-empty silently
     *     accumulates, unremoved, on every subsequent fresh build forever.
     */
    private function purgeAbandonedBuilds(): void
    {
        $failures = [];

        // The manifest of the previous build, and the chunk files a pre-1.5.0
        // build wrote beside it at the root.
        $legacy = array_merge(
            [$this->stateDir . '/' . self::MANIFEST_FILE],
            glob($this->stateDir . '/' . self::MANIFEST_FILE . self::MANIFEST_TMP_SUFFIX . '*') ?: [],
            glob($this->stateDir . '/chunk-*.dat') ?: [],
        );
        foreach ($legacy as $file) {
            if (is_file($file) && !@unlink($file)) {
                $failures[] = $file;
            }
        }

        foreach (glob($this->stateDir . '/' . self::BUILDS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file) && !@unlink($file)) {
                    $failures[] = $file;
                }
            }
            if (!@rmdir($dir) && is_dir($dir)) {
                $failures[] = $dir;
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException(
                'Failed to clear abandoned build state in ' . $this->stateDir . ': '
                . implode(', ', $failures),
            );
        }
    }

    /**
     * The manifest path: the state directory root, always.
     *
     * It stays where every adapter already looks for it — the Laravel queue
     * dispatcher reads it to decide whether a dispatch changed anything, and
     * moving it under the generation directory broke that. Sharing one
     * manifest is safe now for the reason sharing the chunk namespace was not:
     * the lock is genuinely held, and a writer whose generation no longer
     * matches the manifest's aborts instead of counting its pages into
     * somebody else's total.
     */
    private function manifestPath(): string
    {
        return $this->stateDir . '/' . self::MANIFEST_FILE;
    }

    private function chunkPath(int $chunkNumber): string
    {
        return $this->buildDir() . '/' . sprintf('chunk-%03d.dat', $chunkNumber);
    }

    /**
     * Write the manifest atomically: write to a unique temp file, then rename.
     *
     * The temp filename is unique per writer (pid + uniqid), so no two writers
     * ever contend for the same path — no flock() is needed. A blocking
     * LOCK_EX on a *fixed* temp path used to hang forever here on NFS-backed
     * storage after an ungracefully-killed writer left a stale server-side
     * lock behind (NFS lock state is tracked server-side; SIGKILL never
     * releases it the way a local flock() would).
     *
     * @throws \RuntimeException on I/O failure.
     */
    private function commitManifest(array $manifest): void
    {
        $manifestPath = $this->manifestPath();
        $tempPath     = $manifestPath . self::MANIFEST_TMP_SUFFIX . '.' . getmypid() . '.' . uniqid('', true);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($tempPath, $json) === false) {
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
     * Falls back to the newest manifest.json.tmp* temp file (each writer gets
     * a unique one) if manifest.json is missing or invalid. Returns null if
     * nothing yields valid JSON (fresh build).
     */
    /**
     * The build scope recorded by BuildCoordinator::prepare().
     *
     * Defaults to full for any manifest that predates the field — every build
     * written before it existed did walk the whole corpus.
     *
     * @return string BuildIntent::SCOPE_FULL or BuildIntent::SCOPE_PARTIAL.
     * @since 1.5.0
     * @stability experimental
     */
    public function declaredScope(): string
    {
        $manifest = $this->readManifest();
        $scope    = (string) ($manifest['scope'] ?? BuildIntent::SCOPE_FULL);

        return $scope === BuildIntent::SCOPE_PARTIAL
            ? BuildIntent::SCOPE_PARTIAL
            : BuildIntent::SCOPE_FULL;
    }

    private function readManifest(): ?array
    {
        $path = $this->manifestPath();

        if (file_exists($path)) {
            $data = file_get_contents($path);
            if ($data !== false) {
                $manifest = json_decode($data, true);
                if (is_array($manifest)) {
                    return $manifest;
                }
            }
        }

        $candidates = glob($path . self::MANIFEST_TMP_SUFFIX . '*') ?: [];
        usort(
            $candidates,
            static fn(string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0),
        );

        foreach ($candidates as $candidate) {
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
}
