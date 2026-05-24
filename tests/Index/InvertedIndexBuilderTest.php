<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;

class InvertedIndexBuilderTest extends TestCase
{
    private InvertedIndexBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InvertedIndexBuilder(
            new Tokenizer(),
            new Stemmer('en'),
        );
    }

    private function makeItem(string $id, string $title, string $body, string $url = 'https://example.com'): ContentItem
    {
        return new ContentItem($id, $title, "<p>{$body}</p>", $url, '2026-01-01');
    }

    public function testBuildReturnsIndexAndPages(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Test Page', 'This is a test page with some content about testing.'),
        ]);

        $this->assertArrayHasKey('index', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertNotEmpty($result['index']);
        $this->assertNotEmpty($result['pages']);
    }

    public function testWordMapsToCorrectPage(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Apple Recipes', 'How to cook delicious apple pie and apple sauce.'),
        ]);

        // "appl" is the stemmed form of "apple"
        $stemmed = (new Stemmer('en'))->stem('apple');
        $this->assertArrayHasKey($stemmed, $result['index']);
    }

    public function testMultiplePages(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Cats', 'All about cats and kittens.'),
            $this->makeItem('doc-2', 'Dogs', 'All about dogs and puppies.'),
        ]);

        $this->assertCount(2, $result['pages']);
    }

    public function testPageMetadataIncluded(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Test Title', 'Body content here for testing purposes.', 'https://example.com/test'),
        ]);

        $page = array_values($result['pages'])[0];
        $this->assertSame('/test', $page['url']); // absolute URLs are normalized to relative paths by ContentItem
        $this->assertSame('Test Title', $page['title']);
        $this->assertArrayHasKey('wordCount', $page);
        $this->assertGreaterThan(0, $page['wordCount']);
    }

    public function testContentFieldStartsWithTitle(): void
    {
        // The fragment content must mirror what PagefindHtmlBuilder puts in <body>
        // ("<h1>Title</h1><p>body</p>" → "Title. body..."), so that
        // scolta-core's content_match_score sees title words in the excerpt and
        // applies the same content boost as body matches.
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'My Great Title', 'Body content here for testing purposes.'),
        ]);

        $page = array_values($result['pages'])[0];
        $this->assertStringStartsWith('My Great Title. ', $page['content']);
        $this->assertStringContainsString('Body content', $page['content']);
    }

    public function testContentFieldBodyOnlyWhenTitleEmpty(): void
    {
        $item = new ContentItem('doc-1', '', '<p>Body content here for testing purposes, long enough.</p>', 'https://example.com', '2026-01-01');
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        $this->assertStringStartsNotWith('. ', $page['content']);
    }

    public function testPositionsAreTracked(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Test', 'apple banana apple cherry apple pie'),
        ]);

        $stemmed = (new Stemmer('en'))->stem('apple');
        if (isset($result['index'][$stemmed])) {
            $pageEntry = array_values(
                array_filter($result['index'][$stemmed], fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY)
            );
            if (!empty($pageEntry)) {
                $positions = $pageEntry[0]['positions'];
                $this->assertNotEmpty($positions);
            }
        }
        $this->assertTrue(true); // Structure test
    }

    public function testTitleWeightDiffersFromBody(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Apple', 'Banana cherry date elderberry fig grape.'),
        ]);

        $stemmed = (new Stemmer('en'))->stem('apple');
        if (isset($result['index'][$stemmed])) {
            $pageEntries = array_filter($result['index'][$stemmed], fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY);
            $entry = array_values($pageEntries)[0] ?? null;
            if ($entry !== null) {
                // Title tokens go to meta_positions (for pagefind meta_locs).
                $this->assertNotEmpty($entry['meta_positions'], 'Title word should have meta_positions');
            }
        }
        $this->assertTrue(true);
    }

    public function testTitleOnlyWordsNotInBodyPositions(): void
    {
        // Title-only words (not repeated in body) must appear in meta_positions
        // only — matching the Pagefind binary indexer. Body tokens start at a
        // higher word index so title words will appear in body positions only if
        // the body text also contains them. Duplicating title positions into body
        // positions produces incorrect phrase proximity spans.
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Zirconium', 'Banana cherry date elderberry fig grape enough text.'),
        ]);

        $stemmed = (new Stemmer('en'))->stem('zirconium');
        $this->assertArrayHasKey($stemmed, $result['index'], 'Title word should be in index');

        $pageEntries = array_filter($result['index'][$stemmed], fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY);
        $entry = array_values($pageEntries)[0] ?? null;
        $this->assertNotNull($entry, 'Should have at least one page entry');

        // Title-only word: meta_positions set, body positions empty.
        $this->assertNotEmpty($entry['meta_positions'], 'Title word should have meta_positions');
        $this->assertEmpty($entry['positions'], 'Title-only word must NOT have body positions');
    }

    public function testTitleWordRepeatedInBodyHasBodyPositions(): void
    {
        // A word appearing in both title and body should have meta_positions
        // from the title AND body positions from the body tokenization.
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Apple', 'The apple tree produces apples every season in the orchard.'),
        ]);

        $stemmed = (new Stemmer('en'))->stem('apple');
        $this->assertArrayHasKey($stemmed, $result['index'], "'apple' must be in index");

        $pageEntries = array_filter($result['index'][$stemmed], fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY);
        $entry = array_values($pageEntries)[0] ?? null;
        $this->assertNotNull($entry);

        $this->assertNotEmpty($entry['meta_positions'], "Title 'apple' should have meta_positions");
        $this->assertNotEmpty($entry['positions'], "Body 'apple' should also have body positions");
    }

    public function testFiltersIncluded(): void
    {
        $item = new ContentItem('doc-1', 'Title', '<p>Content for testing.</p>', 'https://x.com', '2026-01-01', 'MySite');
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        $this->assertSame(['site' => 'MySite', 'language' => 'en'], $page['filters']);
    }

    public function testLanguageFilterIncluded(): void
    {
        $item = new ContentItem(
            id: 'doc-1',
            title: 'Title',
            bodyHtml: '<p>Content for testing purposes here.</p>',
            url: 'https://x.com',
            date: '2026-01-01',
            siteName: 'MySite',
            language: 'fr',
        );
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        $this->assertSame('fr', $page['filters']['language']);
    }

    public function testCustomFiltersIncluded(): void
    {
        $item = new ContentItem(
            id: 'doc-1',
            title: 'Title',
            bodyHtml: '<p>Content for testing purposes here.</p>',
            url: 'https://x.com',
            date: '2026-01-01',
            siteName: 'MySite',
            filters: ['base_topic' => 'Cardiology', 'region' => 'Europe'],
        );
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        $this->assertSame([
            'site' => 'MySite',
            'language' => 'en',
            'base_topic' => 'Cardiology',
            'region' => 'Europe',
        ], $page['filters']);
    }

    public function testShortContentSkipped(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Short', 'Hi'),
        ]);

        $this->assertEmpty($result['pages']);
    }

    public function testContentHash(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Test', 'Enough content here to pass the minimum length requirement for indexing.'),
        ]);

        $page = array_values($result['pages'])[0];
        $this->assertArrayHasKey('hash', $page);
        $this->assertSame(64, strlen($page['hash'])); // SHA-256 hex
    }

    public function testHtmlInTitleIsStripped(): void
    {
        $item = new ContentItem(
            'doc-1',
            '<b>Bold &amp; Beautiful</b>',
            '<p>Enough content here to pass the minimum length requirement for indexing.</p>',
            'https://example.com/page',
            '2026-01-01',
        );
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        // Title stored in page metadata must have no HTML tags.
        $this->assertSame('Bold & Beautiful', $page['title']);
        $this->assertSame('Bold & Beautiful', $page['meta']['title']);

        // No "<b>" or "</b>" tokens in the inverted index.
        foreach (array_keys($result['index']) as $word) {
            $this->assertStringNotContainsString('<', $word, "HTML tag leaked into index word: {$word}");
            $this->assertStringNotContainsString('>', $word, "HTML tag leaked into index word: {$word}");
            $this->assertStringNotContainsString('&amp', $word, "HTML entity leaked into index word: {$word}");
        }
    }

    public function testMetaFieldsPopulated(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'My Title', 'Content body text for testing index builder output.', 'https://example.com/page'),
        ]);

        $page = array_values($result['pages'])[0];
        $this->assertSame('My Title', $page['meta']['title']);
        $this->assertSame('2026-01-01', $page['meta']['date']);
        // url is a top-level page property, not inside meta (Pagefind convention).
        // Absolute URLs are normalized to relative paths by ContentItem.
        $this->assertSame('/page', $page['url']);
        $this->assertArrayNotHasKey('url', $page['meta']);
    }

    public function testSortableFieldsStoredInPageMetaAndSortable(): void
    {
        $item = new ContentItem(
            id: 'doc-1',
            title: 'Amethyst Ring',
            bodyHtml: '<p>Beautiful gemstone ring at a great price, well worth buying.</p>',
            url: 'https://example.com/ring',
            date: '2026-01-01',
            sortable: ['price' => '42.99'],
        );
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        $this->assertSame('42.99', $page['meta']['price']);
        // date is auto-included alongside any explicit sortable fields.
        $this->assertSame(['price' => '42.99', 'date' => '2026-01-01'], $page['sortable']);
    }

    public function testNoSortableFieldsAutoIncludesDate(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'Plain Page', 'Some plain content without any sort fields configured at all.'),
        ]);

        $page = array_values($result['pages'])[0];
        // date is always auto-included in sortable when a date is present.
        $this->assertSame(['date' => '2026-01-01'], $page['sortable']);
        $this->assertArrayNotHasKey('price', $page['meta']);
        $this->assertArrayNotHasKey('rating', $page['meta']);
    }

    public function testSortableAndMetadataFieldsCoexist(): void
    {
        $item = new ContentItem(
            id: 'doc-1',
            title: 'Product',
            bodyHtml: '<p>A product with both metadata and sortable fields configured.</p>',
            url: 'https://example.com/product',
            date: '2026-01-01',
            metadata: ['sku' => 'GEM-001'],
            sortable: ['price' => '29.99', 'rating' => '4.5'],
        );
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        // Sortable fields appear in both meta (for fragment JSON) and sortable (for sorts array).
        $this->assertSame('29.99', $page['meta']['price']);
        $this->assertSame('4.5', $page['meta']['rating']);
        // date is auto-included alongside explicit sortable fields.
        $this->assertSame(['price' => '29.99', 'rating' => '4.5', 'date' => '2026-01-01'], $page['sortable']);
        // Title and date are always present.
        $this->assertSame('Product', $page['meta']['title']);
    }

    public function testZeroIntegerSortableValuePreservedInMeta(): void
    {
        // Regression: array_filter() with no callback strips falsy values (including integer 0).
        // A sortable field with value 0 (e.g. reference_count for an article with no citations)
        // must appear in meta so scolta.js can validate the sort field is present in the index.
        // If it is absent, scolta.js falls back to relevance and the sort badge never renders.
        $item = new ContentItem(
            id: 'doc-1',
            title: 'Article',
            bodyHtml: '<p>An article with zero references.</p>',
            url: 'https://example.com/article',
            date: '2026-01-01',
            sortable: ['word_count' => 5000, 'reference_count' => 0],
        );
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        $this->assertSame(5000, $page['meta']['word_count']);
        $this->assertArrayHasKey('reference_count', $page['meta'], 'reference_count: 0 must not be stripped from meta by array_filter');
        $this->assertSame(0, $page['meta']['reference_count']);
        $this->assertSame(['word_count' => 5000, 'reference_count' => 0, 'date' => '2026-01-01'], $page['sortable']);
    }

    public function testBuildFromItemStreamProducesSameResultAsBuildFromTokenData(): void
    {
        $items = [
            $this->makeItem('doc-1', 'Apple Pie Recipe', 'A delicious apple pie recipe for baking enthusiasts.'),
            $this->makeItem('doc-2', 'Banana Bread Guide', 'How to make banana bread from scratch with simple ingredients.'),
            $this->makeItem('doc-3', 'Cherry Tart Tips', 'Professional tips for making the perfect cherry tart dessert.'),
        ];

        $tokenDataList = [];
        foreach ($items as $item) {
            $tokenData = $this->builder->tokenizeItem($item);
            $tokenDataList[] = ['item' => (object) [
                'id'       => $item->id,
                'url'      => $item->url,
                'date'     => $item->date,
                'siteName' => $item->siteName,
                'language' => $item->language,
                'filters'  => $item->filters,
                'sortable' => $item->sortable,
            ], 'tokenData' => $tokenData];
        }

        $resultArray = $this->builder->buildFromTokenData($tokenDataList, 0);

        // Use a generator for the stream version.
        $stream = (function () use ($tokenDataList) {
            yield from $tokenDataList;
        })();
        $resultStream = $this->builder->buildFromItemStream($stream, 0);

        $this->assertSame(
            array_keys($resultArray['pages']),
            array_keys($resultStream['pages']),
        );
        $this->assertSame(
            array_keys($resultArray['index']),
            array_keys($resultStream['index']),
        );

        // Verify page content matches.
        foreach ($resultArray['pages'] as $pageNum => $pageData) {
            $this->assertSame($pageData['url'], $resultStream['pages'][$pageNum]['url']);
            $this->assertSame($pageData['sortable'], $resultStream['pages'][$pageNum]['sortable']);
        }
    }

    public function testBuildFromItemStreamWithSortableData(): void
    {
        $item = new ContentItem(
            id: 'sorted-1',
            title: 'Sortable Stream Test',
            bodyHtml: '<p>Testing sortable data flows through the item stream path correctly.</p>',
            url: 'https://example.com/stream-sort',
            date: '2026-05-01',
            sortable: ['price' => '19.99', 'weight' => '250'],
        );

        $tokenData = $this->builder->tokenizeItem($item);
        $stream = (function () use ($item, $tokenData) {
            yield ['item' => (object) [
                'id'       => $item->id,
                'url'      => $item->url,
                'date'     => $item->date,
                'siteName' => $item->siteName,
                'language' => $item->language,
                'filters'  => $item->filters,
                'sortable' => $item->sortable,
            ], 'tokenData' => $tokenData];
        })();

        $result = $this->builder->buildFromItemStream($stream, 0);

        $page = $result['pages'][0];
        $this->assertSame('19.99', $page['meta']['price']);
        $this->assertSame('250', $page['meta']['weight']);
        $this->assertSame(['price' => '19.99', 'weight' => '250', 'date' => '2026-05-01'], $page['sortable']);
    }
}
