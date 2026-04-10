<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Html;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Html\HtmlCleaner;

/**
 * Tests for the pure PHP HTML cleaner.
 */
class HtmlCleanerTest extends TestCase
{
    public function testCleanBasicHtml(): void
    {
        $html = '<p>Hello <strong>world</strong></p>';
        $result = HtmlCleaner::clean($html);

        $this->assertEquals('Hello world', $result);
    }

    public function testRemovesScript(): void
    {
        $html = '<p>Content</p><script>alert("xss")</script><p>More</p>';
        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Content', $result);
        $this->assertStringContainsString('More', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('script', $result);
    }

    public function testRemovesMultilineScript(): void
    {
        $html = "<p>Before</p>\n<script type=\"text/javascript\">\n  var x = 1;\n  console.log(x);\n</script>\n<p>After</p>";
        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
        $this->assertStringNotContainsString('console', $result);
        $this->assertStringNotContainsString('var x', $result);
    }

    public function testRemovesMultilineStyle(): void
    {
        $html = "<p>Before</p>\n<style>\n  body { color: red; }\n  h1 { font-size: 2em; }\n</style>\n<p>After</p>";
        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
        $this->assertStringNotContainsString('color', $result);
        $this->assertStringNotContainsString('font-size', $result);
    }

    public function testRemovesHtmlComments(): void
    {
        $html = '<p>Visible</p><!-- This is a comment --><p>Also visible</p>';
        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Visible', $result);
        $this->assertStringContainsString('Also visible', $result);
        $this->assertStringNotContainsString('comment', $result);
        $this->assertStringNotContainsString('<!--', $result);
    }

    public function testExtractMainContent(): void
    {
        $html = '<nav>Navigation</nav>'
            . '<div id="main-content"><p>Important content here</p></div>'
            . '<footer>Footer stuff</footer>';

        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Important content', $result);
        $this->assertStringNotContainsString('Navigation', $result);
        $this->assertStringNotContainsString('Footer stuff', $result);
    }

    public function testMainContentCaseInsensitive(): void
    {
        $html = '<div>Outside</div>'
            . '<DIV ID="main-content"><p>Inside main</p></DIV>'
            . '<div>Also outside</div>';

        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Inside main', $result);
        $this->assertStringNotContainsString('Outside', $result);
    }

    public function testRemovesFooterByClass(): void
    {
        $html = '<p>Content</p><div class="site-footer"><p>Footer content</p></div>';
        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Content', $result);
        $this->assertStringNotContainsString('Footer content', $result);
    }

    public function testRemovesFooterById(): void
    {
        $html = '<p>Content</p><div id="page-footer"><p>Footer content</p></div>';
        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Content', $result);
        $this->assertStringNotContainsString('Footer content', $result);
    }

    public function testHandlesMalformedHtml(): void
    {
        $html = '<p>Unclosed paragraph<div>Mixed <b>nesting</div></b>';
        $result = HtmlCleaner::clean($html);

        // Should not crash, should produce some text output.
        $this->assertIsString($result);
        $this->assertStringContainsString('Unclosed paragraph', $result);
    }

    public function testEmptyInput(): void
    {
        $result = HtmlCleaner::clean('');
        $this->assertIsString($result);
        $this->assertEquals('', $result);
    }

    public function testRemovesNav(): void
    {
        $html = '<nav><ul><li>Home</li><li>About</li></ul></nav>'
            . '<main><p>Page content here</p></main>';

        $result = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Page content', $result);
        $this->assertStringNotContainsString('Home', $result);
        $this->assertStringNotContainsString('About', $result);
    }
}
