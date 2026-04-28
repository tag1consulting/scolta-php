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
    /** Weight for title matches. */
    private const TITLE_WEIGHT = 50;

    /** Weight for body content matches (default). */
    private const BODY_WEIGHT = 25;

    public function __construct(
        private readonly Tokenizer $tokenizer,
        private readonly Stemmer $stemmer,
    ) {
    }

    /**
     * Build a partial inverted index from content items.
     *
     * @param ContentItem[] $items Content items to index.
     * @return array{index: array, pages: array}
     */
    public function build(array $items, int $pageOffset = 0): array
    {
        $index = [];
        $pages = [];
        $pageNum = $pageOffset;  // Start from caller-provided offset

        foreach ($items as $item) {
            // Page numbers MUST be sequential. pagefind.js resolves search
            // results via pf_meta[1][page_num] where pf_meta[1] is a
            // sequential array. crc32 hashing and non-sequential keys
            // corrupt result resolution at runtime.

            $cleanText = HtmlCleaner::clean($item->bodyHtml, $item->title);

            if (strlen($cleanText) < 10) {
                continue;
            }

            // Tokenize title and body separately for weight differentiation.
            // Strip HTML tags and decode entities — CMS adapters may pass
            // titles like "<b>Bold Title</b>" or "Title &amp; Subtitle".
            // Remove <script>/<style> blocks first so their inner text (e.g.
            // "alert('xss')") is discarded, not kept as plain text by strip_tags.
            $titleRaw = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $item->title) ?? $item->title;
            $cleanTitle = html_entity_decode(strip_tags($titleRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Pagefind uses word-sequential indices (0, 1, 2, 3...) not
            // character offsets. Reindex after tokenization so positions are
            // comparable across pages and phrase_proximity_multiplier fires.
            $rawTitleTokens = $this->tokenizer->tokenize($cleanTitle);
            $titleResult = $this->reindexToWordPositions($rawTitleTokens, 0);
            $titleTokens = $titleResult['tokens'];

            $rawBodyTokens = $this->tokenizer->tokenize($cleanText);
            $bodyResult = $this->reindexToWordPositions($rawBodyTokens, $titleResult['nextIndex']);
            $bodyTokens = $bodyResult['tokens'];

            // Tokenize URL path segments for search discovery.
            $urlPath = parse_url($item->url, PHP_URL_PATH) ?? '';
            $urlPath = preg_replace('/\.\w+$/', '', $urlPath); // Strip file extension.
            $urlSegments = array_filter(explode('/', $urlPath), fn ($s) => strlen($s) > 0);
            $urlText = implode(' ', $urlSegments);
            $rawUrlTokens = $this->tokenizer->tokenize($urlText);
            $urlResult = $this->reindexToWordPositions($rawUrlTokens, $bodyResult['nextIndex']);
            $urlTokens = $urlResult['tokens'];

            // Pagefind word_count = content.split(' ').length — URL path
            // tokens are NOT counted even though they are indexed.
            $wordCount = count($titleTokens) + count($bodyTokens);

            // Fragment content mirrors what PagefindHtmlBuilder puts in <body>:
            // "<h1>title</h1><p>body...</p>". Pagefind extracts that as
            // "Title. Body..." in the content field. We must do the same so
            // scolta-core's content_match_score sees title words in the excerpt,
            // giving title-matching pages the same content boost as body matches.
            $content = $cleanTitle !== '' ? $cleanTitle . '. ' . $cleanText : $cleanText;

            // Build page entry.
            $pages[$pageNum] = [
                'id' => $item->id,
                'url' => $item->url,
                'title' => $cleanTitle,
                'content' => $content,
                'wordCount' => $wordCount,
                'date' => $item->date,
                'filters' => $item->siteName !== '' ? ['site' => $item->siteName] : [],
                'meta' => array_filter([
                    'title' => $cleanTitle,
                    'date' => $item->date,
                ]),
                'hash' => hash('sha256', $content),
            ];

            // Index title tokens with title weight.
            $this->indexTokens($index, $titleTokens, $pageNum, self::TITLE_WEIGHT);

            // Index body tokens with default weight.
            $this->indexTokens($index, $bodyTokens, $pageNum, self::BODY_WEIGHT);

            // Index URL tokens with body weight.
            $this->indexTokens($index, $urlTokens, $pageNum, self::BODY_WEIGHT);

            $pageNum++;
        }

        return ['index' => $index, 'pages' => $pages];
    }

    /**
     * Reassign token positions to sequential word indices.
     *
     * Pagefind uses word-sequential indices (0, 1, 2, 3...) not
     * character offsets. This method converts after tokenization.
     *
     * @param array $tokens Tokens from Tokenizer::tokenize()
     * @param int $startIndex Starting word index
     * @return array{tokens: array, nextIndex: int}
     */
    private function reindexToWordPositions(array $tokens, int $startIndex = 0): array
    {
        $reindexed = [];
        $wordIndex = $startIndex;
        foreach ($tokens as $token) {
            $reindexed[] = [
                'stem' => $token['stem'],
                'original' => $token['original'],
                'position' => $wordIndex,
            ];
            $wordIndex++;
        }
        return ['tokens' => $reindexed, 'nextIndex' => $wordIndex];
    }

    /**
     * Add tokens to the inverted index for a page.
     */
    private function indexTokens(array &$index, array $tokens, int $pageNum, int $weight): void
    {
        foreach ($tokens as $token) {
            $stemmed = $this->stemmer->stem($token['stem']);
            $position = $token['position'];

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

            // Title tokens go to meta_positions only (encoded in meta_locs).
            // Pagefind binary indexer puts title words in meta_positions only —
            // body tokens start at a higher word index, so title words will
            // appear in body positions if and only if they also occur in the
            // body text. Do not duplicate title positions into body positions.
            // Body/URL tokens go only to positions (encoded in locs).
            if ($weight === self::TITLE_WEIGHT) {
                $index[$stemmed][$pageNum]['meta_positions'][] = $position;
            } else {
                if (!isset($index[$stemmed][$pageNum]['positions'][$weight])) {
                    $index[$stemmed][$pageNum]['positions'][$weight] = [];
                }
                $index[$stemmed][$pageNum]['positions'][$weight][] = $position;
            }

            // Track diacritic variants.
            if ($token['stem'] !== $token['original']) {
                if (!isset($index[$stemmed]['_variants'])) {
                    $index[$stemmed]['_variants'] = [];
                }
                $original = $token['original'];
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
