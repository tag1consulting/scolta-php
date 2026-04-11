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
    public function build(array $items): array
    {
        $index = [];
        $pages = [];

        foreach ($items as $pageId => $item) {
            $pageNum = is_int($pageId) ? $pageId : crc32($item->id) & 0x7FFFFFFF;

            $cleanText = HtmlCleaner::clean($item->bodyHtml, $item->title);

            if (strlen($cleanText) < 10) {
                continue;
            }

            // Tokenize title and body separately for weight differentiation.
            $titleTokens = $this->tokenizer->tokenize($item->title);
            $bodyTokens = $this->tokenizer->tokenize($cleanText, mb_strlen($item->title) + 1);

            $wordCount = count($titleTokens) + count($bodyTokens);

            // Build page entry.
            $pages[$pageNum] = [
                'id' => $item->id,
                'url' => $item->url,
                'title' => $item->title,
                'content' => $cleanText,
                'wordCount' => $wordCount,
                'date' => $item->date,
                'filters' => $item->siteName !== '' ? ['site' => $item->siteName] : [],
                'meta' => array_filter([
                    'title' => $item->title,
                    'url' => $item->url,
                    'date' => $item->date,
                ]),
                'hash' => hash('sha256', $cleanText),
            ];

            // Index title tokens with title weight.
            $this->indexTokens($index, $titleTokens, $pageNum, self::TITLE_WEIGHT);

            // Index body tokens with default weight.
            $this->indexTokens($index, $bodyTokens, $pageNum, self::BODY_WEIGHT);
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
                ];
            }

            // Add position under the weight group.
            if (!isset($index[$stemmed][$pageNum]['positions'][$weight])) {
                $index[$stemmed][$pageNum]['positions'][$weight] = [];
            }
            $index[$stemmed][$pageNum]['positions'][$weight][] = $position;

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
