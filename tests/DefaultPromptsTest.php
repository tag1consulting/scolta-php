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
            'expand_query template must contain an AUDIENCE QUALIFIERS rule',
        );
    }

    public function testExpandQueryTemplateGenericTermsListIncludesChildren(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            '"children"',
            $template,
            'expand_query generic-terms prohibition list must include "children"',
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
            'summarize template must contain a GROUNDING CHECK section',
        );
    }

    public function testFollowUpTemplateContainsGroundingCheck(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::FOLLOW_UP);

        $this->assertStringContainsString(
            'GROUNDING CHECK',
            $template,
            'follow_up template must contain a GROUNDING CHECK section',
        );
    }

    // -------------------------------------------------------------------------
    // PR fix/follow-up-numbered-result-references — ordinal reference resolution
    // -------------------------------------------------------------------------

    public function testFollowUpTemplateContainsNumberedResultReferencesSection(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::FOLLOW_UP);

        $this->assertStringContainsString(
            'NUMBERED RESULT REFERENCES',
            $template,
            'follow_up template must contain a NUMBERED RESULT REFERENCES section',
        );
    }

    public function testFollowUpTemplateInstructsExplicitNumberedReferenceResolution(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::FOLLOW_UP);

        $this->assertMatchesRegularExpression(
            '/#\d|number \d|item \d|result \d/i',
            $template,
            'follow_up template must give examples of explicit number references (#3, number 4, etc.)',
        );
    }

    public function testFollowUpTemplateInstructsOrdinalReferenceResolution(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::FOLLOW_UP);

        $this->assertMatchesRegularExpression(
            '/the (first|second|third|last) (one|result|article|option)/i',
            $template,
            'follow_up template must give examples of ordinal references (the third one, the last result, etc.)',
        );
    }

    public function testFollowUpTemplateMapsPosToNumberedLabel(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::FOLLOW_UP);

        $this->assertMatchesRegularExpression(
            '/first\s*=\s*\[1\]|second\s*=\s*\[2\]/i',
            $template,
            'follow_up template must map ordinal positions to numeric labels (first = [1], second = [2])',
        );
    }

    public function testSummarizeTemplatePartialRelevanceInstruction(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertMatchesRegularExpression(
            '/whatever IS relevant|partial.{0,30}relevant|extract.{0,50}relevant/i',
            $template,
            'summarize template must instruct extraction of partial relevance rather than binary yes/no',
        );
    }

    public function testSummarizeTemplateNoBinaryFallback(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringNotContainsString(
            "The search results don't directly address this topic. You may want to try different search terms",
            $template,
            'summarize template must not use the old binary fallback phrasing — use partial-relevance extraction instead',
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
            'summarize template must specify a minimum bullet count for detail extraction',
        );
    }

    public function testSummarizeTemplateExtractPerExcerpt(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertMatchesRegularExpression(
            '/each excerpt|per excerpt|every excerpt|from each result/i',
            $template,
            'summarize template must instruct per-excerpt detail extraction',
        );
    }

    // -------------------------------------------------------------------------
    // PR fix/expand-query-site-context-disambiguation — ambiguous multilingual queries
    // -------------------------------------------------------------------------

    public function testExpandQueryRuleNineReferencesSiteContext(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'site topic',
            $template,
            'expand_query rule 9 must instruct the model to use the site topic to disambiguate ambiguous queries',
        );
    }

    public function testExpandQueryRuleNineCoversMultilingualAmbiguity(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertMatchesRegularExpression(
            '/another language|common word.{0,50}language|language.{0,50}common word/i',
            $template,
            'expand_query rule 9 must mention that a query word may be a common word in another language',
        );
    }

    public function testExpandQueryRuleNineInstructsDomainInterpretation(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertMatchesRegularExpression(
            '/domain of this site|interpreted in the domain|in the domain/i',
            $template,
            'expand_query rule 9 must instruct interpretation within the site domain',
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
            'summarize template must contain a CATEGORY curation rule instructing the model to browse across a category rather than deep-dive on one result',
        );
    }

    public function testSummarizeTemplateContainsVarietyRule(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringContainsString(
            'VARIETY',
            $template,
            'summarize template must contain a VARIETY curation rule instructing the model to present multiple options rather than a single detailed result',
        );
    }

    // -------------------------------------------------------------------------
    // PR fix/summarize-corpus-awareness-no-stat — drop Wikipedia-specific count
    // -------------------------------------------------------------------------

    public function testSummarizeTemplateHasNoFabricatedCorpusStatistic(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringNotContainsString(
            '6,900',
            $template,
            'summarize template must not ship the Wikipedia-specific "6,900" count',
        );
        $this->assertStringNotContainsString(
            '6900',
            $template,
            'summarize template must not ship a hard-coded corpus count',
        );
        $this->assertStringNotContainsString(
            'Featured Articles',
            $template,
            'summarize template must not reference "Featured Articles" (Wikipedia-specific)',
        );
    }

    public function testSummarizeTemplateForbidsInventingStatistics(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        // The guard formerly lived under a "CORPUS AWARENESS" heading, which was
        // removed because the surrounding rule instructed the model to assert
        // absence (see the tests below). The no-invented-statistics guard from
        // #33 is preserved.
        $this->assertStringContainsString(
            'Do NOT invent statistics about the collection',
            $template,
            'summarize must explicitly forbid inventing corpus statistics',
        );
    }

    // -------------------------------------------------------------------------
    // Identifier / proper-noun queries — the summary must never generalize a
    // single search's slice of the corpus into a claim about the whole corpus.
    // -------------------------------------------------------------------------

    public function testSummarizeTemplateForbidsAssertingAbsence(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringContainsString(
            'NEVER ASSERT ABSENCE',
            $template,
            'summarize must carry the NEVER ASSERT ABSENCE rule',
        );
        $this->assertStringContainsString(
            'Do NOT state or imply that the collection lacks an article, has no dedicated coverage',
            $template,
            'the rule must forbid claiming the collection lacks coverage',
        );
    }

    public function testSummarizeTemplateBansTheObservedAbsencePhrasings(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        // These are the exact phrasings the old CORPUS AWARENESS rule taught,
        // and that produced the false "no dedicated article" overview.
        foreach ([
            'the collection doesn\'t have a dedicated article on [topic]',
            'there is no article about [topic]',
            '[topic] isn\'t covered here',
        ] as $banned) {
            $this->assertStringContainsString(
                $banned,
                $template,
                sprintf('summarize must name `%s` as a banned phrasing', $banned),
            );
        }
    }

    public function testSummarizeTemplateNoLongerInstructsAbsenceClaims(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        // Regression guard: the template must not reintroduce wording that tells
        // the model to report the collection as lacking a topic.
        $this->assertStringNotContainsString(
            'so it doesn\'t include a dedicated article on',
            $template,
            'summarize must not instruct the model to claim a missing article',
        );
        $this->assertStringNotContainsString(
            'may fall outside what this collection covers',
            $template,
            'summarize must not instruct the model to claim a topic is out of scope',
        );
    }

    public function testSummarizeTemplateFramesThinResultsAsASearchLimitation(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertStringContainsString(
            'PARTIAL VIEW',
            $template,
            'summarize must state that the excerpts are a slice, not the collection',
        );
        $this->assertStringContainsString(
            'WEAK RESULT SETS',
            $template,
            'summarize must carry the WEAK RESULT SETS rule',
        );
        $this->assertStringContainsString(
            'Attribute that to THIS SEARCH, never to the collection',
            $template,
            'a weak result set must be attributed to the search, not the corpus',
        );
    }

    public function testSummarizeTemplateUnderstandsTheWeakMatchContextSignal(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        // scolta.js prepends this marker to the context when the full query
        // matched nothing and results came from the broadened OR fallback.
        // Guarded on the JS side by tests/js/summary-weak-match-signal.test.js.
        $this->assertStringContainsString(
            '[No result matched the full query...]',
            $template,
            'summarize must recognize the weak-match context header emitted by scolta.js',
        );
    }

    public function testSummarizeAbsenceGroundingRulesMatchCanonicalSnapshot(): void
    {
        // Snapshot guard: pin the exact absence/grounding block so any future edit
        // fails loudly and surfaces as an explicit diff in review. The fixture is
        // seeded byte-for-byte from these bullets and is kept hand-identical to the
        // matching bullets in scolta-core's SUMMARIZE constant. The PHP source escapes
        // apostrophes as \' inside the single-quoted template literal; getTemplate()
        // returns the resolved runtime string where those are already plain ', so the
        // fixture (plain ') compares directly with no further un-escaping.
        // This does NOT mechanically prevent the two repos from drifting apart — the
        // cross-repo PromptTextIdentityTest covers that — but it makes any change here
        // deliberate and visible.
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);
        $canonical = file_get_contents(__DIR__ . '/fixtures/absence-grounding-rules.txt');

        $this->assertNotFalse($canonical, 'absence-grounding snapshot fixture must be readable');
        $this->assertStringContainsString(
            $canonical,
            $template,
            'summarize absence/grounding rules drifted from the pinned snapshot '
            . '(tests/fixtures/absence-grounding-rules.txt); review the diff and, if '
            . 'intentional, update the fixture and the matching scolta-core bullets',
        );
    }

    // -------------------------------------------------------------------------
    // Issue #168 — explicit output-length budget prevents mid-sentence truncation
    // -------------------------------------------------------------------------

    public function testSummarizeTemplateStatesOutputLengthBudget(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertMatchesRegularExpression(
            '/under ~?150 words/i',
            $template,
            'summarize template must state an explicit output-length budget (issue #168)',
        );
    }

    public function testSummarizeTemplateForbidsSubCategoryHeaders(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::SUMMARIZE);

        $this->assertMatchesRegularExpression(
            '/single flat bulleted list|no section headers|do not add section headers/i',
            $template,
            'summarize template must forbid ad-hoc sub-category headers and require a flat list (issue #168)',
        );
    }

    // -------------------------------------------------------------------------
    // Issue #36 — category-member and context decomposition rules
    // -------------------------------------------------------------------------

    public function testExpandQueryTemplateContainsCategoryMemberRule(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'CATEGORY → MEMBERS',
            $template,
            'expand_query must contain rule 13 (CATEGORY → MEMBERS)',
        );
        $this->assertStringContainsString(
            'Mercurial',
            $template,
            'rule 13 must lead with the non-food version-control example',
        );
    }

    public function testExpandQueryTemplateContainsContextDecompositionRule(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'CONTEXT / USE-CASE → CONCRETE ITEMS',
            $template,
            'expand_query must contain rule 14 (CONTEXT / USE-CASE → CONCRETE ITEMS)',
        );
        $this->assertStringContainsString(
            'standing desk',
            $template,
            'rule 14 must lead with the non-food home-office example',
        );
    }

    public function testExpandQueryTemplateForbidsFabricatingMembers(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'never invent members',
            $template,
            'rule 13 must forbid fabricating members for unknown categories',
        );
    }

    public function testExpandQueryForbidsFabricatingUnverifiedEntities(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'UNRECOGNIZED OR UNVERIFIABLE NAMED ENTITIES',
            $template,
            'expand_query must contain rule 15 (no-fabrication guard for unrecognized entities)',
        );
        $this->assertStringContainsString(
            'do NOT manufacture',
            $template,
            'rule 15 must forbid manufacturing detail for unrecognized entities',
        );
    }

    // -------------------------------------------------------------------------
    // Rule 16 — identifier / proper-noun queries. Anchor-preserving expansion
    // ("Apollo 13 crisis" → "Apollo 13 accident", "Apollo 13 explosion", ...)
    // misses prose that refers to a well-known entity without naming it, which
    // is how most authors actually write. The expansion must emit terms that
    // drop the entity name.
    // -------------------------------------------------------------------------

    public function testExpandQueryTemplateHasEntityDetailRule(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'NAMED ENTITY / EVENT → DEFINING DETAILS',
            $template,
            'expand_query must contain rule 16 (NAMED ENTITY / EVENT → DEFINING DETAILS)',
        );
        $this->assertStringContainsString(
            'participants, components, distinctive phrases, causes, and consequences',
            $template,
            'rule 16 must name the classes of defining detail to expand into',
        );
    }

    public function testExpandQueryTemplateRequiresAnchorFreeTerms(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'At least half your terms MUST drop the entity name entirely',
            $template,
            'rule 16 must require that some terms drop the entity name',
        );
        $this->assertStringContainsString(
            'never simply append the name to a list of near-synonyms',
            $template,
            'rule 16 must forbid appending the entity name to every term',
        );
    }

    public function testExpandQueryTemplateEntityRuleExamplesAreDomainNeutral(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        // Rule 16 must generalize beyond any one corpus: a consumer-product
        // example, a vehicle-spec example, and a historical-event example. None
        // of them is drawn from a Scolta demo corpus, so a demo passing this
        // behaviour cannot be explained by the prompt naming its content.
        $this->assertStringContainsString('battery drain', $template);
        $this->assertStringContainsString('swollen battery', $template);
        $this->assertStringContainsString('payload rating', $template);
        $this->assertStringContainsString('tow package', $template);
        $this->assertStringContainsString('airship fire', $template);
        $this->assertStringContainsString('Lakehurst landing', $template);
    }

    public function testExpandQueryTemplateEntityRuleDefersToNoFabricationGuard(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        // Rule 16 broadens expansion; rule 15 must still bound it so an
        // unrecognized entity does not acquire invented participants or parts.
        $this->assertStringContainsString(
            'Rule 15 still governs: only emit details you are confident are true of that entity',
            $template,
            'rule 16 must defer to rule 15\'s no-fabrication guard',
        );
    }

    public function testExpandQueryTemplateReconcilesTermCapForEntityDecomposition(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'up to 6 defining details',
            $template,
            'expand_query must reconcile the 2-4 term cap with rule 16',
        );
    }

    public function testExpandQueryTemplateReconcilesTermCapForDecomposition(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'up to 6 concrete members',
            $template,
            'expand_query must reconcile the 2-4 term cap with decomposition',
        );
    }

    public function testExpandQueryTemplateRuleSevenNarrowedToFilterLabels(): void
    {
        $template = DefaultPrompts::getTemplate(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString(
            'taxonomy term or filter label',
            $template,
            'rule 7 must be narrowed to taxonomy/filter-label matching so it no longer contradicts rule 13',
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
            "Template '{$name}' must contain a {SITE_NAME} placeholder for per-site customisation",
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
