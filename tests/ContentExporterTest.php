<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\CachedContentReference;

/**
 * Tests ContentExporter::filterItems(), the minimum-content gate in front of
 * IndexBuildOrchestrator.
 */
class ContentExporterTest extends TestCase
{
    public function testFilterItemsReturnsGenerator(): void
    {
        $exporter = new ContentExporter();
        $result = $exporter->filterItems([]);
        $this->assertInstanceOf(\Generator::class, $result);
    }

    public function testFilterItemsYieldsOnlyPassingItems(): void
    {
        $exporter = new ContentExporter();

        $items = [
            new ContentItem('pass', 'Pass', '<p>' . str_repeat('word ', 20) . '</p>', '/pass', '2024-01-01'),
            new ContentItem('fail', 'Fail', '<p>Hi</p>', '/fail', '2024-01-01'),
        ];

        $yielded = iterator_to_array($exporter->filterItems($items));
        $this->assertCount(1, $yielded);
        $this->assertSame('pass', $yielded[0]->id);
    }

    public function testFilterItemsAcceptsGenerator(): void
    {
        $exporter = new ContentExporter();

        $source = (static function (): \Generator {
            yield new ContentItem('a', 'A', '<p>' . str_repeat('word ', 20) . '</p>', '/a', '2024-01-01');
            yield new ContentItem('b', 'B', '<p>Short</p>', '/b', '2024-01-01');
            yield new ContentItem('c', 'C', '<p>' . str_repeat('word ', 20) . '</p>', '/c', '2024-01-01');
        })();

        $yielded = iterator_to_array($exporter->filterItems($source));
        $this->assertCount(2, $yielded);
        $this->assertSame('a', $yielded[0]->id);
        $this->assertSame('c', $yielded[1]->id);
    }

    public function testCustomMinLength(): void
    {
        $exporter = new ContentExporter(minContentLength: 5);

        // "Short" is 5 chars — passes minContentLength=5.
        $yielded = iterator_to_array($exporter->filterItems([
            new ContentItem('x', 'T', '<b>Short</b>', '/x', '2024-01-01'),
        ]));
        $this->assertCount(1, $yielded);
    }

    // -------------------------------------------------------------------------
    // CachedContentReference pass-through (regression test for the re-index
    // crash: gather() yields mixed items when a prior build exists)
    // -------------------------------------------------------------------------

    public function testFilterItemsPassesThroughCachedContentReference(): void
    {
        $exporter = new ContentExporter();

        $ref = new CachedContentReference(
            entityKey: 'post-42',
            contentHash: 'abc123',
            id: 'post-42',
            url: '/my-post',
            date: '2024-01-01',
            siteName: 'Test',
            language: 'en',
            filters: [],
        );

        $yielded = iterator_to_array($exporter->filterItems([$ref]));

        $this->assertCount(1, $yielded);
        $this->assertSame($ref, $yielded[0]);
    }

    public function testFilterItemsMixedCachedAndContentItems(): void
    {
        $exporter = new ContentExporter(minContentLength: 10);

        $ref = new CachedContentReference(
            entityKey: 'post-1',
            contentHash: 'hash1',
            id: 'post-1',
            url: '/post-1',
            date: '2024-01-01',
            siteName: 'Test',
            language: 'en',
            filters: [],
        );
        $longItem  = new ContentItem('post-2', 'Long', '<p>' . str_repeat('word ', 20) . '</p>', '/post-2', '2024-01-01');
        $shortItem = new ContentItem('post-3', 'Short', '<p>Hi</p>', '/post-3', '2024-01-01');

        $source = (static function () use ($ref, $longItem, $shortItem): \Generator {
            yield $ref;
            yield $longItem;
            yield $shortItem;
        })();

        $yielded = iterator_to_array($exporter->filterItems($source));

        $this->assertCount(2, $yielded);
        $this->assertSame($ref, $yielded[0], 'CachedContentReference must pass through');
        $this->assertSame($longItem, $yielded[1], 'ContentItem with sufficient content must pass through');
    }
}
