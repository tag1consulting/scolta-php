<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Tests for DefaultPrompts.
 *
 * Template resolution for known names delegates to WASM (tested in
 * WasmIntegrationTest). This file tests the local fallback path for
 * custom prompt strings and the constant values.
 */
class DefaultPromptsTest extends TestCase
{
    public function testTemplateConstants(): void
    {
        $this->assertEquals('expand_query', DefaultPrompts::EXPAND_QUERY);
        $this->assertEquals('summarize', DefaultPrompts::SUMMARIZE);
        $this->assertEquals('follow_up', DefaultPrompts::FOLLOW_UP);
    }

    public function testResolveCustomStringReplacesPlaceholders(): void
    {
        $template = 'Welcome to {SITE_NAME}. We are a {SITE_DESCRIPTION}.';
        $result = DefaultPrompts::resolve($template, 'Acme Corp', 'technology company');

        $this->assertEquals('Welcome to Acme Corp. We are a technology company.', $result);
    }

    public function testResolveCustomStringDefaultDescription(): void
    {
        $template = '{SITE_NAME} ({SITE_DESCRIPTION})';
        $result = DefaultPrompts::resolve($template, 'My Site');

        $this->assertEquals('My Site (website)', $result);
    }

    public function testResolveCustomStringMultiplePlaceholders(): void
    {
        $template = '{SITE_NAME} and {SITE_NAME} again. {SITE_DESCRIPTION}!';
        $result = DefaultPrompts::resolve($template, 'X', 'Y');

        $this->assertEquals('X and X again. Y!', $result);
    }

    public function testResolveCustomStringNoPlaceholders(): void
    {
        $template = 'Just a plain string with no placeholders.';
        $result = DefaultPrompts::resolve($template, 'Ignored', 'Also ignored');

        $this->assertEquals('Just a plain string with no placeholders.', $result);
    }

    public function testResolveCustomStringSpecialChars(): void
    {
        $template = '{SITE_NAME}: {SITE_DESCRIPTION}';
        $result = DefaultPrompts::resolve($template, 'Tom & Jerry\'s <Site>', 'a "great" website');

        $this->assertEquals('Tom & Jerry\'s <Site>: a "great" website', $result);
    }

    public function testResolveEmptyValues(): void
    {
        $template = '[{SITE_NAME}] [{SITE_DESCRIPTION}]';
        $result = DefaultPrompts::resolve($template, '', '');

        $this->assertEquals('[] []', $result);
    }

    // -------------------------------------------------------------------------
    // PR fix/expand-query-prompt — audience qualifier and generic terms rules
    // -------------------------------------------------------------------------

    public function testExpandQueryTemplateContainsAudienceQualifierRule(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'AUDIENCE QUALIFIERS',
            $template,
            'expand_query template must contain an AUDIENCE QUALIFIERS rule'
        );
    }

    public function testExpandQueryTemplateGenericTermsListIncludesChildren(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            '"children"',
            $template,
            'expand_query generic-terms prohibition list must include "children"'
        );
    }

    // -------------------------------------------------------------------------
    // PR fix/summarize-grounding — hallucination guardrail
    // -------------------------------------------------------------------------

    public function testSummarizeTemplateContainsGroundingCheck(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringContainsString(
            'GROUNDING CHECK',
            $template,
            'summarize template must contain a GROUNDING CHECK section'
        );
    }

    public function testFollowUpTemplateContainsGroundingCheck(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::FOLLOW_UP);

        $this->assertStringContainsString(
            'GROUNDING CHECK',
            $template,
            'follow_up template must contain a GROUNDING CHECK section'
        );
    }

    public function testSummarizeTemplatePartialRelevanceInstruction(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertMatchesRegularExpression(
            '/whatever IS relevant|partial.{0,30}relevant|extract.{0,50}relevant/i',
            $template,
            'summarize template must instruct extraction of partial relevance rather than binary yes/no'
        );
    }

    public function testSummarizeTemplateNoBinaryFallback(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringNotContainsString(
            "The search results don't directly address this topic. You may want to try different search terms",
            $template,
            'summarize template must not use the old binary fallback phrasing — use partial-relevance extraction instead'
        );
    }

    // -------------------------------------------------------------------------
    // PR fix/summarize-detail-extraction — richer detail extraction
    // -------------------------------------------------------------------------

    public function testSummarizeTemplateSpecifiesMinimumBullets(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertMatchesRegularExpression(
            '/at least [3-9]|minimum [3-9]|[3-9]-[5-9] bullets?|[3-9]\+ bullets?/i',
            $template,
            'summarize template must specify a minimum bullet count for detail extraction'
        );
    }

    public function testSummarizeTemplateExtractPerExcerpt(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertMatchesRegularExpression(
            '/each excerpt|per excerpt|every excerpt|from each result/i',
            $template,
            'summarize template must instruct per-excerpt detail extraction'
        );
    }

    // -------------------------------------------------------------------------
    // PR fix/prompt-drift-cross-adapter-tests — CATEGORY and VARIETY guardrails
    // -------------------------------------------------------------------------

    public function testSummarizeTemplateContainsCategoryRule(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringContainsString(
            'CATEGORY',
            $template,
            'summarize template must contain a CATEGORY curation rule instructing the model to browse across a category rather than deep-dive on one result'
        );
    }

    public function testSummarizeTemplateContainsVarietyRule(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringContainsString(
            'VARIETY',
            $template,
            'summarize template must contain a VARIETY curation rule instructing the model to present multiple options rather than a single detailed result'
        );
    }

    /**
     * Both CMS adapter tests delegate to this class.  Verify the templates are
     * non-empty and contain placeholder markers so adapters can substitute
     * site-specific values at runtime.
     *
     * @dataProvider allTemplateNamesProvider
     */
    public function testEachTemplateHasSiteNamePlaceholder(string $name): void
    {
        $template = DefaultPrompts::getTemplate($name);

        $this->assertNotEmpty($template, "Template '{$name}' must not be empty");
        $this->assertStringContainsString(
            '{SITE_NAME}',
            $template,
            "Template '{$name}' must contain a {SITE_NAME} placeholder for per-site customisation"
        );
    }

    public static function allTemplateNamesProvider(): array
    {
        return [
            'expand_query' => [DefaultPrompts::EXPAND_QUERY],
            'summarize'    => [DefaultPrompts::SUMMARIZE],
            'follow_up'    => [DefaultPrompts::FOLLOW_UP],
        ];
    }
}
