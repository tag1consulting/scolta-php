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
            $cleanTitle = html_entity_decode(strip_tags($item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $titleTokens = $this->tokenizer->tokenize($cleanTitle);
            $bodyTokens = $this->tokenizer->tokenize($cleanText, mb_strlen($cleanTitle) + 1);

            // Tokenize URL path segments for search discovery.
            $urlPath = parse_url($item->url, PHP_URL_PATH) ?? '';
            $urlPath = preg_replace('/\.\w+$/', '', $urlPath); // Strip file extension.
            $urlSegments = array_filter(explode('/', $urlPath), fn ($s) => strlen($s) > 0);
            $urlText = implode(' ', $urlSegments);
            $urlTokens = $this->tokenizer->tokenize($urlText, mb_strlen($cleanTitle) + mb_strlen($cleanText) + 2);

            $wordCount = count($titleTokens) + count($bodyTokens) + count($urlTokens);

            // Build page entry.
            $pages[$pageNum] = [
                'id' => $item->id,
                'url' => $item->url,
                'title' => $cleanTitle,
                'content' => $cleanText,
                'wordCount' => $wordCount,
                'date' => $item->date,
                'filters' => $item->siteName !== '' ? ['site' => $item->siteName] : [],
                'meta' => array_filter([
                    'title' => $cleanTitle,
                    'date' => $item->date,
                ]),
                'hash' => hash('sha256', $cleanText),
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

            // Title tokens go to meta_positions (Pagefind encodes these
            // in meta_locs with field index markers, not in body locs).
            // Body/URL tokens go to positions (encoded in locs).
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
