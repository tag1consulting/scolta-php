<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;

/**
 * Verifies that InvertedIndexBuilder assigns sequential page numbers
 * regardless of the input array key types.
 *
 * This test guards against the crc32 fallback that was removed in 0.2.1:
 * non-integer keys (WP post ID strings, Drupal UUIDs) previously generated
 * crc32-based page numbers that could collide and scramble result ordering.
 */
class PageNumberingTest extends TestCase
{
    private InvertedIndexBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InvertedIndexBuilder(new Tokenizer(), new Stemmer('en'));
    }

    /** Make a ContentItem with enough body text to survive the 10-char check. */
    private function item(string $id, string $body = ''): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: "Title for {$id}",
            bodyHtml: '<p>' . ($body ?: "This is a sufficient body text for item {$id} to pass the minimum length check.") . '</p>',
            url: "/{$id}",
            date: '2024-01-01',
        );
    }

    public function testSequentialIntegerKeys(): void
    {
        $items = [
            0 => $this->item('item-0'),
            1 => $this->item('item-1'),
            2 => $this->item('item-2'),
        ];

        $result = $this->builder->build($items);
        $pageKeys = array_keys($result['pages']);
        sort($pageKeys);

        $this->assertSame([0, 1, 2], $pageKeys, 'Sequential integer keys must produce pages 0, 1, 2');
    }

    public function testStringKeysProduceSequentialPages(): void
    {
        // WP-style: string post IDs as array keys.
        $items = [
            '12'  => $this->item('post-12'),
            '34'  => $this->item('post-34'),
            '56'  => $this->item('post-56'),
        ];

        $result = $this->builder->build($items);
        $pageKeys = array_keys($result['pages']);
        sort($pageKeys);

        $this->assertSame(
            [0, 1, 2],
            $pageKeys,
            'String-keyed items must produce sequential pages 0, 1, 2 — NOT 12, 34, 56'
        );
    }

    public function testUuidKeysProduceSequentialPages(): void
    {
        // Drupal-style: UUID strings as array keys.
        $items = [
            'abc-123-def' => $this->item('node-abc'),
            'def-456-ghi' => $this->item('node-def'),
        ];

        $result = $this->builder->build($items);
        $pageKeys = array_keys($result['pages']);
        sort($pageKeys);

        $this->assertSame(
            [0, 1],
            $pageKeys,
            'UUID-keyed items must produce sequential pages 0, 1'
        );
    }

    public function testSkippedItemsDoNotGapPageNumbers(): void
    {
        // Item at index 1 has too-short body — it gets skipped.
        // Page numbers must remain gap-free (0, 1) not (0, 2).
        $items = [
            $this->item('item-a', 'This is a sufficient body text to pass the minimum character length check.'),
            new ContentItem(id: 'item-skip', title: 'Short', bodyHtml: '<p>Too short</p>', url: '/skip', date: '2024-01-01'),
            $this->item('item-c', 'Another sufficient body text for item c that definitely passes the length requirement.'),
        ];

        $result = $this->builder->build($items);
        $pageKeys = array_keys($result['pages']);
        sort($pageKeys);

        $this->assertSame(
            [0, 1],
            $pageKeys,
            'Skipped items must not create gaps — expected pages 0 and 1, not 0 and 2'
        );
        $this->assertCount(2, $result['pages'], 'Exactly 2 pages should be indexed (1 skipped)');
    }

    public function testWordEntryPageReferencesAreValidIndices(): void
    {
        // Index a known word and verify all page references in the inverted
        // index are valid indices into the pages array.
        $items = [
            'post-10' => $this->item('item-x', 'The quick brown fox searches for information online quickly.'),
            'post-20' => $this->item('item-y', 'The search engine processes all the searching queries carefully.'),
            'post-30' => $this->item('item-z', 'No matching words here — completely unrelated content about databases.'),
        ];

        $result = $this->builder->build($items);
        $pageCount = count($result['pages']);
        $validIndices = range(0, $pageCount - 1);

        foreach ($result['index'] as $word => $entries) {
            if ($word === '_variants') {
                continue;
            }
            foreach ($entries as $pageNum => $data) {
                if ($pageNum === '_variants') {
                    continue;
                }
                $this->assertContains(
                    (int) $pageNum,
                    $validIndices,
                    "Word '{$word}' references page {$pageNum} which is out of range [0..{$pageCount})"
                );
            }
        }
    }

    public function testPageOffsetParameterProducesGloballyUniqueNumbers(): void
    {
        // Simulates what PhpIndexer does for multi-chunk builds:
        // chunk 0 starts at offset 0, chunk 1 starts at offset = count(chunk 0 pages).
        $chunk0Items = [
            $this->item('c0-item-a', 'First chunk first item with adequate body text for indexing purposes here.'),
            $this->item('c0-item-b', 'First chunk second item with adequate body text for indexing purposes here.'),
        ];
        $chunk1Items = [
            $this->item('c1-item-a', 'Second chunk first item with adequate body text for indexing purposes here.'),
            $this->item('c1-item-b', 'Second chunk second item with adequate body text for indexing purposes here.'),
        ];

        $partial0 = $this->builder->build($chunk0Items, 0);
        $offset = count($partial0['pages']);
        $partial1 = $this->builder->build($chunk1Items, $offset);

        $allPageKeys = array_merge(
            array_keys($partial0['pages']),
            array_keys($partial1['pages'])
        );

        $this->assertCount(
            count($allPageKeys),
            array_unique($allPageKeys),
            'Multi-chunk page numbers must be globally unique — no collisions between chunks'
        );

        sort($allPageKeys);
        $this->assertSame(
            [0, 1, 2, 3],
            $allPageKeys,
            'Multi-chunk pages must form a contiguous 0-based range'
        );
    }
}
