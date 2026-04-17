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
        expect(jsSource).toContain('CONFIG.CONTENT_MATCH_BOOST');
        expect(jsSource).toContain('CONFIG.RESULTS_PER_PAGE');
        expect(jsSource).toContain('CONFIG.EXPAND_PRIMARY_WEIGHT');
    });

    test('scoring defaults match expected values', () => {
        expect(jsSource).toContain('RECENCY_BOOST_MAX: s.RECENCY_BOOST_MAX ?? 0.5');
        expect(jsSource).toContain('RECENCY_HALF_LIFE_DAYS: s.RECENCY_HALF_LIFE_DAYS ?? 365');
        expect(jsSource).toContain('TITLE_MATCH_BOOST: s.TITLE_MATCH_BOOST ?? 1.0');
        expect(jsSource).toContain('RESULTS_PER_PAGE: s.RESULTS_PER_PAGE ?? 10');
    });

    test('Jaccard dedup threshold is 0.7', () => {
        expect(jsSource).toContain('>= 0.7');
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
        // formatSummary converts markdown-style text (bold, bullets, links)
        // Code fence stripping is done server-side in PHP controllers
        expect(jsSource).toContain('formatSummary');
        expect(jsSource).toContain('formatInline');
        expect(jsSource).toContain('<strong>');
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

    test('renderFilters hides sidebar when one or fewer filter entries', () => {
        expect(jsSource).toMatch(/entries\.length\s*<=\s*1/);
        expect(jsSource).not.toMatch(/entries\.length\s*===\s*0/);
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

    test('priority page boosting is supported when configured', () => {
        expect(jsSource).toContain('match_priority_pages');
        expect(jsSource).toContain('priority_pages');
        expect(jsSource).toContain('Priority page matching failed');
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
});
