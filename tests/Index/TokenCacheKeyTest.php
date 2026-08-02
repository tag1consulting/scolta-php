<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * What the token cache key has to cover.
 *
 * The cache stores the result of cleaning and tokenizing a page, and the key
 * decides when that result may be reused. Anything the cached value depends on
 * has to be in the key, or an edit produces a stale index with no error
 * anywhere.
 *
 * Two inputs were missing. The cached value includes `cleanTitle` and
 * `titleTokens`, both derived from the title, and `HtmlCleaner::clean()` strips
 * a leading title match from the body — so the title affects three of the six
 * cached fields while not appearing in the key at all. The language selects the
 * Snowball stemmer, so the same bytes indexed as English and as Spanish must
 * not share an entry either.
 */
#[CoversClass(PhpIndexer::class)]
final class TokenCacheKeyTest extends TestCase
{
    private static function item(
        string $title = 'Original Title',
        string $body = '<p>Some body text long enough to index properly here.</p>',
        string $url = '/page',
        string $language = 'en',
    ): ContentItem {
        return new ContentItem(
            id: 'item-1',
            title: $title,
            bodyHtml: $body,
            url: $url,
            date: '2025-01-01',
            siteName: 'Site',
            language: $language,
        );
    }

    public function testATitleOnlyEditChangesTheCacheKey(): void
    {
        $this->assertNotSame(
            PhpIndexer::contentHash(self::item(title: 'Original Title')),
            PhpIndexer::contentHash(self::item(title: 'Completely Different Title')),
            'A title-only edit reused the cached tokens, indexing the old title.',
        );
    }

    public function testALanguageChangeChangesTheCacheKey(): void
    {
        $this->assertNotSame(
            PhpIndexer::contentHash(self::item(language: 'en')),
            PhpIndexer::contentHash(self::item(language: 'es')),
            'Language selects the stemmer, so it must not share a cache entry.',
        );
    }

    public function testBodyAndUrlStillChangeTheKey(): void
    {
        $base = PhpIndexer::contentHash(self::item());

        $this->assertNotSame($base, PhpIndexer::contentHash(self::item(body: '<p>Different body text entirely.</p>')));
        $this->assertNotSame($base, PhpIndexer::contentHash(self::item(url: '/elsewhere')));
    }

    public function testIdenticalItemsShareAKey(): void
    {
        $this->assertSame(
            PhpIndexer::contentHash(self::item()),
            PhpIndexer::contentHash(self::item()),
            'The cache would never hit if the key were not stable.',
        );
    }

    /**
     * Fields that do not affect tokenization must NOT change the key, or the
     * cache misses on every build for no reason. The date, filters and
     * sortable values reach the fragment straight from the ContentItem and
     * never touch the cached token data.
     */
    public function testFieldsThatDoNotAffectTokenizationDoNotChangeTheKey(): void
    {
        $a = new ContentItem(
            id: 'item-1',
            title: 'T',
            bodyHtml: '<p>Body text long enough to index.</p>',
            url: '/p',
            date: '2025-01-01',
            siteName: 'Site',
            filters: ['topic' => 'a'],
            sortable: ['rank' => '1'],
        );
        $b = new ContentItem(
            id: 'item-1',
            title: 'T',
            bodyHtml: '<p>Body text long enough to index.</p>',
            url: '/p',
            date: '2099-12-31',
            siteName: 'Site',
            filters: ['topic' => 'z'],
            sortable: ['rank' => '9'],
        );

        $this->assertSame(PhpIndexer::contentHash($a), PhpIndexer::contentHash($b));
    }
}
