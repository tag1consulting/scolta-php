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

    public function testContentItemConstructor(): void
    {
        $item = new ContentItem(
            id: '42',
            title: 'Test Article',
            bodyHtml: '<p>Body content</p>',
            url: 'https://example.com/article/42',
            date: '2024-06-15',
            siteName: 'Example Site',
        );

        $this->assertEquals('42', $item->id);
        $this->assertEquals('Test Article', $item->title);
        $this->assertEquals('<p>Body content</p>', $item->bodyHtml);
        $this->assertEquals('https://example.com/article/42', $item->url);
        $this->assertEquals('2024-06-15', $item->date);
        $this->assertEquals('Example Site', $item->siteName);
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

    public function testContentItemIsReadonly(): void
    {
        $item = new ContentItem('1', 'T', 'B', 'https://x.com', '2024-01-01');
        $ref = new \ReflectionClass($item);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }

    // -------------------------------------------------------------------
    // TrackerRecord
    // -------------------------------------------------------------------

    public function testTrackerRecordConstants(): void
    {
        $this->assertEquals('index', TrackerRecord::ACTION_INDEX);
        $this->assertEquals('delete', TrackerRecord::ACTION_DELETE);
    }

    public function testTrackerRecordDefaults(): void
    {
        $record = new TrackerRecord(
            contentId: '42',
            contentType: 'article',
        );

        $this->assertEquals('42', $record->contentId);
        $this->assertEquals('article', $record->contentType);
        $this->assertEquals(TrackerRecord::ACTION_INDEX, $record->action);
        $this->assertNull($record->changedAt);
    }

    public function testTrackerRecordDeleteAction(): void
    {
        $record = new TrackerRecord(
            contentId: '42',
            contentType: 'page',
            action: TrackerRecord::ACTION_DELETE,
        );

        $this->assertEquals(TrackerRecord::ACTION_DELETE, $record->action);
    }

    public function testTrackerRecordWithDate(): void
    {
        $now = new \DateTimeImmutable('2024-06-15 10:30:00');
        $record = new TrackerRecord(
            contentId: '1',
            contentType: 'node',
            action: TrackerRecord::ACTION_INDEX,
            changedAt: $now,
        );

        $this->assertSame($now, $record->changedAt);
        $this->assertEquals('2024-06-15', $record->changedAt->format('Y-m-d'));
    }

    public function testTrackerRecordIsReadonly(): void
    {
        $record = new TrackerRecord('1', 'article');
        $ref = new \ReflectionClass($record);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }

    // -------------------------------------------------------------------
    // AiResponse
    // -------------------------------------------------------------------

    public function testAiResponseConstructor(): void
    {
        $response = new AiResponse(
            content: 'Hello world',
            inputTokens: 100,
            outputTokens: 50,
            model: 'claude-sonnet-4-5-20250929',
        );

        $this->assertEquals('Hello world', $response->content);
        $this->assertEquals(100, $response->inputTokens);
        $this->assertEquals(50, $response->outputTokens);
        $this->assertEquals('claude-sonnet-4-5-20250929', $response->model);
    }

    public function testAiResponseDefaults(): void
    {
        $response = new AiResponse(content: 'Just content');

        $this->assertEquals('Just content', $response->content);
        $this->assertEquals(0, $response->inputTokens);
        $this->assertEquals(0, $response->outputTokens);
        $this->assertEquals('', $response->model);
    }

    public function testAiResponseIsReadonly(): void
    {
        $response = new AiResponse('test');
        $ref = new \ReflectionClass($response);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }
}
