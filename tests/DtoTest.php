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
