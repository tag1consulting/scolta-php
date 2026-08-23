<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Apply a small set of page changes to an index that already exists, without
 * rebuilding it.
 *
 * A full build of a 109,308-page corpus takes tens of minutes, and a one-word
 * edit to one node costs the same as a full rebuild because the rebuild is the
 * only update path there is. This is the other path.
 *
 * It is an addition, not a replacement, and deliberately not a flag on
 * IndexBuildOrchestrator::build(): a mode parameter threaded through the
 * pipeline would make every stage answer "which kind of build am I in", which
 * is the configuration axis this avoids. The full build remains the reference
 * implementation, and the differential test in the suite asserts that an
 * incremental sequence and a full rebuild of the same logical state produce
 * byte-identical output.
 *
 * ## What makes it possible
 *
 * Two identifiers used to be functions of the whole corpus. Both are now
 * pinned:
 *
 *  - The page ordinal comes from {@see PageTableLedger}, so it survives edits,
 *    inserts and deletes instead of being a running counter over gather order.
 *  - The chunk ranges in `pf_meta[2]` are treated as frozen, so which chunk
 *    owns a term is a function of the vocabulary rather than of the corpus.
 *
 * Chunk and fragment *filenames* are not pinned, and deliberately so: both
 * follow their contents (see {@see IndexFileNaming}), because a published
 * index is fetched over HTTP and a name that outlives its bytes is a name a
 * cache will serve stale. A touched chunk is therefore rewritten under a new
 * name and the old file unlinked — the rename path below — and a rewritten
 * fragment likewise. Only the ranges stay put.
 *
 * ## What it refuses
 *
 * An updater that is correct for edits and quietly wrong for deletes is worse
 * than no updater, because it teaches readers to stop checking. Every case it
 * cannot handle exactly throws {@see IncrementalUpdateUnavailable} so the
 * caller falls back to a full build loudly:
 *
 *  - no existing index, or no ledger (nothing to update against)
 *  - a changed page whose previous token data is no longer in the token cache,
 *    so its stale postings cannot be located and removed
 *
 * @since 1.2.0
 * @stability experimental
 */
final class IncrementalIndexUpdater
{
    private const DELIMITER = 'pagefind_dcd';

    private readonly StorageDriverInterface $storage;
    private readonly LoggerInterface $logger;
    private readonly PageTableLedger $ledger;
    private readonly PageWordCache $cache;
    private readonly InvertedIndexBuilder $builder;
    private readonly Stemmer $stemmer;
    private readonly CborEncoder $cbor;
    private readonly string $outputDir;
    private readonly MemoryBudget $budget;

    /** @var list<ContentItem> */
    private array $upserts = [];

    /** @var list<string> */
    private array $deletes = [];

    /**
     * @param MemoryBudget|null $budget Supplies the gzip level for the artifacts
     *                                  this update rewrites, so an update and a
     *                                  full build of the same host compress
     *                                  alike. Defaults to the runtime default.
     */
    public function __construct(
        string $stateDir,
        string $outputDir,
        string $language = 'en',
        ?StorageDriverInterface $storage = null,
        ?LoggerInterface $logger = null,
        ?MemoryBudget $budget = null,
    ) {
        $normalized = rtrim($outputDir, '/');
        if (str_ends_with($normalized, '/pagefind')) {
            $normalized = substr($normalized, 0, -strlen('/pagefind'));
        }
        $this->outputDir = $normalized;

        $this->storage = $storage ?? new FilesystemDriver();
        $this->logger  = $logger  ?? new NullLogger();
        $this->budget  = $budget  ?? MemoryBudget::default();
        $this->cbor    = new CborEncoder();
        $this->stemmer = new Stemmer($language);
        $this->builder = new InvertedIndexBuilder(new Tokenizer(), $this->stemmer);
        $this->ledger  = new PageTableLedger($stateDir, $this->storage);
        $this->cache   = new PageWordCache($stateDir, $this->storage);
    }

    /**
     * Queue a page as added or changed. Which one it is follows from whether
     * the ledger already knows the id.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function stageUpsert(ContentItem $item): void
    {
        $this->upserts[] = $item;
    }

    /**
     * Queue a page for removal by content-item id.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function stageDelete(string $id): void
    {
        $this->deletes[] = $id;
    }

    /**
     * True when an incremental update is possible at all right now.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function isAvailable(): bool
    {
        return !$this->ledger->isEmpty() && $this->findMetaPath() !== null;
    }

    /**
     * Ordinals released and not yet reused, as a fraction of the page table.
     *
     * Deletes tombstone rather than renumber, so the table only shrinks at a
     * compaction. Operators watch this to decide when to run one; nothing
     * compacts automatically, because an unattended compaction is a full
     * rebuild and that is the hour-long surprise this class exists to remove.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function tombstoneRatio(): float
    {
        return $this->ledger->tombstoneRatio();
    }

    /**
     * Apply every staged change and publish the result.
     *
     * @throws IncrementalUpdateUnavailable When the update cannot be done exactly.
     * @since 1.2.0
     * @stability experimental
     */
    public function commit(): IncrementalUpdateResult
    {
        $started = microtime(true);

        if ($this->upserts === [] && $this->deletes === []) {
            return new IncrementalUpdateResult(0, 0, 0, 0, 0.0, $this->ledger->tombstoneRatio());
        }

        $metaPath = $this->findMetaPath();
        if ($metaPath === null || $this->ledger->isEmpty()) {
            throw new IncrementalUpdateUnavailable(
                'No existing index with a page-table ledger at ' . $this->indexDir()
                . '. Run a full build first; incremental updates apply to an index, they do not create one.',
            );
        }

        $meta           = CborDecoder::decodeArtifact($metaPath);
        $version        = (string) $meta[0];
        $pageMeta       = $this->readPageTable($meta[1]);
        $indexChunkMeta = $this->readChunkRanges($meta[2]);
        /** @var list<string> $metaFields */
        $metaFields     = array_values(array_map(strval(...), $meta[5] ?? ['title']));

        /** @var array<string, list<int>> term => ordinals whose postings must go */
        $removals = [];
        /** @var array<string, array<int, mixed>> term => ordinal => new page entry */
        $additions = [];
        /** @var array<string, array<string, list<int>>> term => variant => ordinals */
        $variantAdds = [];

        $touchedFragments = 0;

        foreach ($this->deletes as $id) {
            $ordinal = $this->ledger->ordinalFor($id);
            if ($ordinal === null) {
                continue;
            }
            $this->collectRemovals($id, $ordinal, $removals);

            $superseded         = $pageMeta[$ordinal]['fragmentHash'] ?? null;
            $pageMeta[$ordinal] = $this->writeTombstoneFragment($ordinal);
            $this->deleteSupersededFragment($superseded, $pageMeta[$ordinal]['fragmentHash']);
            $this->ledger->release($id);
            $touchedFragments++;
        }

        foreach ($this->upserts as $item) {
            $newHash   = PhpIndexer::contentHash($item);
            $tokenData = $this->cache->get($newHash) ?? $this->builder->tokenizeItem($item);

            $existingOrdinal = $this->ledger->ordinalFor($item->id);

            if ($tokenData === null) {
                // Body too short to index. An existing page becomes a
                // tombstone; a new one is simply not added, matching what a
                // full build would do with it.
                if ($existingOrdinal !== null) {
                    $this->collectRemovals($item->id, $existingOrdinal, $removals);
                    $superseded                 = $pageMeta[$existingOrdinal]['fragmentHash'] ?? null;
                    $pageMeta[$existingOrdinal] = $this->writeTombstoneFragment($existingOrdinal);
                    $this->deleteSupersededFragment(
                        $superseded,
                        $pageMeta[$existingOrdinal]['fragmentHash'],
                    );
                    $this->ledger->release($item->id);
                    $touchedFragments++;
                }
                continue;
            }

            $this->cache->put($newHash, $tokenData);

            if ($existingOrdinal !== null) {
                $this->collectRemovals($item->id, $existingOrdinal, $removals);
            }

            $ordinal = $this->ledger->allocate(
                $item->id,
                $item->url,
                InvertedIndexBuilder::effectiveFilters($item),
                InvertedIndexBuilder::effectiveSortable($item),
                $newHash,
            );

            $partial = $this->builder->buildFromTokenDataWithOrdinals([
                ['item' => $item, 'tokenData' => $tokenData, 'ordinal' => $ordinal],
            ]);

            // Whatever this ordinal named before is unreferenced the moment the
            // new fragment lands, and unlike the full build there is no
            // directory swap to sweep it away. That covers an edit, a url
            // change, and an ordinal taken off the free list still holding its
            // tombstone — all three are the same thing now that the filename
            // follows the contents.
            $superseded         = $pageMeta[$ordinal]['fragmentHash'] ?? null;
            $pageMeta[$ordinal] = $this->writeFragment($ordinal, $partial['pages'][$ordinal]);
            $this->deleteSupersededFragment($superseded, $pageMeta[$ordinal]['fragmentHash']);
            $touchedFragments++;

            foreach ($partial['index'] as $term => $entries) {
                $term = (string) $term;
                foreach ($entries as $key => $entry) {
                    if ($key === '_variants') {
                        foreach ($entry as $form => $ordinals) {
                            foreach ($ordinals as $o) {
                                $variantAdds[$term][(string) $form][] = (int) $o;
                            }
                        }
                        continue;
                    }
                    $additions[$term][(int) $key] = $entry;
                }
            }
        }

        $chunkStats = $this->applyTermDeltas($removals, $additions, $variantAdds, $indexChunkMeta);

        // The whole-corpus artifacts are rebuilt in full: they are small, and a
        // partial rewrite of any of them has no meaning.
        [$filterData, $sortFields] = $this->rebuildCorpusTables($pageMeta);

        (new IndexMetadataWriter($this->cbor, $this->budget->compressionLevel()))->write(
            $this->indexDir(),
            $pageMeta,
            $filterData,
            $sortFields,
            $indexChunkMeta,
            $metaFields,
            $version,
        );

        // Publish order matters. New chunks were written under new names before
        // this point and the old pf_meta still pointed at the old ones, so a
        // reader mid-update saw a consistent older index throughout. The new
        // pf_meta and the entry file that names it are written last, and only
        // then is the superseded pf_meta removed.
        $this->removeSupersededMeta($metaPath);

        $this->ledger->save();

        // Saved, not pruned. Pruning drops every hash this process did not look
        // up, which at the end of a full build means the pages that are gone
        // and here would mean all of them but the one just edited: an update
        // touching two pages out of 109,308 would leave a two-entry cache, the
        // next update to any other page could not find its previous token data
        // and would refuse, and the full build that refusal falls back to would
        // start cold. The cache belongs to the corpus, not to this update.
        $this->cache->saveWithoutPruning();

        $result = new IncrementalUpdateResult(
            pagesUpdated: count($this->upserts),
            pagesDeleted: count($this->deletes),
            fragmentsWritten: $touchedFragments,
            chunksRewritten: $chunkStats['rewritten'],
            durationSeconds: round(microtime(true) - $started, 3),
            tombstoneRatio: $this->ledger->tombstoneRatio(),
        );

        $this->logger->info(
            '[scolta] Incremental update: {updated} updated, {deleted} deleted, {chunks} index chunks rewritten in {secs}s (tombstones {pct}%).',
            [
                'updated' => $result->pagesUpdated,
                'deleted' => $result->pagesDeleted,
                'chunks'  => $result->chunksRewritten,
                'secs'    => $result->durationSeconds,
                'pct'     => round($result->tombstoneRatio * 100, 1),
            ],
        );

        $this->upserts = [];
        $this->deletes = [];

        return $result;
    }

    // ── Term routing and chunk rewriting ───────────────────────────────────

    /**
     * Rewrite every index chunk touched by the staged term changes.
     *
     * @param array<string, list<int>>                $removals
     * @param array<string, array<int, mixed>>        $additions
     * @param array<string, array<string, list<int>>> $variantAdds
     * @param list<array{from: string, to: string, hash: string}> $indexChunkMeta Updated in place.
     * @return array{rewritten: int}
     */
    private function applyTermDeltas(
        array $removals,
        array $additions,
        array $variantAdds,
        array &$indexChunkMeta,
    ): array {
        if ($indexChunkMeta === []) {
            throw new IncrementalUpdateUnavailable('Existing index has no term chunks to update.');
        }

        // Group every affected term by the chunk that owns it, so each chunk is
        // decoded, mutated and re-encoded exactly once.
        $byChunk = [];
        foreach ([array_keys($removals), array_keys($additions), array_keys($variantAdds)] as $termSet) {
            foreach ($termSet as $term) {
                $byChunk[$this->chunkIndexForTerm((string) $term, $indexChunkMeta)][(string) $term] = true;
            }
        }

        $rewritten = 0;
        foreach ($byChunk as $chunkIdx => $terms) {
            $row      = $indexChunkMeta[$chunkIdx];
            $path     = $this->indexDir() . '/index/' . $row['hash'] . '.pf_index';
            $chunk    = PfIndexCodec::decodeChunkFile($path);
            $original = $chunk;

            foreach (array_keys($terms) as $term) {
                $term = (string) $term;

                foreach ($removals[$term] ?? [] as $ordinal) {
                    unset($chunk[$term][$ordinal]);
                    if (isset($chunk[$term]['_variants'])) {
                        foreach ($chunk[$term]['_variants'] as $form => $ordinals) {
                            $kept = array_values(array_filter($ordinals, static fn(int $o): bool => $o !== $ordinal));
                            if ($kept === []) {
                                unset($chunk[$term]['_variants'][$form]);
                            } else {
                                $chunk[$term]['_variants'][$form] = $kept;
                            }
                        }
                        if ($chunk[$term]['_variants'] === []) {
                            unset($chunk[$term]['_variants']);
                        }
                    }
                }

                foreach ($additions[$term] ?? [] as $ordinal => $entry) {
                    $chunk[$term][$ordinal] = $entry;
                }

                foreach ($variantAdds[$term] ?? [] as $form => $ordinals) {
                    $merged = array_merge($chunk[$term]['_variants'][$form] ?? [], $ordinals);
                    sort($merged);
                    $chunk[$term]['_variants'][$form] = array_values(array_unique($merged));
                }

                // A term whose last posting went away leaves the vocabulary,
                // which is the only way an update shrinks a chunk's word list.
                if (isset($chunk[$term]) && $this->pageCount($chunk[$term]) === 0) {
                    unset($chunk[$term]);
                }
            }

            if ($chunk === $original) {
                continue;
            }

            if ($chunk === []) {
                throw new IncrementalUpdateUnavailable(
                    'An index chunk would be left with no terms. Removing a chunk changes the '
                    . 'pf_meta[2] range table in a way this updater does not implement; run a full build.',
                );
            }

            // Terms inside a chunk must stay in ascending order: the range
            // table is a sorted, non-overlapping cover and the writer emitted
            // each chunk's words in order.
            uksort($chunk, self::compareTerms(...));

            $words   = PfIndexCodec::wordList($chunk);
            $body    = PfIndexCodec::encodeChunk($this->cbor, $chunk);
            $newHash = PfIndexCodec::chunkHash($chunk, $body);

            $newPath = $this->indexDir() . "/index/{$newHash}.pf_index";
            $gzipped = gzencode(self::DELIMITER . $body, $this->budget->compressionLevel());
            if (file_put_contents($newPath, $gzipped) === false) {
                throw new \RuntimeException("Failed to write index chunk: {$newPath}");
            }
            if ($newHash !== $row['hash'] && is_file($path)) {
                unlink($path);
            }

            $indexChunkMeta[$chunkIdx] = [
                'from' => $words[0],
                'to'   => $words[count($words) - 1],
                'hash' => $newHash,
            ];
            $rewritten++;
        }

        return ['rewritten' => $rewritten];
    }

    /**
     * The chunk that owns $term, by the frozen `pf_meta[2]` ranges.
     *
     * Ranges are a sorted, non-overlapping cover of the vocabulary that was
     * present when the index was built. A term that sorts before the first
     * range or after the last joins the end chunk it is adjacent to; a term
     * that falls between two ranges joins the earlier one. Either way exactly
     * one chunk is renamed, instead of a new term shifting every flush
     * boundary after it and renaming most of the index.
     *
     * @param list<array{from: string, to: string, hash: string}> $ranges
     */
    private function chunkIndexForTerm(string $term, array $ranges): int
    {
        $lo = 0;
        $hi = count($ranges) - 1;

        if (self::compareTerms($term, $ranges[0]['from']) <= 0) {
            return 0;
        }
        if (self::compareTerms($term, $ranges[$hi]['to']) >= 0) {
            return $hi;
        }

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (self::compareTerms($term, $ranges[$mid]['from']) < 0) {
                $hi = $mid - 1;
            } elseif (self::compareTerms($term, $ranges[$mid]['to']) > 0) {
                $lo = $mid + 1;
            } else {
                return $mid;
            }
        }

        // Between two ranges: attach to the earlier one so the cover stays
        // contiguous and no gap opens between $ranges[$hi]['to'] and the next
        // range's 'from'.
        return max(0, $hi);
    }

    /**
     * Term ordering, defined once.
     *
     * The N-way merge that produced these chunks ordered terms with
     * SplMinHeap's default comparison, which is PHP's standard comparison, not
     * strcmp. The two disagree on numeric-looking terms ("10" sorts before "9"
     * numerically and after it lexicographically), and a router that used the
     * other one would put a term in the wrong chunk — producing a searchable
     * index that a full rebuild would not reproduce, rather than an error.
     */
    private static function compareTerms(int|string $a, int|string $b): int
    {
        // Accepts int because PHP hands back numeric-looking array keys as
        // ints, and uksort() passes the raw key. Comparing them as PHP's
        // standard comparison does is the point: that is what SplMinHeap used
        // when these chunks were ordered.
        return $a <=> $b;
    }

    /** Number of real page postings in a term entry, ignoring the variants key. */
    /** @param array<int|string, mixed> $entry */
    private function pageCount(array $entry): int
    {
        return count($entry) - (isset($entry['_variants']) ? 1 : 0);
    }

    // ── Reading the existing index ─────────────────────────────────────────

    private function indexDir(): string
    {
        return $this->outputDir . '/pagefind';
    }

    private function findMetaPath(): ?string
    {
        $matches = glob($this->indexDir() . '/pagefind.*.pf_meta') ?: [];

        return $matches === [] ? null : $matches[0];
    }

    private function removeSupersededMeta(string $keptOutOfDate): void
    {
        foreach (glob($this->indexDir() . '/pagefind.*.pf_meta') ?: [] as $path) {
            if ($path === $keptOutOfDate) {
                unlink($path);
            }
        }
    }

    /**
     * @param list<mixed> $pages
     * @return array<int, array{fragmentHash: string, wordCount: int}>
     */
    private function readPageTable(array $pages): array
    {
        /** @var array<int, array{fragmentHash: string, wordCount: int}> $table */
        $table = [];
        foreach ($pages as $ordinal => $row) {
            /** @var array{0: mixed, 1: mixed} $row */
            $table[(int) $ordinal] = ['fragmentHash' => (string) $row[0], 'wordCount' => (int) $row[1]];
        }

        return $table;
    }

    /**
     * @param list<mixed> $chunks
     * @return list<array{from: string, to: string, hash: string}>
     */
    private function readChunkRanges(array $chunks): array
    {
        $ranges = [];
        foreach ($chunks as $row) {
            $ranges[] = ['from' => (string) $row[0], 'to' => (string) $row[1], 'hash' => (string) $row[2]];
        }

        return $ranges;
    }

    /**
     * Locate the terms a page contributed last time, so they can be removed.
     *
     * @param array<string, list<int>> $removals Appended to.
     * @throws IncrementalUpdateUnavailable When the previous token data is gone.
     */
    private function collectRemovals(string $id, int $ordinal, array &$removals): void
    {
        $oldHash = $this->ledger->contentHashFor($id);
        $old     = $oldHash === '' ? null : $this->cache->get($oldHash);

        if ($old === null) {
            throw new IncrementalUpdateUnavailable(sprintf(
                'Previous token data for "%s" is not in the token cache, so its stale postings cannot be '
                . 'located. Run a full build. (The merge resolves a duplicate ordinal by last-write-wins, '
                . 'so leaving them in place would silently corrupt the index rather than fail.)',
                $id,
            ));
        }

        $terms = [];
        // Read from TextChannel rather than a list repeated here: a channel
        // missing from this sweep leaves its postings orphaned on every page
        // update, and does so silently.
        foreach (TextChannel::cases() as $channel) {
            foreach ($old[$channel->value] ?? [] as $token) {
                $terms[$this->stemmer->stem($token->stem)] = true;
            }
        }

        foreach (array_keys($terms) as $term) {
            $removals[(string) $term][] = $ordinal;
        }
    }

    // ── Fragment and corpus-table writing ──────────────────────────────────

    /**
     * @return array{fragmentHash: string, wordCount: int}
     */
    /**
     * @param array<string, mixed> $pageData
     * @return array{fragmentHash: string, wordCount: int}
     */
    private function writeFragment(int $ordinal, array $pageData): array
    {
        $fragment = json_encode([
            'url'        => $pageData['url'],
            'content'    => $pageData['content'] ?? '',
            'word_count' => $pageData['wordCount'],
            'filters'    => !empty($pageData['filters']) ? $pageData['filters'] : new \stdClass(),
            'meta'       => !empty($pageData['meta']) ? $pageData['meta'] : new \stdClass(),
            'anchors'    => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($fragment === false) {
            throw new \RuntimeException(sprintf(
                'Failed to encode fragment for page %d (%s): %s',
                $ordinal,
                (string) $pageData['url'],
                json_last_error_msg(),
            ));
        }

        $hash = IndexFileNaming::fragmentHash($ordinal, (string) $pageData['url'], $fragment);
        $path = $this->indexDir() . "/fragment/{$hash}.pf_fragment";
        $gzipped = gzencode(self::DELIMITER . $fragment, $this->budget->compressionLevel());
        if (file_put_contents($path, $gzipped) === false) {
            throw new \RuntimeException("Failed to write fragment: {$path}");
        }

        return ['fragmentHash' => $hash, 'wordCount' => (int) $pageData['wordCount']];
    }

    /**
     * Write the empty fragment that keeps a released ordinal's row occupied.
     *
     * @return array{fragmentHash: string, wordCount: int}
     */
    private function writeTombstoneFragment(int $ordinal): array
    {
        return $this->writeFragment($ordinal, [
            'url'       => '',
            'content'   => '',
            'wordCount' => 0,
            'filters'   => [],
            'meta'      => [],
        ]);
    }

    /**
     * Remove the fragment an ordinal named before its rewrite.
     *
     * Takes the recorded hash rather than recomputing one: a fragment filename
     * now follows its contents, so the only handle on the previous file is the
     * name `pf_meta` was carrying for it. A rewrite that happens to reproduce
     * the same bytes keeps the same name, and the guard stops that case from
     * deleting the file just written.
     */
    private function deleteSupersededFragment(?string $previousHash, string $currentHash): void
    {
        if ($previousHash === null || $previousHash === $currentHash) {
            return;
        }

        $path = $this->indexDir() . "/fragment/{$previousHash}.pf_fragment";
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Rebuild the filter and sort tables from the ledger.
     *
     * Every live page contributes; tombstoned ordinals contribute nothing, so
     * they carry no filter posting and appear in no sort order, which is what
     * makes them unreachable by search while still occupying a page-table row.
     *
     * @param array<int, array{fragmentHash: string, wordCount: int}> $pageMeta
     * @return array{0: array<string, array<string, list<int>>>, 1: array<string, array<int, string>>}
     */
    private function rebuildCorpusTables(array $pageMeta): array
    {
        $filterData = [];
        $sortFields = [];

        foreach ($this->ledger->rowsByOrdinal() as $ordinal => $row) {
            if (!isset($pageMeta[$ordinal])) {
                continue;
            }
            foreach ($row['filters'] as $name => $value) {
                foreach (is_array($value) ? $value : [$value] as $v) {
                    $filterData[$name][(string) $v][] = $ordinal;
                }
            }
            foreach ($row['sortable'] as $field => $value) {
                $sortFields[$field][$ordinal] = (string) $value;
            }
        }

        return [$filterData, $sortFields];
    }
}
