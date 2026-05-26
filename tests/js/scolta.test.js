/**
 * Tests for scolta.js — public API surface and structural validation.
 *
 * scolta.js is a browser IIFE that uses dynamic import() for Pagefind.
 * JSDOM doesn't support dynamic import, so we test what we can:
 * - The global API surface (Scolta namespace, methods)
 * - Config parsing doesn't throw
 * - DOM creation when Pagefind init is mocked
 *
 * Full integration testing of search/scoring happens in browser tests.
 */

const fs = require('fs');
const path = require('path');

describe('scolta.js structure', () => {
    const jsPath = path.resolve(__dirname, '../../assets/js/scolta.js');
    let jsSource;

    beforeAll(() => {
        jsSource = fs.readFileSync(jsPath, 'utf-8');
    });

    test('scolta.js file exists', () => {
        expect(fs.existsSync(jsPath)).toBe(true);
    });

    test('source is wrapped in IIFE', () => {
        expect(jsSource).toContain('(function (global)');
        expect(jsSource).toMatch(/\}\)\(typeof window/);
    });

    test('exposes Scolta.init function', () => {
        expect(jsSource).toContain('global.Scolta.init');
    });

    test('exposes Scolta.createInstance function', () => {
        expect(jsSource).toContain('global.Scolta.createInstance');
    });

    test('contains autoInit function', () => {
        expect(jsSource).toContain('function autoInit()');
    });

    test('contains STOPWORDS set', () => {
        expect(jsSource).toContain('const STOPWORDS = new Set');
    });

    test('STOPWORDS includes common words', () => {
        // Verify key stopwords are present
        expect(jsSource).toContain("'the'");
        expect(jsSource).toContain("'is'");
        expect(jsSource).toContain("'who'");
        expect(jsSource).toContain("'what'");
    });

    test('contains extractSearchTerms function', () => {
        expect(jsSource).toContain('function extractSearchTerms(');
    });

    test('contains recencyScore function', () => {
        expect(jsSource).toContain('function recencyScoreFallback(');
    });

    test('contains titleMatchScore function', () => {
        expect(jsSource).toContain('function titleMatchScoreFallback(');
    });

    test('contains contentMatchScore function', () => {
        expect(jsSource).toContain('function contentMatchScoreFallback(');
    });

    test('contains deduplicateByTitle function', () => {
        expect(jsSource).toContain('function deduplicateByTitle(');
    });

    test('contains mergeResults function', () => {
        expect(jsSource).toContain('function mergeResults(');
    });

    test('contains escapeHtml function', () => {
        expect(jsSource).toContain('function escapeHtml(');
    });

    test('contains formatSummary function', () => {
        expect(jsSource).toContain('function formatSummary(');
    });

    test('contains cleanBrokenMarkdown function', () => {
        expect(jsSource).toContain('function cleanBrokenMarkdown(');
    });

    test('cleanBrokenMarkdown handles unclosed link with URL', () => {
        expect(jsSource).toContain("text.replace(/\\[([^\\]]+)\\]\\([^)]*$/g, '**$1**')");
    });

    test('cleanBrokenMarkdown handles unclosed bracket', () => {
        expect(jsSource).toContain("text.replace(/\\[([^\\]]+)$/g, '**$1**')");
    });

    test('cleanBrokenMarkdown called at top of formatSummary', () => {
        // cleanBrokenMarkdown must run before escapeHtml — find its call inside formatSummary
        const fmtIdx = jsSource.indexOf('function formatSummary(');
        const cleanIdx = jsSource.indexOf('cleanBrokenMarkdown(text)', fmtIdx);
        const escapeIdx = jsSource.indexOf('escapeHtml(text)', fmtIdx);
        expect(cleanIdx).toBeGreaterThan(fmtIdx);
        expect(cleanIdx).toBeLessThan(escapeIdx);
    });

    test('uses instance config instead of global', () => {
        // Verify the factory pattern uses instance-scoped config
        expect(jsSource).toContain('function getInstanceConfig()');
        expect(jsSource).toContain('function getInstanceEndpoints()');
    });

    test('init builds search UI with expected elements', () => {
        // Verify the HTML template contains key UI elements
        expect(jsSource).toContain('id="scolta-query"');
        expect(jsSource).toContain('id="scolta-search-btn"');
        expect(jsSource).toContain('id="scolta-search-clear"');
        expect(jsSource).toContain('id="scolta-results"');
        expect(jsSource).toContain('id="scolta-no-results"');
        expect(jsSource).toContain('id="scolta-ai-summary"');
        expect(jsSource).toContain('id="scolta-layout"');
        expect(jsSource).toContain('id="scolta-expanded-terms"');
    });

    test('factory instance has destroy method', () => {
        expect(jsSource).toContain('destroy:');
    });

    test('scoring uses configurable parameters', () => {
        expect(jsSource).toContain('CONFIG.RECENCY_BOOST_MAX');
        expect(jsSource).toContain('CONFIG.RECENCY_HALF_LIFE_DAYS');
        expect(jsSource).toContain('CONFIG.TITLE_MATCH_BOOST');
        expect(jsSource).toContain('CONFIG.EXACT_TITLE_MATCH_BOOST');
        expect(jsSource).toContain('CONFIG.CONTENT_MATCH_BOOST');
        expect(jsSource).toContain('CONFIG.RESULTS_PER_PAGE');
        expect(jsSource).toContain('CONFIG.EXPAND_PRIMARY_WEIGHT');
    });

    test('scoring defaults match expected values', () => {
        expect(jsSource).toContain('RECENCY_BOOST_MAX: s.RECENCY_BOOST_MAX ?? 0.5');
        expect(jsSource).toContain('RECENCY_HALF_LIFE_DAYS: s.RECENCY_HALF_LIFE_DAYS ?? 365');
        expect(jsSource).toContain('TITLE_MATCH_BOOST: s.TITLE_MATCH_BOOST ?? 1.0');
        expect(jsSource).toContain('EXACT_TITLE_MATCH_BOOST: s.EXACT_TITLE_MATCH_BOOST ?? 5.0');
        expect(jsSource).toContain('RESULTS_PER_PAGE: s.RESULTS_PER_PAGE ?? 10');
    });

    test('Jaccard dedup threshold is 0.6', () => {
        expect(jsSource).toContain('>= 0.6');
    });

    test('expand weight decay has minimum of 0.4', () => {
        expect(jsSource).toContain('0.4');
        expect(jsSource).toContain('EXPAND_PRIMARY_WEIGHT');
    });

    test('uses AbortController for cancellation', () => {
        expect(jsSource).toContain('AbortController');
        expect(jsSource).toContain('abortController');
    });

    test('markdown formatting handled in summary rendering', () => {
        expect(jsSource).toContain('formatSummary');
        expect(jsSource).toContain('formatInline');
        expect(jsSource).toContain('<strong>');
    });

    test('formatInline handles bold markdown', () => {
        expect(jsSource).toContain(".replace(/\\*\\*(.+?)\\*\\*/g, '<strong>$1</strong>')");
    });

    test('formatInline handles italic markdown', () => {
        expect(jsSource).toContain(".replace(/\\*(.+?)\\*/g, '<em>$1</em>')");
    });

    test('formatInline handles bold+italic markdown', () => {
        expect(jsSource).toContain(".replace(/\\*\\*\\*(.+?)\\*\\*\\*/g, '<strong><em>$1</em></strong>')");
    });

    test('formatInline processes bold+italic before bold before italic', () => {
        const boldItalicIdx = jsSource.indexOf("\\*\\*\\*(.+?)\\*\\*\\*");
        const boldIdx = jsSource.indexOf("\\*\\*(.+?)\\*\\*", boldItalicIdx + 20);
        const italicIdx = jsSource.indexOf("\\*(.+?)\\*", boldIdx + 15);
        expect(boldItalicIdx).toBeGreaterThan(-1);
        expect(boldIdx).toBeGreaterThan(boldItalicIdx);
        expect(italicIdx).toBeGreaterThan(boldIdx);
    });

    test('event delegation used instead of inline handlers', () => {
        expect(jsSource).toContain('data-scolta-search-term');
        expect(jsSource).toContain('data-scolta-followup-submit');
        expect(jsSource).toContain('data-scolta-filter');
        // No onclick= or onchange= in the template HTML
        expect(jsSource).not.toMatch(/onclick=/);
        expect(jsSource).not.toMatch(/onchange=/);
    });

    test('OR fallback activates only when AND returns zero', () => {
        expect(jsSource).toContain('primarySearch.results.length === 0');
        expect(jsSource).toContain('usedOrFallback');
    });

    test('search version tracks stale responses', () => {
        expect(jsSource).toContain('searchVersion');
        expect(jsSource).toContain('followUpVersion !== searchVersion');
    });

    test('search updates URL with query parameter', () => {
        expect(jsSource).toContain("searchParams.set('q'");
        expect(jsSource).toContain("searchParams.delete('q'");
        expect(jsSource).toContain(".get('q')");
        expect(jsSource).toContain('replaceState');
    });

    test('renderFilters hides sidebar when no dimension has multiple values', () => {
        expect(jsSource).toMatch(/dims\.length\s*===\s*0/);
    });

    test('merge_results uses N-set format with sets array', () => {
        expect(jsSource).toContain('sets:');
        expect(jsSource).toMatch(/sets:\s*\[/);
        expect(jsSource).not.toMatch(/original:\s*original/);
        expect(jsSource).not.toMatch(/expanded:\s*expanded/);
    });

    test('AI context extraction uses batch_extract_context when available', () => {
        expect(jsSource).toContain('batch_extract_context');
        expect(jsSource).toContain('WASM context extraction failed');
    });

    test('sanitizeQueryForLogging utility exists', () => {
        expect(jsSource).toContain('sanitizeQueryForLogging');
        expect(jsSource).toContain('sanitize_query');
    });

    test('exact title match boost applied in scoreResults', () => {
        expect(jsSource).toContain('EXACT_TITLE_MATCH_BOOST');
        // Verify the boost is multiplicative (score *= ...) not additive
        expect(jsSource).toContain('r.score *= CONFIG.EXACT_TITLE_MATCH_BOOST');
        // Verify case-insensitive comparison
        expect(jsSource).toContain(".toLowerCase().trim()");
    });

    test('exact title match uses primaryQuery when available', () => {
        // When scoring expansion terms, the boost should fire against the
        // original user query (primaryQuery), not the expansion term.
        const scoreBody = jsSource.match(/function scoreResults[\s\S]*?return scored;\s*\}/);
        expect(scoreBody).not.toBeNull();
        expect(scoreBody[0]).toContain('primaryQuery || query');
    });

    test('priority page boosting is supported when configured', () => {
        expect(jsSource).toContain('match_priority_pages');
        expect(jsSource).toContain('priority_pages');
        expect(jsSource).toContain('Priority page matching failed');
    });

    test('sort override state variable is declared', () => {
        expect(jsSource).toContain('currentSortOverride');
    });

    test('pagefindSearch passes sort option from sortHint', () => {
        expect(jsSource).toContain('searchOpts.sort = { [sortHint.field]: sortHint.direction }');
    });

    test('renderSortIndicator function exists', () => {
        expect(jsSource).toContain('function renderSortIndicator(');
    });

    test('dismissSortOverride function exists', () => {
        expect(jsSource).toContain('function dismissSortOverride(');
    });

    test('sort indicator element in HTML template', () => {
        expect(jsSource).toContain('id="scolta-sort-indicator"');
    });

    test('sort dismiss uses event delegation attribute', () => {
        expect(jsSource).toContain('data-scolta-sort-dismiss');
    });

    test('sort indicator is hidden on clearSearch', () => {
        const clearBody = jsSource.match(/function clearSearch[\s\S]*?queryInput\.focus/);
        expect(clearBody).not.toBeNull();
        expect(clearBody[0]).toContain('sortIndicator');
        expect(clearBody[0]).toContain('currentSortOverride = null');
    });

    test('expandQuery parses sort_hint from API response', () => {
        expect(jsSource).toContain('data.sort_hint');
        expect(jsSource).toContain("sort_hint: null");
    });

    test('sort_hint field absent from all results falls back silently', () => {
        expect(jsSource).toContain('absent from all results, falling back to relevance');
    });

    test('expandQuery returns subject_terms from API response', () => {
        expect(jsSource).toContain('data?.subject_terms');
        expect(jsSource).toContain('subject_terms: null');
    });

    test('mergeExpandedSearchResults accepts subjectTerms parameter', () => {
        expect(jsSource).toMatch(/mergeExpandedSearchResults\([^)]*subjectTerms/);
    });

    test('filter+sort discovery caches Pagefind filters on init', () => {
        expect(jsSource).toContain('cachedPagefindFilters');
        expect(jsSource).toContain('pagefind.filters()');
    });

    test('matchSubjectToFilters function exists and skips non-subject dimensions', () => {
        expect(jsSource).toContain('matchSubjectToFilters');
        expect(jsSource).toContain('SKIP_FILTER_DIMENSIONS');
    });

    test('sort path matches subject terms against cached filters', () => {
        expect(jsSource).toContain('matchSubjectToFilters(subjectTerms, cachedPagefindFilters)');
        expect(jsSource).toContain('Subject filter match:');
    });

    test('sort path merges subject filters with active filters', () => {
        expect(jsSource).toContain('mergedFilters');
        expect(jsSource).toContain('new Set([val])');
    });

    test('subject filter match updates activeFilters and llmAppliedFilters', () => {
        const sortBlock = jsSource.substring(
            jsSource.indexOf('if (hasFilterMatch)'),
            jsSource.indexOf('const termSet = new Set')
        );
        expect(sortBlock).toContain('activeFilters[dim]');
        expect(sortBlock).toContain('llmAppliedFilters[dim]');
    });

    test('sort path logs when no filter match found', () => {
        expect(jsSource).toContain('No filter match for subject terms, using sort only');
    });

    test('doSearch extracts subject_terms from expansion response', () => {
        expect(jsSource).toContain('expansion?.subject_terms');
    });
});

describe('scolta.css structure', () => {
    const cssPath = path.resolve(__dirname, '../../assets/css/scolta.css');

    test('CSS file exists', () => {
        expect(fs.existsSync(cssPath)).toBe(true);
    });

    test('uses CSS custom properties for theming', () => {
        const css = fs.readFileSync(cssPath, 'utf-8');
        expect(css).toContain('--scolta-primary');
        expect(css).toContain('--scolta-bg');
        expect(css).toContain('--scolta-text');
    });

    test('main CSS classes are prefixed with scolta-', () => {
        const css = fs.readFileSync(cssPath, 'utf-8');
        // Verify key scolta classes exist
        expect(css).toContain('.scolta-search-box');
        expect(css).toContain('.scolta-result-card');
        expect(css).toContain('.scolta-ai-summary');
        expect(css).toContain('.scolta-filters');
        expect(css).toContain('.scolta-no-results');
        expect(css).toContain('.scolta-load-more');
        expect(css).toContain('.scolta-expanded-term');
    });

    test('layout defaults to single column without has-filters', () => {
        const css = fs.readFileSync(cssPath, 'utf-8');
        expect(css).toContain('.scolta-layout');
        expect(css).toContain('.scolta-layout.has-filters');
        expect(css).toMatch(/\.scolta-layout\.has-filters\s*\{[^}]*grid-template-columns:\s*220px/);
        expect(css).toMatch(/\.scolta-layout\s*\{[^}]*grid-template-columns:\s*1fr/);
    });

    test('results header allows text to wrap on narrow viewports', () => {
        const css = fs.readFileSync(cssPath, 'utf-8');
        // .scolta-results-header must have overflow-wrap and word-wrap so long
        // status messages do not overflow the container on narrow viewports.
        expect(css).toMatch(/\.scolta-results-header\s*\{[^}]*overflow-wrap:\s*break-word/s);
        expect(css).toMatch(/\.scolta-results-header\s*\{[^}]*word-wrap:\s*break-word/s);
        // The first child span must have min-width:0 so the flex item can shrink.
        expect(css).toMatch(/\.scolta-results-header\s+span:first-child\s*\{[^}]*min-width:\s*0/s);
        expect(css).toMatch(/\.scolta-results-header\s+span:first-child\s*\{[^}]*overflow-wrap:\s*break-word/s);
    });
});
