/**
 * Tests for multi-dimensional filter faceting in scolta.js.
 *
 * Source-structure tests verify new constants and functions exist.
 * Logic tests exercise computeFilterCounts and filterDisplayValue in isolation.
 * Behavioral tests run scolta.js in JSDOM to verify pagefind.search call shape
 * and URL encoding.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const jsPath = path.resolve(__dirname, '../../assets/js/scolta.js');
const scoltaSource = fs.readFileSync(jsPath, 'utf-8');

// ===========================================================================
// Source-structure tests
// ===========================================================================

describe('faceting: source structure', () => {
    test('LANGUAGE_NAMES map is defined', () => {
        expect(scoltaSource).toContain('const LANGUAGE_NAMES =');
        expect(scoltaSource).toContain("en: 'English'");
        expect(scoltaSource).toContain("es: 'Spanish'");
        expect(scoltaSource).toContain("fr: 'French'");
    });

    test('FILTER_LABELS map is defined', () => {
        expect(scoltaSource).toContain('const FILTER_LABELS =');
        expect(scoltaSource).toContain("language: 'Language'");
        expect(scoltaSource).toContain("site: 'Site'");
    });

    test('filterDisplayValue function is defined', () => {
        expect(scoltaSource).toContain('function filterDisplayValue(dimension, value)');
        expect(scoltaSource).toContain("if (dimension === 'language') return LANGUAGE_NAMES[value] || value;");
    });

    test('currentLanguage config option is documented in module comment', () => {
        expect(scoltaSource).toContain('currentLanguage');
    });

    test('defaultLangCode is derived from instanceConfig.currentLanguage', () => {
        expect(scoltaSource).toContain('instanceConfig.currentLanguage');
        expect(scoltaSource).toContain('defaultLangCode');
    });

    test('doSearch applies defaultLangCode when no language in initialFilters', () => {
        expect(scoltaSource).toContain('effectiveFilters.language && defaultLangCode');
    });

    test('doSearch guards auto-filter against AI_LANGUAGES list', () => {
        expect(scoltaSource).toContain('CONFIG.AI_LANGUAGES');
        expect(scoltaSource).toContain('langs.length > 1 && langs.includes(defaultLangCode)');
        // getInstanceConfig must expose AI_LANGUAGES so doSearch can read it
        expect(scoltaSource).toContain("AI_LANGUAGES: s.AI_LANGUAGES ?? ['en']");
    });

    test('doSearch guards auto-filter against AUTO_LANGUAGE_FILTER flag', () => {
        // Auto-filter is opt-in; it only activates when CONFIG.AUTO_LANGUAGE_FILTER is truthy.
        expect(scoltaSource).toContain('CONFIG.AUTO_LANGUAGE_FILTER');
    });

    test('doSearch skips auto-filter when AI_LANGUAGES has only one entry', () => {
        // The guard condition `langs.length > 1` ensures monolingual sites
        // (ai_languages: [en]) never pre-filter — renderFilters wouldn't show
        // the facet anyway since there is only one language value.
        expect(scoltaSource).toContain('langs.length > 1');
    });

    test('doSearch skips auto-filter when detected language is not in AI_LANGUAGES', () => {
        // langs.includes(defaultLangCode) prevents filtering by a language
        // that has no content in the configured index.
        expect(scoltaSource).toContain('langs.includes(defaultLangCode)');
    });

    test('activeFilters initialized as plain object not Set', () => {
        expect(scoltaSource).toContain('let activeFilters = {};');
        expect(scoltaSource).not.toMatch(/let activeFilters = new Set\(\)/);
    });

    test('computeQueryFacetCounts derives counts from the typed-query search and follows the OR-fallback mode', () => {
        expect(scoltaSource).toContain('async function computeQueryFacetCounts(query, baseFilters, meaningfulTerms, isForcedPhrase)');
        expect(scoltaSource).toContain('const search = await pagefindSearch(query, structuralFilters);');
        // Mode 1: AND search matched → native .filters. Mode 2: AND empty + fallback
        // engages → union tally. The union helper exists and dedups by fragment id.
        expect(scoltaSource).toContain('if (search && search.results && search.results.length > 0) {');
        expect(scoltaSource).toContain('return await computeUnionFacetCounts(terms, structuralFilters);');
        expect(scoltaSource).toContain('async function computeUnionFacetCounts(terms, structuralFilters)');
        expect(scoltaSource).toContain('if (seenIds.has(r.id)) continue;');
    });

    test('computeQueryFacetCounts keeps only structural filter dimensions', () => {
        // User-facing facet selections are dropped so counts never move on click.
        expect(scoltaSource).toContain('if (SKIP_FILTER_DIMENSIONS.has(dim.toLowerCase())) {');
        expect(scoltaSource).toContain('structuralFilters[dim] = vals;');
    });

    test('result-derived facet helpers are removed', () => {
        expect(scoltaSource).not.toContain('function computeFilterCounts(');
        expect(scoltaSource).not.toContain('computeDropSelfFacets');
    });

    test('toggleFilter accepts dimension and value parameters', () => {
        expect(scoltaSource).toContain('async function toggleFilter(dimension, value)');
        expect(scoltaSource).toContain('activeFilters[dimension]');
    });

    test('renderFilters uses data-scolta-filter-dim and data-scolta-filter-val', () => {
        expect(scoltaSource).toContain('data-scolta-filter-dim=');
        expect(scoltaSource).toContain('data-scolta-filter-val=');
    });

    test('renderFilters renders .scolta-filter-group per dimension', () => {
        expect(scoltaSource).toContain('scolta-filter-group');
    });

    test('renderFilters orders dimensions alphabetically by display label', () => {
        expect(scoltaSource).toContain('dims.sort((a, b) => filterDimLabel(a).localeCompare(filterDimLabel(b)));');
    });

    test('renderFilters sorts values alphabetically by display value', () => {
        expect(scoltaSource).toContain('(a, b) => filterDisplayValue(dim, a).localeCompare(filterDisplayValue(dim, b))');
    });

    test('renderFilters calls filterDisplayValue for display names', () => {
        expect(scoltaSource).toContain('filterDisplayValue(dim, val)');
    });

    test('renderFilters hides when no dimension has multiple values', () => {
        expect(scoltaSource).toMatch(/dims\.length\s*===\s*0/);
    });

    test('URL encoding uses f_ prefix for filter params', () => {
        expect(scoltaSource).toContain("url.searchParams.set('f_' + dim");
        expect(scoltaSource).toContain("key.startsWith('f_')");
    });

    test('URL decoding reads f_ params on init and popstate', () => {
        expect(scoltaSource).toContain("key.startsWith('f_') && val");
        expect(scoltaSource).toContain("key.slice(2)");
    });

    test('doSearch accepts initialFilters parameter', () => {
        expect(scoltaSource).toContain('async function doSearch(preserveFilters, initialFilters)');
        // activeFilters is now set via effectiveFilters to allow auto-language injection
        expect(scoltaSource).toContain('activeFilters = effectiveFilters;');
    });

    test('doSearch computes query-fixed counts only on a fresh typed query', () => {
        // Gated on !preserveFilters so a facet toggle/sort/load-more never recomputes.
        expect(scoltaSource).toContain('queryFacetCounts = await computeQueryFacetCounts(searchQuery, activeFilters, meaningfulTerms, isForcedPhrase);');
    });

    test('renderFilters reads dimensions from the index taxonomy', () => {
        expect(scoltaSource).toContain('const taxonomy = cachedPagefindFilters || {};');
        expect(scoltaSource).toContain('Object.keys(taxonomy[dim]).length > 1');
    });

    test('renderFilters skips infrastructure dimensions', () => {
        expect(scoltaSource).toContain('!SKIP_FILTER_DIMENSIONS.has(dim.toLowerCase())');
    });

    test('renderFilters disables zero-count values unless active', () => {
        expect(scoltaSource).toContain('const disabled = (count === 0 && !isActive) ? " disabled" : "";');
    });

    test('initPagefind merges all non-primary language instances via absolute URL', () => {
        // pagefind.mergeIndex skips calls where indexPath is a string-prefix of
        // the primary basePath. Passing an absolute URL bypasses the check.
        expect(scoltaSource).toContain('await pagefind.mergeIndex(absoluteBase, { language: lang });');
        expect(scoltaSource).toContain('const absoluteBase = new URL(basePath, window.location.href).href;');
        expect(scoltaSource).toContain('if (lang !== primaryLang)');
    });

    test('clearSearch resets activeFilters to empty object', () => {
        expect(scoltaSource).toContain('activeFilters = {};');
    });

    test('event delegation uses data-scolta-filter-dim not data-scolta-filter', () => {
        expect(scoltaSource).toContain('e.target.closest("[data-scolta-filter-dim]")');
        expect(scoltaSource).toContain('filterEl.dataset.scoltaFilterDim');
        expect(scoltaSource).toContain('filterEl.dataset.scoltaFilterVal');
    });
});

// ===========================================================================
// Pure-logic tests (isolated from browser environment)
// ===========================================================================

// Extract and re-implement the pure logic functions for direct unit testing.
// These mirror the implementations in scolta.js so changes there must be
// reflected here.

const LANGUAGE_NAMES_TEST = {
    en: 'English', es: 'Spanish', fr: 'French', de: 'German',
    it: 'Italian', pt: 'Portuguese', nl: 'Dutch', ru: 'Russian',
    zh: 'Chinese', ja: 'Japanese', ko: 'Korean', ar: 'Arabic',
};

function filterDisplayValueTest(dimension, value) {
    if (dimension === 'language') return LANGUAGE_NAMES_TEST[value] || value;
    return value;
}

describe('faceting: filterDisplayValue logic', () => {
    test('maps known language code to full name', () => {
        expect(filterDisplayValueTest('language', 'en')).toBe('English');
        expect(filterDisplayValueTest('language', 'es')).toBe('Spanish');
        expect(filterDisplayValueTest('language', 'fr')).toBe('French');
        expect(filterDisplayValueTest('language', 'de')).toBe('German');
    });

    test('passes through unknown language code unchanged', () => {
        expect(filterDisplayValueTest('language', 'xx')).toBe('xx');
        expect(filterDisplayValueTest('language', 'tlh')).toBe('tlh');
    });

    test('passes through site values unchanged', () => {
        expect(filterDisplayValueTest('site', 'My Site')).toBe('My Site');
        expect(filterDisplayValueTest('site', 'drupal.org')).toBe('drupal.org');
    });

    test('passes through content_type values unchanged', () => {
        expect(filterDisplayValueTest('content_type', 'article')).toBe('article');
    });

    test('passes through unknown dimension values unchanged', () => {
        expect(filterDisplayValueTest('topic', 'technology')).toBe('technology');
    });
});

// ===========================================================================
// Behavioral tests (JSDOM)
// ===========================================================================

const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = mockPagefind'
);

function buildMockPagefind(resultsList, searchFilters, taxonomy) {
    return {
        init: () => Promise.resolve(),
        // The taxonomy (pagefind.filters()) drives which dimensions/values the
        // panel renders. Tests that only assert search-call shape can omit it.
        filters: () => Promise.resolve(taxonomy || {}),
        search: jest.fn(() => Promise.resolve({ results: resultsList, filters: searchFilters || {} })),
    };
}

// Let all pending async work settle: Pagefind init kicks off a URL-driven
// auto-search (when the URL has ?q=) and AI expansion runs on a microtask, so a
// single await is not enough to reach a stable final render.
async function settle(window) {
    for (let i = 0; i < 5; i++) {
        await new Promise(r => window.setTimeout(r, 0));
    }
}

// Capture the rendered facet panel as ordered (dimension, value, count) tuples.
// This is the canonical "did the panel move?" snapshot used by the no-jump test.
function captureFacetTuples(window) {
    return [...window.document.querySelectorAll('#scolta-filters .scolta-filter-item')].map(item => {
        const input = item.querySelector('input[data-scolta-filter-dim]');
        const countEl = item.querySelector('.scolta-filter-count');
        return {
            dim: input.getAttribute('data-scolta-filter-dim'),
            val: input.getAttribute('data-scolta-filter-val'),
            count: countEl ? countEl.textContent.trim() : null,
        };
    });
}

function makeResult(filterObj, overrides) {
    const url = (overrides && overrides.url) || '/test';
    return {
        // Pagefind result objects carry an `id` (the fragment hash); the OR-fallback
        // count path unions per-term results by it so a doc matching several terms
        // is counted once. Defaults to the url so single-result mocks stay distinct.
        id: (overrides && overrides.id) || url,
        data: () => Promise.resolve({
            meta: {
                title: (overrides && overrides.title) || 'Test Page',
                url,
                site: filterObj.site || 'Site A',
            },
            filters: filterObj,
            excerpt: 'Test excerpt',
            content: 'Test content',
            locations: [],
        }),
    };
}

function createWindow(mockPagefind) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;
    window.fetch = jest.fn().mockResolvedValue({
        ok: false, status: 503,
        json: () => Promise.resolve({}),
        text: () => Promise.resolve(''),
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    window.scrollTo = () => {};
    window.mockPagefind = mockPagefind;

    window.eval(patchedSource);
    window.scolta = {
        scoring: {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    };
    window.Scolta.init('#scolta-search');
    return { dom, window };
}

describe('faceting: pagefindSearch filter shape', () => {
    test('passes multi-dimensional filters to pagefind.search', async () => {
        const mock = buildMockPagefind([]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'filteredquery';

        await inst.toggleFilter('language', 'en');

        // Find the call for our query (not the warm-up "" call from initPagefind).
        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'filteredquery');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).toBeDefined();
        expect(searchCall[1].filters).toEqual({ language: 'en' });
    });

    test('passes no filters to pagefind.search when none active', async () => {
        const mock = buildMockPagefind([]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'unfilteredquery';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'unfilteredquery');
        expect(searchCall).toBeDefined();
        // With no active filters, searchOpts.filters should not be set.
        expect(searchCall[1]).not.toHaveProperty('filters');
    });
});

describe('faceting: URL encoding round-trip', () => {
    test('encodes active filter dimension in URL after search', async () => {
        const mock = buildMockPagefind([
            makeResult({ language: 'en', site: 'Site A' }),
            makeResult({ language: 'es', site: 'Site B' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        // After initial search, no filter params yet.
        const url1 = new window.URL(window.location.href);
        expect(url1.searchParams.get('q')).toBe('test');
        expect(url1.searchParams.get('f_language')).toBeNull();

        // Toggle a filter — URL should get the f_language param.
        await inst.toggleFilter('language', 'en');
        const url2 = new window.URL(window.location.href);
        expect(url2.searchParams.get('f_language')).toBe('en');
    });

    test('removes filter params from URL on clearSearch', async () => {
        const mock = buildMockPagefind([
            makeResult({ language: 'en', site: 'Site A' }),
            makeResult({ language: 'es', site: 'Site B' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();
        await inst.toggleFilter('language', 'en');

        // Verify filter param is set
        expect(new window.URL(window.location.href).searchParams.get('f_language')).toBe('en');

        // Clear search — filter params should be gone.
        inst.clearSearch();
        const urlAfterClear = new window.URL(window.location.href);
        expect(urlAfterClear.searchParams.get('q')).toBeNull();
        expect(urlAfterClear.searchParams.get('f_language')).toBeNull();
    });
});

describe('faceting: index-driven static panel', () => {
    // The taxonomy (pagefind.filters()) is the single source of truth for which
    // dimensions and values exist. Counts come from a per-query search's .filters.
    const TAXONOMY = {
        language: { en: 50, es: 30 },                          // structural — never a user facet
        region: { Asia: 12, Europe: 8, 'North America': 4 },
        era: { Ancient: 6, Modern: 9 },
        format: { article: 7 },                                // single value — never shown
    };

    // Per-query counts Pagefind returns for the typed query. 'North America' is
    // absent (→ count 0) and era.Ancient is present but zero.
    const QUERY_FILTERS = {
        region: { Asia: 40, Europe: 25 },
        era: { Ancient: 0, Modern: 18 },
    };

    test('shows every non-structural taxonomy dimension with >1 value, alphabetical', async () => {
        const mock = buildMockPagefind([makeResult({})], QUERY_FILTERS, TAXONOMY);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'history';
        await inst.doSearch();
        await settle(window);

        const groups = [...window.document.querySelectorAll('#scolta-filters .scolta-filter-group h3')]
            .map(h => h.textContent);
        // Era + Region shown (alphabetical). Language is structural; Format is single-value.
        expect(groups).toEqual(['Era', 'Region']);
    });

    test('shows all taxonomy values per dimension, alphabetical, with query counts', async () => {
        const mock = buildMockPagefind([makeResult({})], QUERY_FILTERS, TAXONOMY);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'history';
        await inst.doSearch();
        await settle(window);

        expect(captureFacetTuples(window)).toEqual([
            { dim: 'era', val: 'Ancient', count: '(0)' },
            { dim: 'era', val: 'Modern', count: '(18)' },
            { dim: 'region', val: 'Asia', count: '(40)' },
            { dim: 'region', val: 'Europe', count: '(25)' },
            { dim: 'region', val: 'North America', count: '(0)' },
        ]);
    });

    test('zero-count values are disabled, non-zero values enabled', async () => {
        const mock = buildMockPagefind([makeResult({})], QUERY_FILTERS, TAXONOMY);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'history';
        await inst.doSearch();
        await settle(window);

        const q = (dim, val) => window.document.querySelector(
            `input[data-scolta-filter-dim="${dim}"][data-scolta-filter-val="${val}"]`);
        expect(q('region', 'North America').disabled).toBe(true);
        expect(q('era', 'Ancient').disabled).toBe(true);
        expect(q('region', 'Asia').disabled).toBe(false);
        expect(q('era', 'Modern').disabled).toBe(false);
    });

    // The core regression: clicking a facet must NOT move any count. This would
    // have failed under the old result-derived / drop-self model, which re-queried
    // and reshuffled every number on each toggle.
    test('facet counts do NOT change when a facet is toggled', async () => {
        // Mock returns DIFFERENT per-query filters once a facet is applied, to
        // prove the panel ignores them on a toggle and reuses the query-fixed counts.
        const search = jest.fn((query, opts) => {
            const hasFacet = !!(opts && opts.filters && opts.filters.region);
            return Promise.resolve({
                results: [makeResult({})],
                filters: hasFacet ? { region: { Asia: 99 }, era: { Modern: 1 } } : QUERY_FILTERS,
            });
        });
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'history';
        await inst.doSearch();
        await settle(window);
        const before = captureFacetTuples(window);

        await inst.toggleFilter('region', 'Asia');
        await settle(window);
        const after = captureFacetTuples(window);

        // Same dimensions, values, counts, order — only the checked state differs.
        expect(after).toEqual(before);
        expect(window.document.querySelector(
            'input[data-scolta-filter-dim="region"][data-scolta-filter-val="Asia"]').checked).toBe(true);
    });

    test('an active zero-count value stays enabled and uncheckable', async () => {
        const mock = buildMockPagefind([makeResult({})], QUERY_FILTERS, TAXONOMY);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'history';
        await inst.doSearch();
        await settle(window);

        // era=Ancient has count 0; activating it must keep it enabled + checked.
        await inst.toggleFilter('era', 'Ancient');
        await settle(window);
        const ancient = window.document.querySelector(
            'input[data-scolta-filter-dim="era"][data-scolta-filter-val="Ancient"]');
        expect(ancient.disabled).toBe(false);
        expect(ancient.checked).toBe(true);
    });

    test('a brand-new typed query DOES update the counts', async () => {
        const search = jest.fn((query) => Promise.resolve({
            results: [makeResult({})],
            filters: query === 'history'
                ? { region: { Asia: 40, Europe: 25 } }
                : { region: { Asia: 3, Europe: 99 } },
        }));
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;

        window.document.querySelector('#scolta-query').value = 'history';
        await inst.doSearch();
        await settle(window);
        const first = captureFacetTuples(window);

        // New typed query → fresh doSearch (preserveFilters falsy) → counts recompute.
        window.document.querySelector('#scolta-query').value = 'science';
        await inst.doSearch();
        await settle(window);
        const second = captureFacetTuples(window);

        expect(second).not.toEqual(first);
        const get = (tuples, val) => tuples.find(t => t.dim === 'region' && t.val === val).count;
        expect(get(second, 'Asia')).toBe('(3)');
        expect(get(second, 'Europe')).toBe('(99)');
    });

    test('panel stays stable across post-render async (expansion / auto-search)', async () => {
        // After the primary render, more async lands (AI expansion microtask, the
        // URL-driven auto-search). Because the panel is taxonomy-driven and counts
        // come from the deterministic typed query, none of it may move the panel.
        const mock = buildMockPagefind([makeResult({})], QUERY_FILTERS, TAXONOMY);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'history';
        await inst.doSearch();
        await settle(window);
        const first = captureFacetTuples(window);
        await settle(window);
        const second = captureFacetTuples(window);
        expect(first.length).toBeGreaterThan(0);
        expect(second).toEqual(first);
    });
});

// ===========================================================================
// Counts stable across expansion (the deliberate Option A consequence)
//
// Facet counts are the exact breakdown of the TYPED query, read once from
// Pagefind's native `.filters`. AI expansion enlarges the result list and the
// header "N results", but the facet counts must NOT move: Pagefind has no way
// to count a union of multiple search terms, and summing per-term `.filters`
// overcounts any doc matching more than one term. We therefore keep counts
// pinned to the typed query. This locks in that decision — it is the inverse of
// the (reverted) expansion-aware summing pass, which made the counts grow.
// ===========================================================================

// Per-term `.filters` the mock returns. If the code ever summed these across the
// expansion term set, the counts would GROW (history + warfare per value). The
// typed-query-only model must keep them at history's values.
const EXPAND_PER_TERM_FILTERS = {
    history: { region: { Asia: 40, Europe: 25 }, era: { Ancient: 0, Modern: 18 } },
    warfare: { region: { Asia: 10, Europe: 5 }, era: { Ancient: 4, Modern: 2 } },
};

const EXPAND_TAXONOMY = {
    language: { en: 50, es: 30 },
    region: { Asia: 12, Europe: 8, 'North America': 4 },
    era: { Ancient: 6, Modern: 9 },
};

// Build a window whose expand endpoint returns a real expansion term so the
// post-expansion merge actually runs. The expand response is gated behind
// `release()`, so a test can let the taxonomy + typed-query counts render first,
// capture them, then release expansion and confirm the counts did NOT move.
function createWindowWithExpansion(mock, expandTerms) {
    const { window } = createWindow(mock);
    let release;
    const gate = new Promise(r => { release = r; });
    window.fetch = jest.fn((url) => {
        if (String(url).includes('/e')) {
            return gate.then(() => ({
                ok: true, status: 200,
                json: () => Promise.resolve({ terms: expandTerms }),
                text: () => Promise.resolve(''),
            }));
        }
        return Promise.resolve({
            ok: false, status: 503,
            json: () => Promise.resolve({}), text: () => Promise.resolve(''),
        });
    });
    return { window, release };
}

function expandCountMock(perTermFilters, taxonomy) {
    const search = jest.fn((query) => Promise.resolve({
        results: [makeResult({})],
        filters: perTermFilters[query] || {},
    }));
    return { init: () => Promise.resolve(), filters: () => Promise.resolve(taxonomy), search };
}

const facetCount = (tuples, dim, val) => {
    const t = tuples.find(x => x.dim === dim && x.val === val);
    return t ? t.count : null;
};

describe('faceting: counts stable across expansion', () => {
    test('facet counts do NOT grow when AI expansion settles', async () => {
        const mock = expandCountMock(EXPAND_PER_TERM_FILTERS, EXPAND_TAXONOMY);
        const { window, release } = createWindowWithExpansion(mock, ['warfare']);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'history';

        // Expansion is gated: settle renders the taxonomy + typed-query counts
        // while the expand request is still pending.
        await inst.doSearch();
        await settle(window);
        const primary = captureFacetTuples(window);

        // Release expansion; the result list/header change, but the facet counts
        // stay pinned to the typed query — no summed second pass exists.
        release();
        await settle(window);
        const expanded = captureFacetTuples(window);

        // Counts are the typed query 'history' breakdown, unchanged by expansion.
        // (Had warfare's `.filters` been summed in, Asia would read 50, not 40.)
        expect(facetCount(primary, 'region', 'Asia')).toBe('(40)');
        expect(facetCount(expanded, 'region', 'Asia')).toBe('(40)');
        expect(facetCount(primary, 'region', 'Europe')).toBe('(25)');
        expect(facetCount(expanded, 'region', 'Europe')).toBe('(25)');
        // A value zero on the typed query stays zero — expansion does not pull
        // warfare's matches into the count.
        expect(facetCount(primary, 'era', 'Ancient')).toBe('(0)');
        expect(facetCount(expanded, 'era', 'Ancient')).toBe('(0)');

        // The entire panel — dims, values, counts, order — is byte-identical
        // before and after expansion settles.
        expect(expanded).toEqual(primary);
    });
});

// ===========================================================================
// OR-fallback facet counts (the AND-empty count path)
//
// Pagefind ANDs every word of a multi-word query, so a long conversational
// query often returns ZERO AND matches; the result list then rebuilds from
// per-term OR searches. The facet counts must follow the same mode decision:
// reading the empty AND search's native `.filters` would report every count as
// 0 (the bug). In fallback mode counts are the EXACT union of per-term matches —
// each document counted once (by fragment id), capped per term — never a sum of
// per-term `.filters`, which double-counts any doc matching more than one term.
// ===========================================================================

const OR_TAXONOMY = {
    language: { en: 100, es: 40 },                 // structural — never a user facet
    difficulty: { Beginner: 10, Intermediate: 20, Advanced: 8 },
    section: { Tips: 5, Guides: 7 },
};

// Per-term result sets WITH OVERLAP: 'doc-2' is returned by both 'alpha' and
// 'beta'; 'doc-3' by both 'beta' and 'gamma'. The strict AND query matches
// nothing. Each fragment carries scalar filter values (as real Drupal fragments
// do). If the count path summed per-term `.filters` (the rejected approach) or
// failed to union by id, the overlapping docs would be counted twice.
function buildOrFallbackMock(perTerm, taxonomy, andQuery) {
    const search = jest.fn((query) => {
        if (query === andQuery) {
            // Strict AND returns nothing → OR fallback / union count path engages.
            return Promise.resolve({ results: [], filters: {} });
        }
        return Promise.resolve({ results: perTerm[query] || [], filters: {} });
    });
    return { init: () => Promise.resolve(), filters: () => Promise.resolve(taxonomy), search };
}

describe('faceting: OR-fallback facet counts', () => {
    test('AND-zero query tallies the exact union of per-term matches (overlap counted once)', async () => {
        const r1 = makeResult({ difficulty: 'Beginner', language: 'en' }, { id: 'doc-1', url: '/1' });
        const r2 = makeResult({ difficulty: 'Intermediate', language: 'en' }, { id: 'doc-2', url: '/2' });
        const r3 = makeResult({ difficulty: 'Advanced', language: 'en' }, { id: 'doc-3', url: '/3' });
        // doc-2 in both alpha+beta, doc-3 in both beta+gamma — fresh result
        // objects with the SAME id, exactly as Pagefind returns across searches.
        const r2b = makeResult({ difficulty: 'Intermediate', language: 'en' }, { id: 'doc-2', url: '/2' });
        const r3b = makeResult({ difficulty: 'Advanced', language: 'en' }, { id: 'doc-3', url: '/3' });
        const mock = buildOrFallbackMock(
            { alpha: [r1, r2], beta: [r2b, r3], gamma: [r3b] },
            OR_TAXONOMY,
            'alpha beta gamma'
        );
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'alpha beta gamma';
        await inst.doSearch();
        await settle(window);

        const tuples = captureFacetTuples(window);
        // Each unique doc contributes once: Beginner(doc-1), Intermediate(doc-2),
        // Advanced(doc-3) — all 1, NOT 2. Intermediate==1 is the assertion that
        // locks out the rejected per-term summing / non-deduped tally.
        expect(facetCount(tuples, 'difficulty', 'Beginner')).toBe('(1)');
        expect(facetCount(tuples, 'difficulty', 'Intermediate')).toBe('(1)');
        expect(facetCount(tuples, 'difficulty', 'Advanced')).toBe('(1)');
    });

    test('fallback counts are structural-only: user dims dropped, structural filter applied to per-term searches', async () => {
        const makeMock = () => {
            const r1 = makeResult({ difficulty: 'Beginner', language: 'en' }, { id: 'doc-1', url: '/1' });
            const r2 = makeResult({ difficulty: 'Intermediate', language: 'en' }, { id: 'doc-2', url: '/2' });
            const r2b = makeResult({ difficulty: 'Intermediate', language: 'en' }, { id: 'doc-2', url: '/2' });
            const r3 = makeResult({ difficulty: 'Advanced', language: 'en' }, { id: 'doc-3', url: '/3' });
            return buildOrFallbackMock(
                { alpha: [r1, r2], beta: [r2b, r3], gamma: [r3] },
                OR_TAXONOMY,
                'alpha beta gamma'
            );
        };

        // Baseline: a structural language filter only. Sets must be built with
        // the window's Set so `instanceof Set` holds inside scolta.js's realm.
        const baseMock = makeMock();
        const w1 = createWindow(baseMock);
        w1.window.document.querySelector('#scolta-query').value = 'alpha beta gamma';
        await w1.window.Scolta.defaultInstance.doSearch(false, { language: new w1.window.Set(['en']) });
        await settle(w1.window);
        const baseline = captureFacetTuples(w1.window);

        // Same query, but with a user-facing difficulty facet ALSO selected.
        const selMock = makeMock();
        const w2 = createWindow(selMock);
        w2.window.document.querySelector('#scolta-query').value = 'alpha beta gamma';
        await w2.window.Scolta.defaultInstance.doSearch(false, {
            language: new w2.window.Set(['en']),
            difficulty: new w2.window.Set(['Beginner']),
        });
        await settle(w2.window);
        const withSelection = captureFacetTuples(w2.window);

        // Counts are identical — the user's difficulty selection was dropped from
        // the count searches (invariant: counts independent of facet clicks).
        const countsOnly = t => t.map(x => ({ dim: x.dim, val: x.val, count: x.count }));
        expect(countsOnly(withSelection)).toEqual(countsOnly(baseline));

        // The count path's per-term searches apply the STRUCTURAL language filter
        // but NOT the user difficulty selection: among the 'alpha' searches there
        // is one scoped to language alone (the result-path fallback also searches
        // 'alpha', but carries the full activeFilters incl. difficulty).
        const alphaCalls = selMock.search.mock.calls.filter(c => c[0] === 'alpha');
        const structuralCall = alphaCalls.find(
            c => c[1] && JSON.stringify(c[1].filters) === JSON.stringify({ language: 'en' })
        );
        expect(structuralCall).toBeDefined();
    });

    test('scalar AND array fragment filter values both tally correctly', async () => {
        const taxonomy = {
            language: { en: 100 },
            topic: { git: 5, vcs: 4, mercurial: 2 },
            difficulty: { Beginner: 3, Advanced: 2 },
        };
        // r1: array-valued `topic` + scalar `difficulty`; r2: scalar `topic`.
        const r1 = makeResult({ topic: ['git', 'vcs'], difficulty: 'Beginner', language: 'en' }, { id: 'doc-1', url: '/1' });
        const r2 = makeResult({ topic: 'git', difficulty: 'Advanced', language: 'en' }, { id: 'doc-2', url: '/2' });
        const mock = buildOrFallbackMock(
            { alpha: [r1], beta: [r2] },
            taxonomy,
            'alpha beta'
        );
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'alpha beta';
        await inst.doSearch();
        await settle(window);

        const tuples = captureFacetTuples(window);
        // git: doc-1 (in array) + doc-2 (scalar) = 2; vcs: doc-1 only = 1.
        expect(facetCount(tuples, 'topic', 'git')).toBe('(2)');
        expect(facetCount(tuples, 'topic', 'vcs')).toBe('(1)');
        expect(facetCount(tuples, 'topic', 'mercurial')).toBe('(0)');
        expect(facetCount(tuples, 'difficulty', 'Beginner')).toBe('(1)');
        expect(facetCount(tuples, 'difficulty', 'Advanced')).toBe('(1)');
    });

    test('forced-phrase query stays all-zero and triggers NO per-term count searches', async () => {
        // A quoted query never falls back to OR — the user asked for the phrase.
        const mock = buildOrFallbackMock({ alpha: [makeResult({ difficulty: 'Beginner' }, { id: 'd1' })] },
            OR_TAXONOMY, 'alpha beta');
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = '"alpha beta"';
        await inst.doSearch();
        await settle(window);

        const tuples = captureFacetTuples(window);
        expect(facetCount(tuples, 'difficulty', 'Beginner')).toBe('(0)');
        expect(facetCount(tuples, 'difficulty', 'Intermediate')).toBe('(0)');
        // No per-term search ('alpha' or 'beta' alone) ran — neither the result
        // OR fallback nor the count union engages for a forced phrase.
        const perTerm = mock.search.mock.calls.filter(c => c[0] === 'alpha' || c[0] === 'beta');
        expect(perTerm).toHaveLength(0);
    });

    test('single meaningful term whose search is empty stays all-zero', async () => {
        // One meaningful term → no OR fallback → zeros are truthful (the list is
        // empty too). The query 'solo' returns nothing.
        const search = jest.fn(() => Promise.resolve({ results: [], filters: {} }));
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(OR_TAXONOMY), search };
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'solo';
        await inst.doSearch();
        await settle(window);

        const tuples = captureFacetTuples(window);
        expect(facetCount(tuples, 'difficulty', 'Beginner')).toBe('(0)');
        expect(facetCount(tuples, 'difficulty', 'Intermediate')).toBe('(0)');
        expect(facetCount(tuples, 'difficulty', 'Advanced')).toBe('(0)');
    });
});

// ===========================================================================
// Auto-language filter (behavioral tests)
// ===========================================================================

function createWindowWithConfig(mockPagefind, extraScoltaConfig, htmlLang) {
    const htmlAttr = htmlLang ? ` lang="${htmlLang}"` : '';
    const dom = new JSDOM(
        `<!DOCTYPE html><html${htmlAttr}><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;
    window.fetch = jest.fn().mockResolvedValue({
        ok: false, status: 503,
        json: () => Promise.resolve({}),
        text: () => Promise.resolve(''),
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    window.scrollTo = () => {};
    window.mockPagefind = mockPagefind;

    window.eval(patchedSource);
    window.scolta = Object.assign({
        scoring: {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    }, extraScoltaConfig);
    window.Scolta.init('#scolta-search');
    return { dom, window };
}

describe('auto-language filter: currentLanguage config', () => {
    test('applies language filter from currentLanguage config on fresh search', async () => {
        const mock = buildMockPagefind([]);
        const { window } = createWindowWithConfig(mock, {
            currentLanguage: 'en',
            scoring: { AI_LANGUAGES: ['en', 'es'], AUTO_LANGUAGE_FILTER: true },
        });
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'worktrees';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'worktrees');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).toHaveProperty('filters');
        expect(searchCall[1].filters).toEqual({ language: 'en' });
    });

    test('URL f_language param overrides currentLanguage config', async () => {
        const mock = buildMockPagefind([]);
        // URL has f_language=fr but config says currentLanguage: 'en'
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
            { url: 'https://example.com/?q=worktrees&f_language=fr', runScripts: 'dangerously' }
        );
        const win = dom.window;
        win.fetch = jest.fn().mockResolvedValue({ ok: false, status: 503, json: () => Promise.resolve({}), text: () => Promise.resolve('') });
        win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
        win.scrollTo = () => {};
        win.mockPagefind = mock;
        win.eval(patchedSource);
        win.scolta = {
            scoring: { AI_LANGUAGES: ['en', 'fr'] },
            endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
            pagefindPath: '/pf.js',
            siteName: 'Test',
            container: '#scolta-search',
            allowedLinkDomains: [],
            disclaimer: '',
            currentLanguage: 'en',
        };
        win.Scolta.init('#scolta-search');
        // Wait for pagefind init + URL-based auto-search to complete.
        await new Promise(r => setTimeout(r, 50));

        // The URL had f_language=fr, which takes priority over currentLanguage: 'en'.
        const calls = mock.search.mock.calls;
        const autoSearch = calls.find(c => c[0] === 'worktrees');
        expect(autoSearch).toBeDefined();
        expect(autoSearch[1]).toHaveProperty('filters');
        expect(autoSearch[1].filters).toEqual({ language: 'fr' });
    });

    test('no language filter when currentLanguage is not set and no html lang', async () => {
        const mock = buildMockPagefind([]);
        // createWindow() (from above) has no currentLanguage and no lang attr
        const { window } = createWindowWithConfig(mock, {});
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'worktrees';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'worktrees');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).not.toHaveProperty('filters');
    });
});

describe('auto-language filter: html lang fallback', () => {
    test('detects language from html lang attribute when no currentLanguage config', async () => {
        const mock = buildMockPagefind([]);
        const { window } = createWindowWithConfig(mock, { scoring: { AI_LANGUAGES: ['en', 'es'], AUTO_LANGUAGE_FILTER: true } }, 'es');
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'worktrees';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'worktrees');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).toHaveProperty('filters');
        expect(searchCall[1].filters).toEqual({ language: 'es' });
    });

    test('strips region subtag from html lang (en-US → en)', async () => {
        const mock = buildMockPagefind([]);
        const { window } = createWindowWithConfig(mock, { scoring: { AI_LANGUAGES: ['en', 'es'], AUTO_LANGUAGE_FILTER: true } }, 'en-US');
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'worktrees';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'worktrees');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).toHaveProperty('filters');
        expect(searchCall[1].filters).toEqual({ language: 'en' });
    });

    test('currentLanguage config takes precedence over html lang attribute', async () => {
        const mock = buildMockPagefind([]);
        const { window } = createWindowWithConfig(mock, {
            currentLanguage: 'de',
            scoring: { AI_LANGUAGES: ['de', 'fr'], AUTO_LANGUAGE_FILTER: true },
        }, 'fr');
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'worktrees';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'worktrees');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).toHaveProperty('filters');
        expect(searchCall[1].filters).toEqual({ language: 'de' });
    });
});

describe('auto-language filter: user can clear the filter', () => {
    test('user unchecking language filter removes it for that search', async () => {
        const filters = { language: { en: 100, es: 50 } };
        const mock = buildMockPagefind([], filters);
        const { window } = createWindowWithConfig(mock, {
            currentLanguage: 'en',
            scoring: { AI_LANGUAGES: ['en', 'es'], AUTO_LANGUAGE_FILTER: true },
        });
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        // The first search should have the auto-filter applied (AUTO_LANGUAGE_FILTER: true).
        const initialCall = mock.search.mock.calls.find(c => c[0] === 'test');
        expect(initialCall[1].filters).toEqual({ language: 'en' });

        // User unchecks "English" — toggleFilter removes it. Capture only the
        // search that this toggle triggers (calls after the pre-toggle baseline),
        // so a late init/expansion auto-search can't make the assertion flaky.
        const before = mock.search.mock.calls.length;
        await inst.toggleFilter('language', 'en');
        const toggleCall = mock.search.mock.calls.slice(before).find(c => c[0] === 'test');
        expect(toggleCall).toBeDefined();
        // After unchecking, no language filter in the toggle's search.
        expect(toggleCall[1]).not.toHaveProperty('filters');
    });
});

describe('auto-language filter: AI_LANGUAGES guard', () => {
    test('skips auto-filter when AI_LANGUAGES has only one entry (monolingual site)', async () => {
        const mock = buildMockPagefind([]);
        // ai_languages: ['en'] — single-language site, filter would be a no-op
        const { window } = createWindowWithConfig(mock, {
            currentLanguage: 'en',
            scoring: { AI_LANGUAGES: ['en'] },
        });
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'test');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).not.toHaveProperty('filters');
    });

    test('skips auto-filter when detected language is not in AI_LANGUAGES', async () => {
        const mock = buildMockPagefind([]);
        // currentLanguage 'zh' is not in ['en', 'es'] — no indexed Chinese content
        const { window } = createWindowWithConfig(mock, {
            currentLanguage: 'zh',
            scoring: { AI_LANGUAGES: ['en', 'es'] },
        });
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'test');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).not.toHaveProperty('filters');
    });

    test('skips auto-filter when AI_LANGUAGES is empty', async () => {
        const mock = buildMockPagefind([]);
        const { window } = createWindowWithConfig(mock, {
            currentLanguage: 'en',
            scoring: { AI_LANGUAGES: [] },
        });
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const calls = mock.search.mock.calls;
        const searchCall = calls.find(c => c[0] === 'test');
        expect(searchCall).toBeDefined();
        expect(searchCall[1]).not.toHaveProperty('filters');
    });
});

// ===========================================================================
// matchSubjectToFilters tests (with subcategory matching)
// ===========================================================================

const SKIP_FILTER_DIMENSIONS_TEST = new Set(['site', 'language', 'content_type', 'entity_type']);

function matchSubjectToFiltersTest(subjectTerms, availableFilters, filterDescriptions) {
    if (!subjectTerms || !subjectTerms.length || !availableFilters) return {};

    const keywords = new Set();
    for (const term of subjectTerms) {
        for (const word of term.toLowerCase().split(/\s+/)) {
            if (word.length > 2) keywords.add(word);
        }
    }

    const matched = {};
    for (const [dimension, values] of Object.entries(availableFilters)) {
        if (SKIP_FILTER_DIMENSIONS_TEST.has(dimension.toLowerCase())) continue;

        for (const filterValue of Object.keys(values)) {
            const lowerValue = filterValue.toLowerCase();
            for (const keyword of keywords) {
                if (lowerValue === keyword
                    || (lowerValue.length > 2 && keyword.includes(lowerValue))
                    || (keyword.length > 2 && lowerValue.includes(keyword))) {
                    matched[dimension] = filterValue;
                    break;
                }
            }
            if (matched[dimension]) break;
        }

        if (!matched[dimension] && filterDescriptions) {
            const desc = (filterDescriptions[dimension] || '').toLowerCase();
            for (const filterValue of Object.keys(values)) {
                const escapedValue = filterValue.toLowerCase().replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const pattern = new RegExp(escapedValue + '\\s*\\(([^)]+)\\)');
                const m = desc.match(pattern);
                if (m) {
                    const subcategories = m[1].split(',').map(s => s.trim());
                    for (const sub of subcategories) {
                        if (keywords.has(sub) || [...keywords].some(kw =>
                            (sub.length > 2 && kw.includes(sub)) ||
                            (kw.length > 2 && sub.includes(kw))
                        )) {
                            matched[dimension] = filterValue;
                            break;
                        }
                    }
                }
                if (matched[dimension]) break;
            }
        }
    }

    return matched;
}

describe('matchSubjectToFilters: direct matching (Pass 1)', () => {
    test('matches exact filter value name', () => {
        const result = matchSubjectToFiltersTest(
            ['history'],
            { topics: { History: 5, Science: 3 } },
            {}
        );
        expect(result).toEqual({ topics: 'History' });
    });

    test('matches via substring (keyword includes filter value)', () => {
        const result = matchSubjectToFiltersTest(
            ['science'],
            { topics: { Science: 3 } },
            null
        );
        expect(result).toEqual({ topics: 'Science' });
    });

    test('returns empty when no match', () => {
        const result = matchSubjectToFiltersTest(
            ['articles about happiness'],
            { topics: { History: 5, Science: 3 } },
            { topics: 'Science (physics, chemistry), History (ancient, medieval)' }
        );
        expect(result).toEqual({});
    });

    test('skips SKIP_FILTER_DIMENSIONS', () => {
        const result = matchSubjectToFiltersTest(
            ['english'],
            { language: { en: 100 } },
            {}
        );
        expect(result).toEqual({});
    });
});

describe('matchSubjectToFilters: subcategory matching (Pass 2)', () => {
    const topicFilters = { topics: { History: 5, Science: 3, Arts: 2 } };
    const topicDescs = {
        topics: 'Arts (music, painting, sculpture), Science (physics, chemistry, biology), History (ancient, medieval)',
    };

    test('physics matches Science via subcategory description', () => {
        const result = matchSubjectToFiltersTest(
            ['articles about physics'],
            topicFilters,
            topicDescs
        );
        expect(result).toEqual({ topics: 'Science' });
    });

    test('music matches Arts via subcategory description', () => {
        const result = matchSubjectToFiltersTest(
            ['music and revolution'],
            topicFilters,
            topicDescs
        );
        expect(result).toEqual({ topics: 'Arts' });
    });

    test('ancient matches History via subcategory description when not a direct match', () => {
        // "ancient" is 7 chars, "History" filter value doesn't contain "ancient"
        // but the description has History (ancient, medieval)
        const result = matchSubjectToFiltersTest(
            ['ancient civilizations'],
            { topics: { Science: 3, Arts: 2 } },
            { topics: 'Arts (music, painting), Science (physics, chemistry)' }
        );
        // No match — "ancient" not in Arts or Science subcategories
        expect(result).toEqual({});
    });

    test('no descriptions falls back to direct matching only', () => {
        const result = matchSubjectToFiltersTest(
            ['physics'],
            { topics: { Science: 3 } },
            null
        );
        // "physics" doesn't directly match "Science", so no match
        expect(result).toEqual({});
    });

    test('empty descriptions object falls back to direct matching only', () => {
        const result = matchSubjectToFiltersTest(
            ['physics'],
            { topics: { Science: 3 } },
            {}
        );
        expect(result).toEqual({});
    });

    test('direct match takes priority over subcategory match', () => {
        // "history" directly matches "History" in Pass 1, so Pass 2 is skipped
        const result = matchSubjectToFiltersTest(
            ['history'],
            topicFilters,
            topicDescs
        );
        expect(result).toEqual({ topics: 'History' });
    });
});

// ===========================================================================
// Sort-without-filter fallback (source-structure tests)
// ===========================================================================

describe('sort-without-filter fallback: source structure', () => {
    test('sort path applies the sort unscoped when subject terms have no filter match', () => {
        // Regression 2026-06-09: dropping the sort here silently ignored
        // explicit sort intent for generic subjects ("newest posts",
        // "cheapest crystals") that never map to a facet.
        expect(scoltaSource).toContain(
            'No filter match for subject terms — applying sort unscoped'
        );
        expect(scoltaSource).not.toContain(
            'dropping sort, using relevance'
        );
    });

    test('sort path checks subjectTerms length for the unmatched-subject branch', () => {
        expect(scoltaSource).toContain('subjectTerms && subjectTerms.length > 0');
    });

    test('sort path uses useSortPath variable for branching', () => {
        expect(scoltaSource).toContain('let useSortPath');
        expect(scoltaSource).toContain('if (useSortPath)');
    });

    test('matchSubjectToFilters receives filterDescriptions parameter', () => {
        expect(scoltaSource).toContain('function matchSubjectToFilters(subjectTerms, availableFilters, filterDescriptions)');
    });

    test('sort path passes filterFieldDescriptions from instanceConfig', () => {
        expect(scoltaSource).toContain('instanceConfig.filterFieldDescriptions');
    });
});
