<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;

/**
 * ContentItem::$metadata must reach the fragment on the PHP indexer path.
 *
 * It did not. IndexBuildOrchestrator::makeSlimProxy() and
 * PhpIndexer::tokenizeItems() each built a seven-field proxy that omitted the
 * field, and InvertedIndexBuilder composed fragment meta from title, date and
 * sortable only — so the field was silently dead, and the only route to an
 * arbitrary per-item meta key was `sortable`, which additionally writes a
 * corpus-wide entry into the eagerly loaded pf_meta sorts table. On a
 * 109,308-page corpus that measured as roughly a 25-35% increase in cold
 * bootstrap payload to carry one identifier.
 *
 * These tests pin the passthrough at the builder, at both stream entry points,
 * and — critically — pin the precedence rules, because a metadata key that
 * quietly overwrote `title` would be a much worse bug than the one being fixed.
 */
class MetadataPassthroughTest extends TestCase
{
    private InvertedIndexBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InvertedIndexBuilder(new Tokenizer(), new Stemmer('en'));
    }

    private function makeItem(array $metadata = [], array $sortable = []): ContentItem
    {
        return new ContentItem(
            'doc-1',
            'Apple Recipes',
            '<p>How to cook delicious apple pie and apple sauce at home.</p>',
            'https://example.com/apple',
            '2026-01-01',
            '',
            'en',
            [],
            $metadata,
            $sortable,
        );
    }

    public function testMetadataReachesFragmentMeta(): void
    {
        $result = $this->builder->build([$this->makeItem(['entity_id' => '4711'])]);
        $page = $result['pages'][0];

        $this->assertSame('4711', $page['meta']['entity_id']);
    }

    public function testMetadataDoesNotLeakIntoSortable(): void
    {
        // The whole point: a per-item meta key must NOT buy a corpus-wide entry
        // in the pf_meta sorts table.
        $result = $this->builder->build([$this->makeItem(['entity_id' => '4711'])]);
        $page = $result['pages'][0];

        $this->assertArrayNotHasKey('entity_id', $page['sortable']);
    }

    public function testTitleAndDateWinOverMetadata(): void
    {
        $result = $this->builder->build([
            $this->makeItem(['title' => 'hijacked', 'date' => '1970-01-01']),
        ]);
        $page = $result['pages'][0];

        $this->assertSame('Apple Recipes', $page['meta']['title']);
        $this->assertSame('2026-01-01', $page['meta']['date']);
    }

    public function testSortableWinsOverMetadataOnKeyCollision(): void
    {
        // A sortable key also has to line up with the pf_meta sorts table, so it
        // is the authority when both carry the same name.
        $result = $this->builder->build([
            $this->makeItem(['price' => 'from metadata'], ['price' => 'from sortable']),
        ]);
        $page = $result['pages'][0];

        $this->assertSame('from sortable', $page['meta']['price']);
    }

    public function testEmptyMetadataChangesNothing(): void
    {
        // The default is [], so no existing corpus sees a byte of difference.
        $withNone = $this->builder->build([$this->makeItem()]);
        $this->assertSame(
            ['title' => 'Apple Recipes', 'date' => '2026-01-01'],
            $withNone['pages'][0]['meta'],
        );
    }

    public function testBuildFromTokenDataCarriesMetadataFromASlimProxy(): void
    {
        $item = $this->makeItem(['entity_id' => '99']);
        $tokenData = $this->builder->tokenizeItem($item);
        $this->assertNotNull($tokenData);

        // The shape IndexBuildOrchestrator::makeSlimProxy() produces.
        $proxy = (object) [
            'id'       => $item->id,
            'url'      => $item->url,
            'date'     => $item->date,
            'siteName' => $item->siteName,
            'language' => $item->language,
            'filters'  => $item->filters,
            'sortable' => $item->sortable,
            'metadata' => $item->metadata,
        ];

        $result = $this->builder->buildFromTokenData([
            ['item' => $proxy, 'tokenData' => $tokenData],
        ]);

        $this->assertSame('99', $result['pages'][0]['meta']['entity_id']);
    }

    public function testBuildFromItemStreamCarriesMetadata(): void
    {
        $item = $this->makeItem(['entity_id' => '77']);
        $tokenData = $this->builder->tokenizeItem($item);
        $this->assertNotNull($tokenData);

        $stream = (static function () use ($item, $tokenData) {
            yield ['item' => $item, 'tokenData' => $tokenData];
        })();

        $result = $this->builder->buildFromItemStream($stream);

        $this->assertSame('77', $result['pages'][0]['meta']['entity_id']);
    }

    public function testAProxyWithoutMetadataStillBuilds(): void
    {
        // buildFromTokenData() documents that any object with the right public
        // properties is acceptable, so a caller predating this field must not
        // start throwing.
        $item = $this->makeItem();
        $tokenData = $this->builder->tokenizeItem($item);
        $this->assertNotNull($tokenData);

        $legacyProxy = (object) [
            'id'       => $item->id,
            'url'      => $item->url,
            'date'     => $item->date,
            'siteName' => $item->siteName,
            'language' => $item->language,
            'filters'  => $item->filters,
            'sortable' => $item->sortable,
        ];

        $result = $this->builder->buildFromTokenData([
            ['item' => $legacyProxy, 'tokenData' => $tokenData],
        ]);

        $this->assertSame('Apple Recipes', $result['pages'][0]['meta']['title']);
    }
}
