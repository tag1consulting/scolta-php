<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Util;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Util\MarkdownRenderer;

/**
 * PHP half of the renderer parity gate.
 *
 * MarkdownRenderer::render() and the JS formatSummary()/formatInline() pair
 * (assets/js/scolta.js) render the same AI output and share a core contract:
 * bold, italic, links, code-backtick passthrough, list/paragraph structure,
 * HTML escaping, and broken-markdown repair. Each fixture under
 * tests/fixtures/render-parity/ is asserted here against the PHP renderer
 * and in tests/js/render-parity.test.js against the real scolta.js — so
 * drift on either side fails that side's suite.
 *
 * Deliberate, documented differences (headings are JS-only; PHP
 * entity-encodes quotes) are kept out of the fixtures — see the fixture
 * directory README.
 */
class RenderParityTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array}>
     */
    public static function fixtureProvider(): iterable
    {
        $files = glob(__DIR__ . '/../fixtures/render-parity/*.json');
        self::assertNotEmpty($files, 'render-parity fixture directory must not be empty');

        foreach ($files as $file) {
            $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            yield basename($file, '.json') => [$fixture];
        }
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testSharedRenderContract(array $fixture): void
    {
        $html = MarkdownRenderer::render($fixture['input']);

        foreach ($fixture['mustContain'] ?? [] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $html,
                "PHP renderer output must contain '{$needle}'"
            );
        }
        foreach ($fixture['mustNotContain'] ?? [] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "PHP renderer output must not contain '{$needle}'"
            );
        }
    }
}
