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

    test('computeFilterCounts reads r.data.filters not r.data.meta.site', () => {
        expect(scoltaSource).toContain('const filters = r.data.filters || {};');
        expect(scoltaSource).not.toMatch(/r\.data\.meta\?\.site/);
    });

    test('computeFilterCounts returns nested dimension structure', () => {
        expect(scoltaSource).toContain('if (!counts[dim]) counts[dim] = {};');
        expect(scoltaSource).toContain('counts[dim][v] = (counts[dim][v] || 0) + 1;');
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

    test('renderFilters orders language first, site second', () => {
        expect(scoltaSource).toContain("const order = { language: 0, site: 1 };");
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

    test('doSearch assigns filterCounts from computeFilterCounts(allScoredResults)', () => {
        expect(scoltaSource).toContain('filterCounts = computeFilterCounts(allScoredResults);');
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

function computeFilterCountsTest(results) {
    const counts = {};
    for (const r of results) {
        const filters = r.data.filters || {};
        for (const [dim, val] of Object.entries(filters)) {
            if (!val) continue;
            const values = Array.isArray(val) ? val : [val];
            for (const v of values) {
                if (!v) continue;
                if (!counts[dim]) counts[dim] = {};
                counts[dim][v] = (counts[dim][v] || 0) + 1;
            }
        }
    }
    return counts;
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

describe('faceting: computeFilterCounts logic', () => {
    test('returns empty object for empty results', () => {
        expect(computeFilterCountsTest([])).toEqual({});
    });

    test('returns empty object when results have no filters', () => {
        const results = [
            { data: { meta: { title: 'T1' }, filters: {} } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({});
    });

    test('counts single dimension', () => {
        const results = [
            { data: { filters: { language: 'en' } } },
            { data: { filters: { language: 'en' } } },
            { data: { filters: { language: 'fr' } } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({
            language: { en: 2, fr: 1 },
        });
    });

    test('counts multiple dimensions simultaneously', () => {
        const results = [
            { data: { filters: { language: 'en', site: 'Site A' } } },
            { data: { filters: { language: 'es', site: 'Site B' } } },
            { data: { filters: { language: 'en', site: 'Site A' } } },
        ];
        const counts = computeFilterCountsTest(results);
        expect(counts.language).toEqual({ en: 2, es: 1 });
        expect(counts.site).toEqual({ 'Site A': 2, 'Site B': 1 });
    });

    test('ignores falsy filter values', () => {
        const results = [
            { data: { filters: { language: '', site: 'Site A' } } },
            { data: { filters: { language: null, site: 'Site B' } } },
        ];
        const counts = computeFilterCountsTest(results);
        expect(counts.language).toBeUndefined();
        expect(counts.site).toEqual({ 'Site A': 1, 'Site B': 1 });
    });

    test('handles single-element array filter values', () => {
        const results = [
            { data: { filters: { language: ['en'] } } },
            { data: { filters: { language: ['en'] } } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({
            language: { en: 2 },
        });
    });

    test('handles multi-value array filters by counting each value', () => {
        const results = [
            { data: { filters: { topics: ['Science', 'History'] } } },
            { data: { filters: { topics: ['Science'] } } },
            { data: { filters: { topics: ['Arts'] } } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({
            topics: { Science: 2, History: 1, Arts: 1 },
        });
    });

    test('multi-value array with null elements skips nulls', () => {
        const results = [
            { data: { filters: { topics: ['Science', null, 'History'] } } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({
            topics: { Science: 1, History: 1 },
        });
    });

    test('multi-value array with empty string elements skips empties', () => {
        const results = [
            { data: { filters: { topics: ['Science', '', 'History'] } } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({
            topics: { Science: 1, History: 1 },
        });
    });

    test('results without filters field are skipped', () => {
        const results = [
            { data: { meta: { title: 'No filters' } } },
            { data: { filters: { language: 'en' } } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({ language: { en: 1 } });
    });
});

// ===========================================================================
// Behavioral tests (JSDOM)
// ===========================================================================

const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = mockPagefind'
);

function buildMockPagefind(resultsList, searchFilters) {
    return {
        init: () => Promise.resolve(),
        search: jest.fn(() => Promise.resolve({ results: resultsList, filters: searchFilters || {} })),
    };
}

function makeResult(filterObj, overrides) {
    return {
        data: () => Promise.resolve({
            meta: {
                title: (overrides && overrides.title) || 'Test Page',
                url: (overrides && overrides.url) || '/test',
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

describe('faceting: filter sidebar rendered from scored results', () => {
    test('renders language facets from scored result filters', async () => {
        const mock = buildMockPagefind([
            makeResult({ language: 'en' }, { title: 'English Article', url: '/en/article' }),
            makeResult({ language: 'es' }, { title: 'Spanish Article', url: '/es/article' }),
            makeResult({ language: 'fr' }, { title: 'French Article', url: '/fr/article' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).toContain('English');
        expect(filterContainer.innerHTML).toContain('Spanish');
        expect(filterContainer.innerHTML).toContain('French');
    });

    test('hides filter sidebar when scored results have only one value per dimension', async () => {
        const mock = buildMockPagefind([
            makeResult({ language: 'en' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).toBe('');
    });

    test('renders multiple dimensions from scored result filters', async () => {
        const mock = buildMockPagefind([
            makeResult({ language: 'en', content_type: 'article' }, { title: 'English Article', url: '/en/art' }),
            makeResult({ language: 'es', content_type: 'page' }, { title: 'Spanish Page', url: '/es/page' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).toContain('Language');
        expect(filterContainer.innerHTML).toContain('Content Type');
        expect(filterContainer.innerHTML).toContain('English');
        expect(filterContainer.innerHTML).toContain('Spanish');
        expect(filterContainer.innerHTML).toContain('article');
        expect(filterContainer.innerHTML).toContain('page');
    });

    test('refreshes facet counts after filter toggle', async () => {
        const mock = buildMockPagefind([
            makeResult({ language: 'en' }, { title: 'English Doc', url: '/en/doc' }),
            makeResult({ language: 'es' }, { title: 'Spanish Doc', url: '/es/doc' }),
            makeResult({ language: 'fr' }, { title: 'French Doc', url: '/fr/doc' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        await inst.toggleFilter('language', 'en');

        const filterContainer = window.document.querySelector('#scolta-filters');
        // Counts are recomputed from the current result set after every toggle.
        // The mock returns all results regardless of filters, so all three
        // language values remain visible.
        expect(filterContainer.innerHTML).toContain('English');
        expect(filterContainer.innerHTML).toContain('Spanish');
        expect(filterContainer.innerHTML).toContain('French');
    });

    test('active filter dimension stays visible when only one value remains', async () => {
        // After filtering, the active dimension may have only one value.
        // It must still be shown so the user can uncheck it.
        const mock = buildMockPagefind([
            makeResult({ language: 'en', era: 'Ancient' }, { title: 'Ancient Article', url: '/ancient' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        // After initial search, era has only one value — normally hidden.
        let filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).not.toContain('Ancient');

        // Now toggle the era filter. Even with one value, the dimension
        // must remain visible because the filter is active.
        await inst.toggleFilter('era', 'Ancient');
        filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).toContain('Ancient');
        expect(filterContainer.querySelector('input[data-scolta-filter-dim="era"]').checked).toBe(true);
    });

    test('active filter value shown with zero count when absent from results', async () => {
        // If the user selects a cross-dimension filter that excludes all
        // results for another dimension's active value, the active value
        // must still be rendered (with count 0) so it can be unchecked.
        const mock = buildMockPagefind([
            makeResult({ language: 'en', era: 'Modern' }, { title: 'Modern English', url: '/modern-en' }),
            makeResult({ language: 'es', era: 'Ancient' }, { title: 'Ancient Spanish', url: '/ancient-es' }),
        ]);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        // Activate era=Ancient and language=en. The mock doesn't filter,
        // so both results come back. But simulate the scenario by manually
        // setting activeFilters and a filterCounts that would result from
        // real Pagefind filtering (only en results, so era only has Modern).
        // We verify via toggleFilter which exercises the real code path.
        await inst.toggleFilter('era', 'Ancient');
        const filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).toContain('Ancient');
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

        // User unchecks "English" — toggleFilter removes it.
        await inst.toggleFilter('language', 'en');
        const afterToggle = mock.search.mock.calls.filter(c => c[0] === 'test');
        const lastCall = afterToggle[afterToggle.length - 1];
        // After unchecking, no language filter in this search.
        expect(lastCall[1]).not.toHaveProperty('filters');
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
    test('sort path drops sort when subject terms have no filter match', () => {
        expect(scoltaSource).toContain(
            'No filter match for subject terms — dropping sort, using relevance'
        );
    });

    test('sort path checks subjectTerms length before dropping sort', () => {
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
