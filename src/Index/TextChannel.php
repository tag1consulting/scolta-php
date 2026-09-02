<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * The text channels a page is indexed from, and what each contributes.
 *
 * A channel is one stream of words with its own relevance weight: the title,
 * the body, text extracted from an attachment, the URL path. Before this
 * existed each was wired in by hand at every site that touched token data —
 * the tokenizer, the indexer, the removal sweep, the cache size estimate — and
 * a new one was only correct if every site remembered it. The failure mode was
 * silence: `IncrementalIndexUpdater::collectRemovals()` swept a hardcoded list,
 * so a channel missing from it left orphaned postings on every page update
 * rather than raising anything.
 *
 * **Declaration order is position order, and it is load-bearing.** Callers
 * number tokens by iterating `cases()`, so a channel's place in this list
 * decides where its words land in the page's word sequence. Two consequences
 * follow from where a channel sits relative to `Url`:
 *
 *  - Pagefind builds an excerpt by splitting the fragment content on
 *    whitespace and indexing into it by match position, so a channel whose
 *    words are in `content` must be numbered contiguously with them.
 *  - `Url` is deliberately numbered past `word_count` (it contributes no
 *    words to it), which puts its positions beyond the reach of that excerpt
 *    builder. Anything placed after `Url` inherits that unreachability.
 *
 * So a channel meant to be excerptable belongs before `Url`.
 *
 * **Markers are magnitudes, not scores.** `positionMarker()` returns N for a
 * marker written to the index as `-N`; pagefind.js reads it back as
 * `weight: l.w / 24`, so the relevance a channel actually carries is
 * `(N - 1) / 24` — see `relevanceWeight()`. Body's 25 is therefore the 1.0
 * baseline and Attachment's 13 is half of it.
 *
 * **Adding a channel** takes two edits: a case here, in the right position,
 * and its text derivation in `InvertedIndexBuilder::tokenizeItem()` (which is
 * genuinely per-channel — a title is cleaned differently from a URL path).
 * Everything else — indexing, word count, content assembly, incremental
 * removal, cache accounting — reads this enum and follows automatically.
 *
 * Every method below answers per case rather than by negating one of them, so
 * a new case is a set of decisions the author has to make rather than a set
 * silently inherited from whichever channel the negation named.
 *
 * @since 1.3.0
 * @stability experimental
 */
enum TextChannel: string
{
    /** Page title. Routed to meta positions rather than a weight bucket. */
    case Title = 'titleTokens';

    /** Page body. The 1.0 relevance baseline every other channel is read against. */
    case Body = 'bodyTokens';

    /** Text extracted from an attached document (PDF, office file). */
    case Attachment = 'attachmentTokens';

    /** URL path segments, for search discovery. Not excerptable — see above. */
    case Url = 'urlTokens';

    /**
     * Magnitude of the weight marker written for this channel, or null when
     * the channel is stored as meta positions instead of a weight bucket.
     *
     * Null is not "no weight" — it is a different destination. Title positions
     * go to `meta_locs` behind the field marker -1, which is how Pagefind
     * distinguishes a title hit from a body hit at all.
     *
     * `Url` deliberately shares `Body`'s magnitude: URL words are indexed at
     * body relevance, and sharing the number merges them into one bucket,
     * which is the behaviour that predates this enum.
     *
     * @since 1.3.0
     * @stability experimental
     */
    public function positionMarker(): ?int
    {
        return match ($this) {
            self::Title      => null,
            self::Body       => 25,
            self::Attachment => 13,
            self::Url        => 25,
        };
    }

    /**
     * The bucket a position list opens in when it carries no marker.
     *
     * Pagefind lets exactly one bucket stay implicit, and it is the body's. A
     * decoder has to start somewhere, so this is non-nullable where
     * `positionMarker()` is not: `Body` carries a weight bucket by
     * construction, and a `Body` that stopped doing so would be a different
     * format rather than a null to handle.
     *
     * @since 1.3.0
     * @stability experimental
     */
    public static function implicitBucketMarker(): int
    {
        return self::Body->positionMarker()
            ?? throw new \LogicException('Body must carry a weight bucket: it is the implicit one.');
    }

    /**
     * The relevance this channel's matches carry, as pagefind.js computes it.
     *
     * Null for a meta channel, which is scored by a different path.
     *
     * @since 1.3.0
     * @stability experimental
     */
    public function relevanceWeight(): ?float
    {
        $marker = $this->positionMarker();

        return $marker === null ? null : ($marker - 1) / 24;
    }

    /**
     * Whether this channel's words count toward `word_count`.
     *
     * Pagefind divides by this number to normalize for length, so a channel
     * whose text is in `content` must count or the page reads as denser than
     * it is. `Url` text is not in `content`, so it does not count.
     *
     * Spelled out per case rather than as `$this !== self::Url`, so that adding
     * a channel is a decision made here rather than one inherited from whatever
     * the negation happened to mean.
     *
     * @since 1.3.0
     * @stability experimental
     */
    public function countsTowardWordCount(): bool
    {
        return match ($this) {
            self::Title, self::Body, self::Attachment => true,
            self::Url                                 => false,
        };
    }

    /**
     * Whether this channel's text is part of the stored fragment content.
     *
     * Content is what the excerpt is sliced out of, so this and position order
     * have to agree: a channel in `content` must be numbered before `Url`.
     *
     * Per case for the same reason as `countsTowardWordCount()`.
     *
     * @since 1.3.0
     * @stability experimental
     */
    public function contributesToContent(): bool
    {
        return match ($this) {
            self::Title, self::Body, self::Attachment => true,
            self::Url                                 => false,
        };
    }
}
