<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Html\HtmlCleaner;

/**
 * Build a partial inverted index for a chunk of content items.
 *
 * Each chunk produces a word→pages mapping with positions and weights.
 * Multiple chunks are later merged by IndexMerger into a complete index.
 */
class InvertedIndexBuilder
{
    /**
     * Maximum positions stored per term per weight bucket per page.
     *
     * Common stop words ("the", "and", "of") appear hundreds of times per
     * Wikipedia article. Without a cap, serializing merged position arrays
     * across 6930 pages during the pre-merge phase requires 25+ MB of
     * contiguous heap — enough to trigger OOM in constrained environments.
     * 200 positions per weight bucket is sufficient for phrase-proximity
     * scoring; additional occurrences are counted but their positions are
     * discarded. Title positions are not capped (titles are short).
     */
    private const MAX_POSITIONS_PER_WEIGHT = 200;

    public function __construct(
        private readonly Tokenizer $tokenizer,
        private readonly Stemmer $stemmer,
    ) {}

    /**
     * Build a partial inverted index from content items.
     *
     * @param ContentItem[] $items Content items to index.
     * @return array{index: array, pages: array}
     * @since 1.0.0
     * @stability stable
     */
    public function build(array $items, int $pageOffset = 0): array
    {
        $tokenDataList = [];
        foreach ($items as $item) {
            $tokenData = $this->tokenizeItem($item);
            if ($tokenData !== null) {
                $tokenDataList[] = ['item' => $item, 'tokenData' => $tokenData];
            }
        }

        return $this->buildFromTokenData($tokenDataList, $pageOffset);
    }

    /**
     * Extract tokenization data for a single content item.
     *
     * Returns one token array per TextChannel, keyed by the channel's value,
     * plus the derived text fields needed to build index entries. Returns null
     * when there is too little text to index. The returned array is safe to
     * serialize into a persistent cache. Token objects serialize efficiently
     * and are allowed in PageWordCache.
     *
     * Each channel is tokenized separately for weight differentiation.
     * Strip HTML tags and decode entities — CMS adapters may pass
     * titles like "<b>Bold Title</b>" or "Title &amp; Subtitle".
     * Remove <script>/<style> blocks first so their inner text (e.g.
     * "alert('xss')") is discarded, not kept as plain text by strip_tags.
     *
     * Pagefind uses word-sequential indices (0, 1, 2, 3...) not
     * character offsets. Positions are reindexed after tokenization so
     * they are comparable across pages and phrase_proximity_multiplier fires.
     *
     * @return array{titleTokens: Token[], bodyTokens: Token[], attachmentTokens: Token[],
     *               urlTokens: Token[], wordCount: int, cleanTitle: string, content: string}|null
     *
     * @since 1.0.0
     * @stability stable
     */
    public function tokenizeItem(ContentItem $item): ?array
    {
        $cleanText       = HtmlCleaner::clean($item->bodyHtml, $item->title);
        $cleanAttachment = $item->attachmentText !== ''
            ? HtmlCleaner::clean($item->attachmentText)
            : '';

        // Either source can carry the page. A stub whose real content is its
        // attachment has almost no body and would otherwise be dropped here,
        // which is the case this field exists to serve.
        if (strlen($cleanText) + strlen($cleanAttachment) < 10) {
            return null;
        }

        $titleRaw   = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $item->title) ?? $item->title;
        $cleanTitle = html_entity_decode(strip_tags($titleRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $urlPath     = parse_url($item->url, PHP_URL_PATH) ?? '';
        $urlPath     = preg_replace('/\.\w+$/', '', $urlPath);
        $urlSegments = array_filter(explode('/', $urlPath), fn($s) => strlen($s) > 0);

        // The one per-channel step: each is derived from a different place and
        // cleaned differently. Everything after this is driven by TextChannel.
        $texts = [
            TextChannel::Title->value      => $cleanTitle,
            TextChannel::Body->value       => $cleanText,
            TextChannel::Attachment->value => $cleanAttachment,
            TextChannel::Url->value        => implode(' ', $urlSegments),
        ];

        // Positions are numbered in TextChannel declaration order, which is
        // what keeps excerptable channels contiguous with `content` below and
        // Url past word_count. See the enum for why that ordering is
        // load-bearing rather than incidental.
        $tokens    = [];
        $wordCount = 0;
        $nextIndex = 0;
        foreach (TextChannel::cases() as $channel) {
            $result = $this->reindexToWordPositions(
                $this->tokenizer->tokenize($texts[$channel->value]),
                $nextIndex,
            );
            $tokens[$channel->value] = $result['tokens'];
            $nextIndex               = $result['nextIndex'];

            if ($channel->countsTowardWordCount()) {
                $wordCount += count($result['tokens']);
            }
        }

        // Fragment content mirrors what PagefindHtmlBuilder puts in <body>:
        // "<h1>title</h1><p>body...</p>". Pagefind extracts that as
        // "Title. Body..." in the content field. We must do the same so
        // scolta-core's content_match_score sees title words in the excerpt,
        // giving title-matching pages the same content boost as body matches.
        // The title keeps the sentence-ending period that mirroring implies;
        // every other channel is joined with a single space.
        $parts = [];
        foreach (TextChannel::cases() as $channel) {
            $text = $texts[$channel->value];
            if (!$channel->contributesToContent() || $text === '') {
                continue;
            }
            $parts[] = $channel === TextChannel::Title ? $text . '.' : $text;
        }

        return $tokens + [
            'wordCount'  => $wordCount,
            'cleanTitle' => $cleanTitle,
            'content'    => implode(' ', $parts),
        ];
    }

    /**
     * Build a partial index from a stream of pre-tokenized items.
     *
     * Unlike buildFromTokenData() which requires all items in an array, this
     * method accepts an iterable (typically a generator). Each item's token
     * arrays are freed after indexing, so only one item's tokens are in memory
     * at a time. This reduces peak RSS from O(N × tokens_per_page) to
     * O(tokens_per_page) for the token component.
     *
     * The inverted index and pages array still accumulate (unavoidable for a
     * single chunk commit), but token arrays dominate per-item memory.
     *
     * @param iterable<array{item: object, tokenData: array}> $items
     * @return array{index: array, pages: array}
     * @since 1.0.0
     * @stability stable
     */
    public function buildFromItemStream(iterable $items, int $pageOffset = 0): array
    {
        $index   = [];
        $pages   = [];
        $pageNum = $pageOffset;

        foreach ($items as ['item' => $item, 'tokenData' => $tokenData]) {
            $this->indexOne($index, $pages, $item, $tokenData, $pageNum);
            $pageNum++;
        }

        return ['index' => $index, 'pages' => $pages];
    }

    /**
     * Build a partial index from pre-tokenized item data.
     *
     * Accepts the output of tokenizeItem() paired with a metadata object for
     * the item (id, url, date, siteName, language, filters, sortable,
     * metadata). The object may be a ContentItem or any object with those
     * public properties — callers may pass a slim proxy to release bodyHtml
     * early and reduce memory pressure. Missing sortable/metadata read as empty.
     * Page numbers are assigned sequentially from pageOffset.
     *
     * Page numbers MUST be sequential. pagefind.js resolves search results via
     * pf_meta[1][page_num] where pf_meta[1] is a sequential array. Non-sequential
     * keys corrupt result resolution at runtime.
     *
     * @param array<int, array{item: object, tokenData: array}> $tokenDataList
     * @return array{index: array, pages: array}
     * @since 1.0.0
     * @stability stable
     */
    public function buildFromTokenData(array $tokenDataList, int $pageOffset = 0): array
    {
        $index   = [];
        $pages   = [];
        $pageNum = $pageOffset;

        foreach ($tokenDataList as ['item' => $item, 'tokenData' => $tokenData]) {
            $this->indexOne($index, $pages, $item, $tokenData, $pageNum);
            $pageNum++;
        }

        return ['index' => $index, 'pages' => $pages];
    }

    /**
     * Build a partial index using an explicit ordinal per item.
     *
     * The sequential-offset form above derives each page number from its
     * position in the gather order, which makes the ordinal a function of the
     * whole corpus: insert one page near the front and every later page
     * renumbers. When ordinals come from a durable ledger instead, they arrive
     * per item and are not contiguous within a chunk, so they cannot be
     * expressed as an offset.
     *
     * Ordinals must still be globally unique across the build.
     * `IndexMerger::mergeEntries()` resolves a collision by last-write-wins,
     * which is order-dependent (see MergeAlgebraTest), so two pages sharing an
     * ordinal do not raise an error — they silently lose one page's postings.
     * This method rejects a duplicate inside its own chunk; the ledger's
     * one-ordinal-per-id invariant covers the rest.
     *
     * @param array<int, array{item: object, tokenData: array<string, mixed>, ordinal: int}> $tokenDataList
     * @return array{index: array<int|string, mixed>, pages: array<int|string, mixed>}
     * @throws \InvalidArgumentException When two items in the chunk share an ordinal.
     * @since 1.2.0
     * @stability experimental
     */
    public function buildFromTokenDataWithOrdinals(array $tokenDataList): array
    {
        $index = [];
        $pages = [];

        foreach ($tokenDataList as ['item' => $item, 'tokenData' => $tokenData, 'ordinal' => $ordinal]) {
            if (isset($pages[$ordinal])) {
                throw new \InvalidArgumentException(
                    "Duplicate page ordinal {$ordinal} within one chunk: '{$pages[$ordinal]['id']}' and '{$item->id}'. "
                    . 'Ordinals must be globally unique; the merge silently drops one side of a collision.',
                );
            }
            $this->indexOne($index, $pages, $item, $tokenData, $ordinal);
        }

        return ['index' => $index, 'pages' => $pages];
    }

    /**
     * Add one item's page record and token postings at $pageNum.
     *
     * Shared by both build entry points so the page record is described once.
     */
    /**
     * The filter map a page actually carries: the auto-injected `site` and
     * `language` dimensions plus the item's own.
     *
     * Public and static because the value has two consumers now — the page
     * record built here, and the durable page table an incremental update
     * rebuilds `pf_filter` and `scolta.facets` from. Two descriptions of the
     * same merge would not raise an error, they would silently disagree about
     * which pages carry which filter.
     *
     * @return array<string, mixed>
     * @since 1.2.0
     * @stability experimental
     */
    public static function effectiveFilters(object $item): array
    {
        return array_merge(
            $item->siteName !== '' ? ['site' => $item->siteName] : [],
            $item->language !== '' ? ['language' => $item->language] : [],
            $item->filters,
        );
    }

    /**
     * The sortable map a page actually carries, with `date` folded in.
     *
     * Same reasoning as effectiveFilters(): the `pf_meta` sorts table and the
     * durable page table have to agree on it.
     *
     * @return array<string, mixed>
     * @since 1.2.0
     * @stability experimental
     */
    public static function effectiveSortable(object $item): array
    {
        $sortable = $item->sortable ?? [];
        $date     = $item->date ?? '';
        if ($date !== '' && !isset($sortable['date'])) {
            $sortable['date'] = $date;
        }

        return $sortable;
    }

    /**
     * @param array<int|string, mixed> $index
     * @param array<int|string, mixed> $pages
     * @param array<string, mixed>     $tokenData
     */
    private function indexOne(array &$index, array &$pages, object $item, array $tokenData, int $pageNum): void
    {
        $itemSortable = self::effectiveSortable($item);
        // Not every caller passes a full ContentItem — buildFromTokenData()
        // documents that a slim proxy is allowed — so read defensively.
        $itemMetadata = (array) ($item->metadata ?? []);
        $pages[$pageNum] = [
            'id'        => $item->id,
            'url'       => $item->url,
            'title'     => $tokenData['cleanTitle'],
            'content'   => $tokenData['content'],
            'wordCount' => $tokenData['wordCount'],
            'date'      => $item->date,
            'filters'   => self::effectiveFilters($item),
            // Precedence, highest first: title/date, then sortable, then
            // arbitrary per-item metadata. Sortable wins over metadata on a
            // key collision because a sortable key also has to line up with
            // the pf_meta sorts table. metadata is the cheap route to a
            // per-item meta key (an entity id, say) — it costs one fragment
            // field and nothing corpus-wide.
            'meta'      => array_filter([
                'title' => $tokenData['cleanTitle'],
                'date'  => $item->date,
            ] + $itemSortable + $itemMetadata, fn($v) => $v !== null && $v !== ''),
            'sortable'  => $itemSortable,
        ];

        // A bucket is absent only on token data cached before its channel
        // existed. Such an entry is only ever reached by a page that has no
        // text for that channel — see the key construction in
        // PhpIndexer::contentHash() — so an empty bucket is the correct
        // reading of it, not a dropped field.
        foreach (TextChannel::cases() as $channel) {
            $this->indexTokens($index, $tokenData[$channel->value] ?? [], $pageNum, $channel);
        }
    }

    /**
     * Reassign token positions to sequential word indices.
     *
     * Pagefind uses word-sequential indices (0, 1, 2, 3...) not
     * character offsets. This method converts after tokenization.
     *
     * @param Token[] $tokens Tokens from Tokenizer::tokenize()
     * @param int $startIndex Starting word index
     * @return array{tokens: Token[], nextIndex: int}
     */
    private function reindexToWordPositions(array $tokens, int $startIndex = 0): array
    {
        $reindexed = [];
        $wordIndex = $startIndex;
        foreach ($tokens as $token) {
            // New Token with reassigned position; stem/original strings are shared by
            // PHP's copy-on-write — no new string allocation for those properties.
            $reindexed[] = new Token($token->stem, $token->original, $wordIndex);
            $wordIndex++;
        }
        return ['tokens' => $reindexed, 'nextIndex' => $wordIndex];
    }

    /**
     * Add tokens to the inverted index for a page.
     *
     * @param Token[] $tokens
     */
    private function indexTokens(array &$index, array $tokens, int $pageNum, TextChannel $channel): void
    {
        $marker = $channel->positionMarker();
        foreach ($tokens as $token) {
            $stemmed = $this->stemmer->stem($token->stem);
            $position = $token->position;

            // Initialize word entry if needed.
            if (!isset($index[$stemmed])) {
                $index[$stemmed] = [];
            }
            if (!isset($index[$stemmed][$pageNum])) {
                $index[$stemmed][$pageNum] = [
                    'positions' => [],
                    'meta_positions' => [],
                ];
            }

            // A meta channel (null marker) goes to meta_positions only, encoded
            // in meta_locs. Pagefind's binary indexer puts title words in
            // meta_positions only — body tokens start at a higher word index,
            // so title words appear in body positions if and only if they also
            // occur in the body text. Do not duplicate them across both.
            // Every weighted channel goes to positions, encoded in locs.
            if ($marker === null) {
                $index[$stemmed][$pageNum]['meta_positions'][] = $position;
            } else {
                if (!isset($index[$stemmed][$pageNum]['positions'][$marker])) {
                    $index[$stemmed][$pageNum]['positions'][$marker] = [];
                }
                if (count($index[$stemmed][$pageNum]['positions'][$marker]) < self::MAX_POSITIONS_PER_WEIGHT) {
                    $index[$stemmed][$pageNum]['positions'][$marker][] = $position;
                }
            }

            // Track diacritic variants.
            if ($token->stem !== $token->original) {
                if (!isset($index[$stemmed]['_variants'])) {
                    $index[$stemmed]['_variants'] = [];
                }
                $original = $token->original;
                if (!isset($index[$stemmed]['_variants'][$original])) {
                    $index[$stemmed]['_variants'][$original] = [];
                }
                if (!in_array($pageNum, $index[$stemmed]['_variants'][$original], true)) {
                    $index[$stemmed]['_variants'][$original][] = $pageNum;
                }
            }
        }
    }
}
