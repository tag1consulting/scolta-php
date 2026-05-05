<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Html;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Html\PagefindHtmlBuilder;

/**
 * Tests for the pure PHP Pagefind HTML builder.
 */
class PagefindHtmlBuilderTest extends TestCase
{
    public function testBuildBasic(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-1',
            title: 'Test Title',
            body: 'Body text here',
            url: 'https://example.com/page',
            date: '2024-06-15',
            siteName: 'My Site',
        );

        // Verify data-pagefind-body attribute.
        $this->assertStringContainsString('data-pagefind-body', $html);

        // Verify id attribute on body.
        $this->assertStringContainsString('id="doc-1"', $html);

        // Verify title in <title> and <h1>.
        $this->assertStringContainsString('<title>Test Title</title>', $html);
        $this->assertStringContainsString('<h1>Test Title</h1>', $html);

        // Verify site filter.
        $this->assertStringContainsString('data-pagefind-filter="site:My Site"', $html);

        // Verify date meta.
        $this->assertStringContainsString('data-pagefind-meta="date:2024-06-15"', $html);

        // Verify URL meta.
        $this->assertStringContainsString('data-pagefind-meta="url:https://example.com/page"', $html);

        // Verify body content.
        $this->assertStringContainsString('Body text here', $html);
    }

    public function testEscapesContent(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-2',
            title: 'Tom & Jerry\'s <Adventure>',
            body: 'Content with "quotes" & <tags>',
            url: 'https://example.com/page?a=1&b=2',
            date: '2024-01-01',
            siteName: 'Site "One"',
        );

        // Title should be escaped (ENT_HTML5 uses &apos; for single quotes).
        $this->assertStringContainsString('Tom &amp; Jerry&apos;s &lt;Adventure&gt;', $html);

        // Body should be escaped.
        $this->assertStringContainsString('Content with &quot;quotes&quot; &amp; &lt;tags&gt;', $html);

        // URL should be escaped.
        $this->assertStringContainsString('url:https://example.com/page?a=1&amp;b=2', $html);

        // Site name should be escaped.
        $this->assertStringContainsString('site:Site &quot;One&quot;', $html);
    }

    public function testOmitsEmptySite(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-3',
            title: 'No Site',
            body: 'Body content',
            url: 'https://example.com',
            date: '2024-01-01',
            siteName: '',
        );

        // Site filter absent, but language filter is always present.
        $this->assertStringNotContainsString('data-pagefind-filter="site:', $html);
        $this->assertStringContainsString('data-pagefind-filter="language:en"', $html);

        // Should still have data-pagefind-body.
        $this->assertStringContainsString('data-pagefind-body', $html);
    }

    public function testDefaultLanguageIsEnglish(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-4',
            title: 'English',
            body: 'Body',
            url: 'https://example.com',
        );

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('data-pagefind-filter="language:en"', $html);
    }

    public function testLanguageAttribute(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-5',
            title: 'Español',
            body: 'Contenido en español',
            url: 'https://example.com/es',
            date: '2024-06-15',
            siteName: 'Mi Sitio',
            language: 'es',
        );

        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringContainsString('data-pagefind-filter="language:es"', $html);
    }

    public function testLanguageValueIsEscaped(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-6',
            title: 'Test',
            body: 'Body',
            url: 'https://example.com',
            language: 'zh-Hant',
        );

        $this->assertStringContainsString('<html lang="zh-Hant">', $html);
        $this->assertStringContainsString('data-pagefind-filter="language:zh-Hant"', $html);
    }

    public function testExtraFiltersEmitted(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-7',
            title: 'Test',
            body: 'Body',
            url: 'https://example.com',
            filters: ['base_topic' => 'Cardiology', 'region' => 'Europe'],
        );

        $this->assertStringContainsString('data-pagefind-filter="base_topic:Cardiology"', $html);
        $this->assertStringContainsString('data-pagefind-filter="region:Europe"', $html);
    }

    public function testExtraFilterValuesAreEscaped(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-8',
            title: 'Test',
            body: 'Body',
            url: 'https://example.com',
            filters: ['category' => 'Rock & Roll <genre>'],
        );

        $this->assertStringContainsString('data-pagefind-filter="category:Rock &amp; Roll &lt;genre&gt;"', $html);
        $this->assertStringNotContainsString('Rock & Roll', $html);
    }

    public function testEmptyFiltersProducesNoExtraSpans(): void
    {
        $html = PagefindHtmlBuilder::build(
            id: 'doc-9',
            title: 'Test',
            body: 'Body',
            url: 'https://example.com',
        );

        // Only site (absent) and language filters — no extra spans.
        $this->assertSame(1, substr_count($html, 'data-pagefind-filter='));
    }
}
