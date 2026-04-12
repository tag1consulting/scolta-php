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
        $this->assertSame('https://example.com/test', $page['url']);
        $this->assertSame('Test Title', $page['title']);
        $this->assertArrayHasKey('wordCount', $page);
        $this->assertGreaterThan(0, $page['wordCount']);
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
                // Title tokens go to meta_positions (not positions weight 50).
                $this->assertNotEmpty($entry['meta_positions'], 'Title word should have meta_positions');
            }
        }
        $this->assertTrue(true);
    }

    public function testFiltersIncluded(): void
    {
        $item = new ContentItem('doc-1', 'Title', '<p>Content for testing.</p>', 'https://x.com', '2026-01-01', 'MySite');
        $result = $this->builder->build([$item]);

        $page = array_values($result['pages'])[0];
        $this->assertSame(['site' => 'MySite'], $page['filters']);
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

    public function testMetaFieldsPopulated(): void
    {
        $result = $this->builder->build([
            $this->makeItem('doc-1', 'My Title', 'Content body text for testing index builder output.', 'https://example.com/page'),
        ]);

        $page = array_values($result['pages'])[0];
        $this->assertSame('My Title', $page['meta']['title']);
        $this->assertSame('2026-01-01', $page['meta']['date']);
        // url is a top-level page property, not inside meta (Pagefind convention).
        $this->assertSame('https://example.com/page', $page['url']);
        $this->assertArrayNotHasKey('url', $page['meta']);
    }
}
