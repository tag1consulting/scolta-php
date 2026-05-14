<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Content\TrackerRecord;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Provider\AiResponse;

/**
 * Tests for immutable DTO (Data Transfer Object) classes.
 */
class DtoTest extends TestCase
{
    // -------------------------------------------------------------------
    // ContentItem
    // -------------------------------------------------------------------

    public function testContentItemStripsAbsoluteUrl(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://myapp.ddev.site/articles/foo-bar',
            date: '2024-01-01',
        );

        $this->assertEquals('/articles/foo-bar', $item->url);
    }

    public function testContentItemPreservesRelativeUrl(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: '/articles/foo-bar',
            date: '2024-01-01',
        );

        $this->assertEquals('/articles/foo-bar', $item->url);
    }

    public function testContentItemStripsAbsoluteUrlWithQueryAndFragment(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://example.com/search?q=test#results',
            date: '2024-01-01',
        );

        $this->assertEquals('/search?q=test#results', $item->url);
    }

    public function testContentItemSiteNameDefaults(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
        );

        $this->assertEquals('', $item->siteName);
    }

    public function testContentItemLanguageDefaults(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
        );

        $this->assertEquals('en', $item->language);
    }

    public function testContentItemFiltersDefault(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
        );

        $this->assertEquals([], $item->filters);
    }

    public function testContentItemFiltersPassthrough(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
            filters: ['base_topic' => 'Cardiology'],
        );

        $this->assertEquals(['base_topic' => 'Cardiology'], $item->filters);
    }

    public function testContentItemMetadataDefaults(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
        );

        $this->assertEquals([], $item->metadata);
    }

    public function testContentItemMetadataPassthrough(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
            metadata: ['price' => '29.99', 'rating' => '4.5'],
        );

        $this->assertEquals(['price' => '29.99', 'rating' => '4.5'], $item->metadata);
    }

    public function testContentItemSortableDefaults(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
        );

        $this->assertEquals([], $item->sortable);
    }

    public function testContentItemSortablePassthrough(): void
    {
        $item = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: 'https://x.com',
            date: '2024-01-01',
            sortable: ['price' => '29.99'],
        );

        $this->assertEquals(['price' => '29.99'], $item->sortable);
    }

    // -------------------------------------------------------------------
    // ContentItem::cloneWith()
    // -------------------------------------------------------------------

    public function testCloneWithNoOverridesIsIdentical(): void
    {
        $original = new ContentItem(
            id: 'page-1',
            title: 'Original Title',
            bodyHtml: '<p>Body</p>',
            url: '/page/1',
            date: '2024-06-15',
            siteName: 'My Site',
            language: 'fr',
            filters: ['type' => 'article'],
            metadata: ['price' => '9.99'],
            sortable: ['price' => '9.99'],
        );

        $clone = $original->cloneWith();

        $this->assertSame($original->id, $clone->id);
        $this->assertSame($original->title, $clone->title);
        $this->assertSame($original->bodyHtml, $clone->bodyHtml);
        $this->assertSame($original->url, $clone->url);
        $this->assertSame($original->date, $clone->date);
        $this->assertSame($original->siteName, $clone->siteName);
        $this->assertSame($original->language, $clone->language);
        $this->assertSame($original->filters, $clone->filters);
        $this->assertSame($original->metadata, $clone->metadata);
        $this->assertSame($original->sortable, $clone->sortable);
    }

    public function testCloneWithBodyHtmlPreservesMetadataAndSortable(): void
    {
        $original = new ContentItem(
            id: 'prod-42',
            title: 'Crystal',
            bodyHtml: '<p>old</p>',
            url: '/products/crystal',
            date: '2024-01-01',
            metadata: ['weight' => '50g', 'origin' => 'Brazil'],
            sortable: ['price' => '29.99', 'rating' => '4.5'],
        );

        $clone = $original->cloneWith(['bodyHtml' => '<p>enriched HTML</p>']);

        $this->assertSame('<p>enriched HTML</p>', $clone->bodyHtml);
        $this->assertSame($original->metadata, $clone->metadata, 'metadata must carry forward');
        $this->assertSame($original->sortable, $clone->sortable, 'sortable must carry forward');
        $this->assertSame($original->id, $clone->id);
        $this->assertSame($original->title, $clone->title);
    }

    public function testCloneWithMetadataOverridesMetadataOnly(): void
    {
        $original = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: '/p',
            date: '2024-01-01',
            metadata: ['price' => '10.00'],
            sortable: ['price' => '10.00'],
        );

        $clone = $original->cloneWith(['metadata' => ['price' => '15.00', 'sale' => 'true']]);

        $this->assertSame(['price' => '15.00', 'sale' => 'true'], $clone->metadata);
        $this->assertSame(['price' => '10.00'], $clone->sortable, 'sortable unchanged');
        $this->assertSame('B', $clone->bodyHtml, 'bodyHtml unchanged');
    }

    public function testCloneWithDoesNotMutateOriginal(): void
    {
        $original = new ContentItem(
            id: 'orig',
            title: 'Original',
            bodyHtml: 'B',
            url: '/orig',
            date: '2024-01-01',
        );

        $clone = $original->cloneWith(['title' => 'Modified', 'id' => 'clone']);

        $this->assertSame('Original', $original->title, 'original must not be mutated');
        $this->assertSame('orig', $original->id, 'original id must not be mutated');
        $this->assertSame('Modified', $clone->title);
        $this->assertSame('clone', $clone->id);
    }

    public function testCloneWithAbsoluteUrlOverrideIsNormalized(): void
    {
        $original = new ContentItem(
            id: '1',
            title: 'T',
            bodyHtml: 'B',
            url: '/page/1',
            date: '2024-01-01',
        );

        $clone = $original->cloneWith(['url' => 'https://production.example.com/page/2']);

        $this->assertSame('/page/2', $clone->url, 'absolute URL override must be normalized to a path');
    }

    // -------------------------------------------------------------------
    // TrackerRecord
    // -------------------------------------------------------------------

    public function testTrackerRecordDefaults(): void
    {
        $record = new TrackerRecord(
            contentId: '42',
            contentType: 'article',
        );

        $this->assertEquals(TrackerRecord::ACTION_INDEX, $record->action);
        $this->assertNull($record->changedAt);
    }

    // -------------------------------------------------------------------
    // AiResponse
    // -------------------------------------------------------------------

    public function testAiResponseDefaults(): void
    {
        $response = new AiResponse(content: 'Just content');

        $this->assertEquals('Just content', $response->content);
        $this->assertEquals(0, $response->inputTokens);
        $this->assertEquals(0, $response->outputTokens);
        $this->assertEquals('', $response->model);
    }

}
