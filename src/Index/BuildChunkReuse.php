<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Carry a build's chunk files forward so an unchanged chunk is not rebuilt.
 *
 * On a warm build the token cache saves tokenization and nothing else. Every
 * page is still fetched from the cache, stemmed, indexed, serialised and
 * committed, to produce a chunk file identical to the one the previous build
 * wrote. Measured after the GC and compression fixes, that chunk work is 48% of
 * a warm build.
 *
 * ## Why chunks have to be cut on ordinal ranges
 *
 * A chunk used to be "the next N pages in arrival order", which makes its
 * membership a function of the whole corpus: delete one node near the front and
 * every later chunk shifts by one page, so nothing matches. Measured on a
 * prototype, three edits and one delete left 0 of 60 chunks reusable.
 *
 * Ranges fix that. Range k holds the ordinals in `[k * size, (k + 1) * size)`,
 * and an ordinal is durable ({@see PageTableLedger}), so a page's chunk is a
 * property of the page and not of its neighbours. An edit dirties one range.
 *
 * ## What a key has to cover
 *
 * The obvious key is the page's content hash, and it is not enough.
 * {@see PhpIndexer::contentHash()} covers language, title, url and body,
 * because that is all tokenization depends on — but a chunk file also carries
 * the page's filters, sortable values, metadata, date, site name and id, none of
 * which are in that hash. A page whose facets changed but whose body did not has
 * the same content hash and a different chunk, so a content-hash key would
 * reuse a stale chunk and publish stale facet postings. {@see self::pageIdentity()}
 * therefore hashes everything that reaches the chunk, and the ordinal alongside
 * it, since two builds can assign the same id different ordinals after a
 * compaction and a chunk file carries ordinals inside it.
 *
 * Anything that is not per-page lives in {@see self::header()}: the range width,
 * the stemmer's language, the HMAC secret the chunk files were signed with, and
 * this format's own version. A header mismatch discards every key rather than
 * risking a chunk that no longer verifies or is cut on a different boundary.
 *
 * ## Why identities are stored per page and not just per range
 *
 * A single key per range would only be checkable once every page in the range
 * had arrived, and a build cannot hold that many page bodies. Storing one
 * identity per ordinal lets the build ask "is this page unchanged?" the moment
 * it arrives, and so drop the body immediately and resolve token data from the
 * cache later — only if the range turns out not to be reusable after all. The
 * range key is derived from those identities rather than stored beside them, so
 * the two cannot disagree.
 *
 * @phpstan-type SlimPage = object{id: string, url: string, date: string, siteName: string, language: string, filters: array<string, mixed>, sortable: array<string, mixed>, metadata: array<string, mixed>}
 *
 * @since 1.3.1
 * @stability experimental
 */
final class BuildChunkReuse
{
    /** Subdirectory holding the previous build's chunk files. */
    public const DIRNAME = 'chunks-prev';

    /** Key file inside {@see self::DIRNAME}. */
    public const KEYS_FILENAME = 'keys.php';

    /**
     * Format version of the carried-forward state.
     *
     * Bump when the chunk file format, the identity construction or the range
     * arithmetic changes, so a state directory written by an older Scolta is
     * discarded instead of reused into a wrong index.
     */
    private const STATE_VERSION = 1;

    /** Whether reuse may happen at all this build. */
    private bool $enabled = false;

    /**
     * The hash these keys are built with.
     *
     * Guarded the way {@see PhpIndexer::contentHash()} guards it: xxh128 has
     * been in core since PHP 8.1, but a host with a cut-down hash extension
     * would make an unguarded hash() call a fatal rather than a slow build. A
     * host that resolves this differently from the build that wrote the keys
     * simply fails every comparison, which costs a rebuild and never a wrong
     * chunk — and the algorithm is folded into the header anyway.
     */
    private static function algo(): string
    {
        return in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
    }

    /**
     * The previous build's identities, per range.
     *
     * @var array<int, array<int, string>> Range => ordinal => identity.
     */
    private array $previous = [];

    /**
     * This build's identities, per range, for the ranges it cut as ranges.
     *
     * @var array<int, array<int, string>> Range => ordinal => identity.
     */
    private array $current = [];

    /**
     * Ranges this build satisfied by linking the previous file.
     *
     * @var array<int, true>
     */
    private array $reusedRanges = [];

    /**
     * Chunk number each recorded range was written as, for promotion.
     *
     * @var array<int, int> Range => chunk number.
     */
    private array $chunkNumbers = [];

    private string $header = '';

    public function __construct(
        private readonly string $stateDir,
        private readonly StorageDriverInterface $storage,
    ) {}

    /**
     * Open the carried-forward state for a build.
     *
     * @param bool $enabled False disables reuse without changing anything else,
     *                      which is how the differential tests produce the
     *                      reference output.
     * @since 1.3.1
     * @stability experimental
     */
    public function begin(bool $enabled, int $rangeSize, string $language, ?string $hmacSecret): void
    {
        $this->header       = self::header($rangeSize, $language, $hmacSecret);
        $this->previous     = [];
        $this->current      = [];
        $this->reusedRanges = [];
        $this->chunkNumbers = [];
        $this->enabled      = $enabled;

        if (!$enabled) {
            return;
        }

        $this->previous = $this->loadPrevious();
    }

    /**
     * The compatibility fingerprint of everything a key does not carry.
     *
     * @since 1.3.1
     * @stability experimental
     */
    public static function header(int $rangeSize, string $language, ?string $hmacSecret): string
    {
        // The secret is hashed, never stored: the file sits in a state
        // directory an operator may well hand to somebody debugging a build.
        $secret = HmacSecret::normalize($hmacSecret);

        return hash(self::algo(), implode("\0", [
            'scolta-chunk-reuse',
            (string) self::STATE_VERSION,
            self::algo(),
            (string) $rangeSize,
            $language,
            $secret === null ? 'no-hmac' : hash('sha256', $secret),
        ]));
    }

    /**
     * Everything about a page that its chunk file depends on, as one hash.
     *
     * $contentHash stands in for title, body and attachment text, since it is
     * exactly what token data is keyed by. The rest are the fields
     * {@see IndexBuildOrchestrator::makeSlimProxy()} carries into the chunk, and
     * they matter: a page whose `filters` changed has an unchanged content hash
     * and a changed chunk.
     *
     * @param SlimPage $page Anything carrying the slim-proxy fields — a
     *                         ContentItem, a CachedContentReference, or the
     *                         proxy itself.
     * @since 1.3.1
     * @stability experimental
     */
    public static function pageIdentity(int $ordinal, string $contentHash, object $page): string
    {
        return hash(self::algo(), implode("\0", [
            (string) $ordinal,
            $contentHash,
            $page->id,
            $page->url,
            $page->date,
            $page->siteName,
            $page->language,
            // serialize() rather than a hand-rolled walk: these are arbitrary
            // nested arrays of scalars, and a key order that differs between
            // builds must read as "changed" — which costs a rebuilt chunk and
            // never a wrong one.
            serialize($page->filters),
            serialize($page->sortable),
            serialize($page->metadata),
        ]));
    }

    /**
     * The key naming a range's membership.
     *
     * Derived from the identities rather than stored alongside them, so there is
     * no second copy to fall out of step. Ordinal order, so two builds that
     * received the same pages in different orders produce the same key.
     *
     * @param array<int, string> $pages Ordinal => identity.
     * @since 1.3.1
     * @stability experimental
     */
    public static function rangeKey(array $pages): string
    {
        ksort($pages, SORT_NUMERIC);
        $parts = [];
        foreach ($pages as $ordinal => $identity) {
            $parts[] = $ordinal . ':' . $identity;
        }

        return hash(self::algo(), implode(',', $parts));
    }

    /**
     * True when reuse is switched on and a previous state was loaded.
     *
     * @since 1.3.1
     * @stability experimental
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The identity the previous build recorded for $ordinal, or null.
     *
     * The build asks this on arrival to decide whether a page's body can be
     * dropped, so it must not touch the disk.
     *
     * @since 1.3.1
     * @stability experimental
     */
    public function previousIdentity(int $range, int $ordinal): ?string
    {
        return $this->previous[$range][$ordinal] ?? null;
    }

    /**
     * True when the previous build's file for $range holds exactly $pages.
     *
     * @param array<int, string> $pages Ordinal => identity, as this build sees them.
     * @since 1.3.1
     * @stability experimental
     */
    public function canReuse(int $range, array $pages): bool
    {
        if (!$this->enabled || $pages === [] || !isset($this->previous[$range])) {
            return false;
        }

        if (self::rangeKey($this->previous[$range]) !== self::rangeKey($pages)) {
            return false;
        }

        return is_file($this->rangePath($range));
    }

    /**
     * Put the previous build's file for $range where this build wants it.
     *
     * Linked rather than copied: the bytes are identical by construction and
     * nothing rewrites a chunk file in place. A filesystem that cannot link
     * falls back to a copy, and a failure at both is not an error — the caller
     * simply rebuilds the chunk, which is always correct.
     *
     * @since 1.3.1
     * @stability experimental
     */
    public function linkInto(int $range, string $target): bool
    {
        $source = $this->rangePath($range);
        if (!is_file($source)) {
            return false;
        }
        if (is_file($target)) {
            return false;
        }

        return @link($source, $target) || @copy($source, $target);
    }

    /**
     * Record that $range was cut as a range chunk, so it can be carried forward.
     *
     * @param array<int, string> $pages Ordinal => identity.
     * @since 1.3.1
     * @stability experimental
     */
    public function recordRange(int $range, int $chunkNumber, array $pages, bool $reused): void
    {
        $this->current[$range]      = $pages;
        $this->chunkNumbers[$range] = $chunkNumber;
        if ($reused) {
            $this->reusedRanges[$range] = true;
        }
    }

    /**
     * Ranges this build linked rather than rebuilt.
     *
     * @since 1.3.1
     * @stability experimental
     */
    public function reusedRangeCount(): int
    {
        return count($this->reusedRanges);
    }

    /**
     * Carry this build's range chunks forward for the next one.
     *
     * Called after the merge has read the chunk files and before the coordinator
     * cleans the state directory. A reused range needs no move: the file it was
     * linked from is already in place and already correct.
     *
     * Ranges the previous build carried but this one did not cut — a corpus that
     * shrank, or a compaction — are removed, so the directory tracks the corpus
     * instead of growing forever.
     *
     * @param callable(int): string $chunkPathFor Chunk number => path on disk.
     * @since 1.3.1
     * @stability experimental
     */
    public function promote(callable $chunkPathFor): void
    {
        $dir = $this->dir();
        $this->storage->makeDirectory($dir);

        foreach ($this->current as $range => $pages) {
            if (isset($this->reusedRanges[$range])) {
                continue;
            }

            $source = $chunkPathFor($this->chunkNumbers[$range]);
            if (!is_file($source)) {
                // The chunk this range was written as is gone, so there is
                // nothing honest to carry forward for it.
                unset($this->current[$range]);
                continue;
            }

            $target = $this->rangePath($range);
            if (is_file($target)) {
                unlink($target);
            }
            if (!rename($source, $target) && !copy($source, $target)) {
                unset($this->current[$range]);
            }
        }

        foreach (array_keys($this->previous) as $range) {
            if (!isset($this->current[$range])) {
                $stale = $this->rangePath($range);
                if (is_file($stale)) {
                    unlink($stale);
                }
            }
        }

        $this->storage->put($dir . '/' . self::KEYS_FILENAME, serialize([
            'header' => $this->header,
            'ranges' => $this->current,
        ]));
    }

    /**
     * Discard the carried-forward state entirely.
     *
     * @since 1.3.1
     * @stability experimental
     */
    public function discard(): void
    {
        $dir = $this->dir();
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->previous = [];
    }

    private function dir(): string
    {
        return $this->stateDir . '/' . self::DIRNAME;
    }

    private function rangePath(int $range): string
    {
        return $this->dir() . '/range-' . $range . '.dat';
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function loadPrevious(): array
    {
        $path = $this->dir() . '/' . self::KEYS_FILENAME;
        if (!$this->storage->exists($path)) {
            return [];
        }

        try {
            $data = @unserialize($this->storage->get($path), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($data) || ($data['header'] ?? null) !== $this->header || !is_array($data['ranges'] ?? null)) {
            // Either written by an incompatible build or unreadable. Both mean
            // the same thing: rebuild everything, which is never wrong.
            return [];
        }

        $ranges = [];
        foreach ($data['ranges'] as $range => $pages) {
            if (!is_array($pages)) {
                continue;
            }
            $clean = [];
            foreach ($pages as $ordinal => $identity) {
                if (is_string($identity)) {
                    $clean[(int) $ordinal] = $identity;
                }
            }
            if ($clean !== []) {
                $ranges[(int) $range] = $clean;
            }
        }

        return $ranges;
    }
}
