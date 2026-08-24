<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Disk-backed chunked token cache for the PHP indexer.
 *
 * Stores per-page tokenization results (the forward index: page → token data)
 * so unchanged pages skip expensive HTML cleaning and tokenization on
 * subsequent builds. This is the "index for the indexer" — a forward index
 * supporting the rebuild of the search index (inverted index: term → pages).
 *
 * Architecture:
 *   - Manifest (token-cache-manifest.php): hash → chunk number, loaded into
 *     memory at construction and capped at $maxManifestEntries.
 *   - Chunk files (token-cache/chunk-NNNN.php): each holds ~N entries of full
 *     token data. Loaded on demand, one at a time, then released.
 *   - Write buffer: new/updated entries in memory, flushed as a new chunk
 *     file when it reaches chunk size.
 *
 * Every one of those is bounded by the memory budget rather than by the
 * corpus, which is the property the class needs and did not have: the manifest
 * grew one entry per page with no ceiling, and a second table of every hash
 * the build had touched grew alongside it. Past the manifest cap the cache
 * declines new pages, so a corpus larger than the budget re-tokenizes its tail
 * on each build — slower, never wrong.
 *
 * Memory: capped manifest + one loaded chunk (~2.5–5 MB) + write buffer up to
 * $maxWriteBufferBytes (default 4 MB). The byte limit prevents OOM when pages
 * have thousands of tokens (e.g. long encyclopedia articles) that would
 * overflow the count-only budget.
 *
 * Cache chunk size matches the build pipeline's MemoryBudget::chunkSize(),
 * so it automatically adapts to constrained environments.
 *
 * Concurrent access: all writes are atomic (temp file + rename).
 * Corruption: per-chunk isolation — one bad chunk doesn't destroy the cache.
 *
 * @since 0.3.11
 * @stability experimental
 */
final class PageWordCache
{
    private const MANIFEST_FILENAME = 'token-cache-manifest.php';
    private const CHUNK_DIR = 'token-cache';

    private readonly StorageDriverInterface $storage;
    private readonly string $stateDir;
    private readonly int $chunkSize;
    private readonly LoggerInterface $logger;

    /**
     * Manifest: content hash → chunk number, with seen-ness in the sign.
     *
     * A non-negative value is a chunk number for a hash this build has not
     * touched. A negative value is `-(chunk + 1)` for one it has, which is how
     * pruning knows what to keep.
     *
     * That encoding replaces a second array of every hash seen this build.
     * The pair was the largest per-corpus allocation in the indexer after the
     * index itself: two hash tables of the same 44k keys, neither bounded by
     * the chunk size the memory budget is expressed in, so `--chunk-size=20`
     * did nothing to either. Only one of them was ever load-bearing.
     *
     * @var array<string, int>
     */
    private array $manifest = [];

    /**
     * Upper bound on manifest entries, from the memory budget.
     *
     * Reached, the cache declines new pages rather than growing: those pages
     * re-tokenize on the next build, which costs time and not correctness.
     */
    private readonly int $maxManifestEntries;

    /** The manifest-full notice is worth saying once per build, not per page. */
    private bool $manifestFullWarned = false;

    /**
     * Currently loaded chunk: [chunkNumber => entries].
     * Only one chunk is loaded at a time. Null when no chunk is loaded.
     *
     * @var array{number: int, entries: array<string, array>}|null
     */
    private ?array $loadedChunk = null;

    /**
     * Write buffer for new/updated entries.
     * Flushed as a new chunk file when it reaches $chunkSize OR $maxWriteBufferBytes.
     *
     * @var array<string, array>
     */
    private array $writeBuffer = [];

    /**
     * Estimated byte footprint of entries currently in the write buffer.
     * Tracked to enforce the byte-based flush threshold.
     */
    private int $writeBufferBytes = 0;

    /**
     * Maximum estimated bytes to accumulate in the write buffer before flushing.
     * 0 disables byte-based flushing (count-only mode).
     */
    private readonly int $maxWriteBufferBytes;

    /**
     * Next chunk number to use when flushing the write buffer.
     */
    private int $nextChunkNumber = 0;

    public function __construct(
        string $stateDir,
        StorageDriverInterface $storage,
        int $chunkSize = 50,
        ?LoggerInterface $logger = null,
        int $maxWriteBufferBytes = 4 * 1024 * 1024,
        int $maxManifestEntries = 1_000_000,
    ) {
        $this->stateDir           = $stateDir;
        $this->storage            = $storage;
        $this->chunkSize          = max(1, $chunkSize);
        $this->logger             = $logger ?? new NullLogger();
        $this->maxWriteBufferBytes = max(0, $maxWriteBufferBytes);
        $this->maxManifestEntries  = max(1, $maxManifestEntries);
        $this->loadManifest();
    }

    /**
     * Decode a stored manifest value to its chunk number.
     */
    private static function chunkOf(int $stored): int
    {
        return $stored < 0 ? (-$stored) - 1 : $stored;
    }

    /**
     * Record that this build still needs $hash, without a second hash table.
     */
    private function markSeen(string $hash): void
    {
        $stored = $this->manifest[$hash] ?? null;
        if ($stored !== null && $stored >= 0) {
            $this->manifest[$hash] = -($stored + 1);
        }
    }

    /**
     * Look up cached token data for a content hash.
     *
     * Records the hash as "used" for pruning regardless of hit/miss.
     * On cache hit: loads the chunk file containing the entry (if not already
     * loaded), returns the token data, then releases the chunk.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function get(string $hash): ?array
    {
        $this->markSeen($hash);

        // Check write buffer first (most recently added entries).
        if (isset($this->writeBuffer[$hash])) {
            return $this->writeBuffer[$hash];
        }

        // Check manifest for chunk location.
        if (!isset($this->manifest[$hash])) {
            return null;
        }

        $chunkNumber = self::chunkOf($this->manifest[$hash]);

        // Load the chunk if not already loaded.
        if ($this->loadedChunk === null || $this->loadedChunk['number'] !== $chunkNumber) {
            $this->loadedChunk = null; // Release previous chunk.
            $entries = $this->loadChunkFile($chunkNumber);
            if ($entries === null) {
                // Chunk file corrupted or missing — remove stale manifest entries.
                $this->logger->warning(
                    "[scolta] Token cache chunk {$chunkNumber} is corrupted or missing. "
                    . 'Affected pages will be re-tokenized.',
                );
                $this->removeChunkFromManifest($chunkNumber);
                return null;
            }
            $this->loadedChunk = ['number' => $chunkNumber, 'entries' => $entries];
        }

        return $this->loadedChunk['entries'][$hash] ?? null;
    }

    /**
     * Store token data for a content hash.
     *
     * Also records the hash as "used." Entries go into the write buffer.
     * The buffer flushes when it reaches $chunkSize entries OR when the
     * estimated byte footprint exceeds $maxWriteBufferBytes — whichever
     * comes first. The byte limit prevents a single serialize() call from
     * allocating tens of megabytes when pages contain thousands of tokens
     * (e.g. long encyclopedia articles).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function put(string $hash, array $tokenData): void
    {
        // The manifest is the bounded resource, so admission is decided here:
        // a hash already tracked can always be rewritten, a new one only while
        // there is room. Declining costs a re-tokenization next build.
        if (!isset($this->manifest[$hash]) && !isset($this->writeBuffer[$hash])
            && count($this->manifest) + count($this->writeBuffer) >= $this->maxManifestEntries
        ) {
            $this->warnManifestFull();

            return;
        }

        $this->markSeen($hash);
        $this->writeBuffer[$hash] = $tokenData;

        if ($this->maxWriteBufferBytes > 0) {
            $this->writeBufferBytes += $this->estimateBytes($tokenData);
        }

        if (count($this->writeBuffer) >= $this->chunkSize
            || ($this->maxWriteBufferBytes > 0 && $this->writeBufferBytes >= $this->maxWriteBufferBytes)
        ) {
            $this->flushWriteBuffer();
        }
    }

    /**
     * Prune stale entries and save the manifest.
     *
     * Removes manifest entries for content hashes not seen in this build
     * (deleted or changed pages), flushes any remaining write buffer,
     * updates manifest entries for buffered writes, and deletes orphaned
     * chunk files that no longer have any live entries.
     *
     * Call on exactly one path: a full build that ran to completion in a
     * single process, and therefore looked up every live page. Anywhere else —
     * an interrupted segment, a merge-only finalize, an incremental update —
     * "not looked up" does not mean "gone", and {@see self::saveWithoutPruning()}
     * is the correct call.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function pruneAndSave(): void
    {
        // Flush any remaining entries in the write buffer.
        if (!empty($this->writeBuffer)) {
            $this->flushWriteBuffer();
        }

        // Release loaded chunk — we're done reading.
        $this->loadedChunk = null;

        // Prune: a hash this build never touched keeps its non-negative value
        // and is dropped; the rest are decoded back to plain chunk numbers.
        // Rebuilding into a new array rather than unsetting in place keeps the
        // peak at one manifest, not one plus a full set of removed keys.
        $kept = [];
        foreach ($this->manifest as $hash => $stored) {
            if ($stored < 0) {
                $kept[$hash] = (-$stored) - 1;
            }
        }
        $this->manifest = $kept;
        unset($kept);

        // Identify which chunk numbers are still referenced by the manifest.
        $liveChunks = array_flip(array_unique(array_values($this->manifest)));

        // Delete orphaned chunk files.
        $chunkDir = $this->stateDir . '/' . self::CHUNK_DIR;
        if ($this->storage->exists($chunkDir)) {
            $pattern = $chunkDir . '/chunk-*.php';
            foreach (glob($pattern) as $file) {
                if (preg_match('/chunk-(\d+)\.php$/', basename($file), $m)) {
                    $num = (int) $m[1];
                    if (!isset($liveChunks[$num])) {
                        $this->storage->delete($file);
                    }
                }
            }
        }

        // Save manifest atomically.
        $this->saveManifest();
    }

    /**
     * Save the manifest without dropping anything this process never looked up.
     *
     * {@see self::pruneAndSave()} is correct exactly once: at the end of a run
     * that looked up every live page, where a hash nobody asked for belongs to
     * a page that is gone. An incremental update looks up one or two, so the
     * same call reads "every other page has been deleted" and empties the
     * cache — 2,000 entries down to 2 after a single edit. The next edit to any
     * other page then cannot find its previous token data and refuses, and the
     * full build it falls back to is cold.
     *
     * So the write buffer is flushed, the seen markers are decoded back to
     * plain chunk numbers (the on-disk manifest holds no signs, and
     * loadManifest() derives the next chunk number from the maximum value),
     * and every entry is kept. Nothing is deleted, which means a partial run
     * leaves the hash of an edited page behind: one stale entry per edit,
     * bounded by the manifest cap and swept by the next full build. That is
     * the trade — a little garbage rather than a wiped cache.
     *
     * @since 1.3.1
     * @stability experimental
     */
    public function saveWithoutPruning(): void
    {
        if (!empty($this->writeBuffer)) {
            $this->flushWriteBuffer();
        }

        // Release loaded chunk — we're done reading.
        $this->loadedChunk = null;

        // Walk the keys rather than the array: a foreach over the property
        // holds a reference to it, so the first write separates the whole hash
        // table and peaks at two manifests. The key list costs a pointer per
        // entry instead.
        foreach (array_keys($this->manifest) as $hash) {
            $stored = $this->manifest[$hash];
            if ($stored < 0) {
                $this->manifest[$hash] = (-$stored) - 1;
            }
        }

        $this->saveManifest();
    }

    private function loadManifest(): void
    {
        $manifestFile = $this->stateDir . '/' . self::MANIFEST_FILENAME;

        // Check for legacy single-file cache and migrate if needed.
        $legacyFile = $this->stateDir . '/page-word-cache.php';
        if (!$this->storage->exists($manifestFile) && $this->storage->exists($legacyFile)) {
            $this->migrateFromLegacy($legacyFile);
            return;
        }

        if ($this->storage->exists($manifestFile)) {
            $raw = $this->storage->get($manifestFile);
            $data = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($data)) {
                $this->manifest = $data;
                // Determine next chunk number from manifest.
                if (!empty($this->manifest)) {
                    $this->nextChunkNumber = max(array_values($this->manifest)) + 1;
                }
            }
        }
    }

    private function migrateFromLegacy(string $legacyFile): void
    {
        $this->logger->info('[scolta] Migrating legacy page-word cache to chunked format.');

        try {
            $raw = $this->storage->get($legacyFile);
            $data = @unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable) {
            $data = null;
        }

        if (!is_array($data) || empty($data)) {
            $this->logger->warning('[scolta] Legacy cache corrupted or empty. Starting fresh.');
            $this->storage->delete($legacyFile);
            return;
        }

        $chunkDir = $this->stateDir . '/' . self::CHUNK_DIR;
        $this->storage->makeDirectory($chunkDir);

        $chunkNum = 0;
        $chunkEntries = [];
        $count = 0;

        foreach ($data as $hash => $tokenData) {
            $chunkEntries[$hash] = $tokenData;
            $this->manifest[$hash] = $chunkNum;
            $count++;

            if ($count >= $this->chunkSize) {
                $this->writeChunkFile($chunkNum, $chunkEntries);
                $chunkNum++;
                $chunkEntries = [];
                $count = 0;
            }
        }

        // Tail chunk.
        if (!empty($chunkEntries)) {
            $this->writeChunkFile($chunkNum, $chunkEntries);
            $chunkNum++;
        }

        $this->nextChunkNumber = $chunkNum;
        $this->saveManifest();

        $this->storage->delete($legacyFile);

        $totalEntries = count($this->manifest);
        $this->logger->info(
            "[scolta] Migration complete: {$totalEntries} entries across {$chunkNum} chunks.",
        );
    }

    private function loadChunkFile(int $chunkNumber): ?array
    {
        $file = $this->chunkFilePath($chunkNumber);
        if (!$this->storage->exists($file)) {
            return null;
        }

        $raw = $this->storage->get($file);
        $data = @unserialize($raw, ['allowed_classes' => [Token::class]]);

        if (!is_array($data)) {
            return null;
        }

        // Detect cache entries written before the Token class was introduced (pre-1.0.0).
        // Old entries store tokens as plain arrays; reading them with ->stem would fatal.
        // Return null to force re-tokenization — the new entry will use Token objects.
        foreach ($data as $entry) {
            $firstTokens = $entry[TextChannel::Title->value] ?? $entry[TextChannel::Body->value] ?? [];
            if (!empty($firstTokens) && is_array(reset($firstTokens))) {
                return null;
            }
            break;
        }

        return $data;
    }

    private function writeChunkFile(int $chunkNumber, array $entries): void
    {
        $chunkDir = $this->stateDir . '/' . self::CHUNK_DIR;
        $this->storage->makeDirectory($chunkDir);

        $file = $this->chunkFilePath($chunkNumber);
        $tmpFile = $file . '.tmp.' . getmypid();

        $this->storage->put($tmpFile, serialize($entries));
        rename($tmpFile, $file);
    }

    private function chunkFilePath(int $chunkNumber): string
    {
        return $this->stateDir . '/' . self::CHUNK_DIR
            . '/chunk-' . str_pad((string) $chunkNumber, 6, '0', STR_PAD_LEFT) . '.php';
    }

    private function flushWriteBuffer(): void
    {
        if (empty($this->writeBuffer)) {
            return;
        }

        $chunkNumber = $this->nextChunkNumber++;
        $this->writeChunkFile($chunkNumber, $this->writeBuffer);

        // Written by this build, so stored already-seen: pruneAndSave() drops
        // whatever is still unseen, and these entries are the newest there are.
        $encoded = -($chunkNumber + 1);
        foreach ($this->writeBuffer as $hash => $entry) {
            $this->manifest[$hash] = $encoded;
        }

        $this->writeBuffer      = [];
        $this->writeBufferBytes = 0;
    }

    /**
     * Estimate the serialized byte footprint of a single token-data entry.
     *
     * Counts all token records (each ~80 bytes serialized: 3 string fields +
     * PHP array overhead) plus the raw content string. The estimate is
     * intentionally conservative — it may undercount complex Unicode tokens —
     * but is fast (no actual serialization) and sufficient for flush budgeting.
     */
    private function estimateBytes(array $tokenData): int
    {
        $tokenCount = 0;
        foreach (TextChannel::cases() as $channel) {
            $tokenCount += count($tokenData[$channel->value] ?? []);
        }

        return $tokenCount * 80 + strlen($tokenData['content'] ?? '');
    }

    private function saveManifest(): void
    {
        $file = $this->stateDir . '/' . self::MANIFEST_FILENAME;
        $tmpFile = $file . '.tmp.' . getmypid();

        $this->storage->makeDirectory($this->stateDir);
        $this->storage->put($tmpFile, serialize($this->manifest));
        rename($tmpFile, $file);
    }

    private function removeChunkFromManifest(int $chunkNumber): void
    {
        $this->manifest = array_filter(
            $this->manifest,
            static fn(int $stored): bool => self::chunkOf($stored) !== $chunkNumber,
        );
    }

    /**
     * Say once that the cache stopped growing, and why it is not an error.
     */
    private function warnManifestFull(): void
    {
        if ($this->manifestFullWarned) {
            return;
        }

        $this->manifestFullWarned = true;
        $this->logger->notice(sprintf(
            '[scolta] Token cache reached its %d-entry memory budget. Further pages are indexed '
            . 'normally but will be re-tokenized on the next build. Raise --memory-budget to cache more.',
            $this->maxManifestEntries,
        ));
    }
}
