<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Util;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Util\MarkdownRenderer;

/**
 * Tests for the MarkdownRenderer utility.
 */
class MarkdownRendererTest extends TestCase
{
    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', MarkdownRenderer::render(''));
    }

    public function testPlainTextWrappedInParagraph(): void
    {
        $this->assertSame(
            '<p>Hello world</p>',
            MarkdownRenderer::render('Hello world'),
        );
    }

    public function testBoldRendersAsStrong(): void
    {
        $this->assertSame(
            '<p>This is <strong>bold</strong> text</p>',
            MarkdownRenderer::render('This is **bold** text'),
        );
    }

    public function testMultipleBoldInSameLine(): void
    {
        $this->assertSame(
            '<p><strong>first</strong> and <strong>second</strong></p>',
            MarkdownRenderer::render('**first** and **second**'),
        );
    }

    public function testLinkRendersAsAnchor(): void
    {
        $this->assertSame(
            '<p>Visit <a href="https://example.com" target="_blank" rel="noopener">Example</a> now</p>',
            MarkdownRenderer::render('Visit [Example](https://example.com) now'),
        );
    }

    public function testBulletListRendersAsUl(): void
    {
        $input = "- First item\n- Second item\n- Third item";
        $expected = '<ul><li>First item</li><li>Second item</li><li>Third item</li></ul>';

        $this->assertSame($expected, MarkdownRenderer::render($input));
    }

    public function testMixedParagraphsAndList(): void
    {
        $input = "Introduction paragraph\n\n- Item one\n- Item two\n\nConclusion paragraph";
        $expected = '<p>Introduction paragraph</p><ul><li>Item one</li><li>Item two</li></ul><p>Conclusion paragraph</p>';

        $this->assertSame($expected, MarkdownRenderer::render($input));
    }

    public function testBoldInsideListItem(): void
    {
        $input = "- A **bold** item\n- A normal item";
        $expected = '<ul><li>A <strong>bold</strong> item</li><li>A normal item</li></ul>';

        $this->assertSame($expected, MarkdownRenderer::render($input));
    }

    public function testLinkInsideListItem(): void
    {
        $input = '- See [docs](https://docs.example.com) for details';
        $expected = '<ul><li>See <a href="https://docs.example.com" target="_blank" rel="noopener">docs</a> for details</li></ul>';

        $this->assertSame($expected, MarkdownRenderer::render($input));
    }

    public function testXssScriptTagIsEscaped(): void
    {
        $result = MarkdownRenderer::render('<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testXssInBoldIsEscaped(): void
    {
        $result = MarkdownRenderer::render('**<img src=x onerror=alert(1)>**');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringContainsString('<strong>', $result);
    }

    public function testXssInLinkTextIsEscaped(): void
    {
        $result = MarkdownRenderer::render('[<script>evil</script>](https://example.com)');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testHtmlEntitiesInPlainTextAreEscaped(): void
    {
        $result = MarkdownRenderer::render('Use the <div> element & "quotes"');

        $this->assertStringContainsString('&lt;div&gt;', $result);
        $this->assertStringContainsString('&amp;', $result);
        $this->assertStringContainsString('&quot;quotes&quot;', $result);
    }

    public function testMultipleParagraphsSeparatedByBlankLines(): void
    {
        $input = "First paragraph\n\nSecond paragraph\n\nThird paragraph";
        $expected = '<p>First paragraph</p><p>Second paragraph</p><p>Third paragraph</p>';

        $this->assertSame($expected, MarkdownRenderer::render($input));
    }

    public function testListClosedAtEndOfInput(): void
    {
        // List at end without trailing blank line.
        $input = "- Item one\n- Item two";
        $expected = '<ul><li>Item one</li><li>Item two</li></ul>';

        $this->assertSame($expected, MarkdownRenderer::render($input));
    }

    public function testWhitespaceOnlyLinesActAsBlankLines(): void
    {
        $input = "Paragraph one\n   \nParagraph two";
        $expected = '<p>Paragraph one</p><p>Paragraph two</p>';

        $this->assertSame($expected, MarkdownRenderer::render($input));
    }
}
