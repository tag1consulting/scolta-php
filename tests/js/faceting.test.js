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

    test('TODO comment for auto-detect language from URL', () => {
        expect(scoltaSource).toContain('TODO: auto-detect active language from URL path');
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
        expect(scoltaSource).toContain('activeFilters = initialFilters || {};');
    });

    test('doSearch assigns filterCounts from primarySearch.filters', () => {
        expect(scoltaSource).toContain('filterCounts = primarySearch.filters || {};');
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
            const v = Array.isArray(val) ? val[0] : val;
            if (!v) continue;
            if (!counts[dim]) counts[dim] = {};
            counts[dim][v] = (counts[dim][v] || 0) + 1;
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

    test('handles array filter values by using first element', () => {
        const results = [
            { data: { filters: { language: ['en'] } } },
            { data: { filters: { language: ['en'] } } },
        ];
        expect(computeFilterCountsTest(results)).toEqual({
            language: { en: 2 },
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

function makeResult(filterObj) {
    return {
        data: () => Promise.resolve({
            meta: { title: 'Test Page', url: '/test', site: filterObj.site || 'Site A' },
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

describe('faceting: filter sidebar rendered from search.filters', () => {
    test('renders language facets from search response filters', async () => {
        const filters = { language: { en: 100, es: 50, fr: 30 } };
        const mock = buildMockPagefind([], filters);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).toContain('English');
        expect(filterContainer.innerHTML).toContain('Spanish');
        expect(filterContainer.innerHTML).toContain('French');
    });

    test('hides filter sidebar when search.filters has only one value per dimension', async () => {
        const filters = { language: { en: 100 } };
        const mock = buildMockPagefind([], filters);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        const filterContainer = window.document.querySelector('#scolta-filters');
        expect(filterContainer.innerHTML).toBe('');
    });

    test('renders multiple dimensions from search.filters', async () => {
        const filters = {
            language: { en: 100, es: 50 },
            content_type: { article: 80, page: 70 },
        };
        const mock = buildMockPagefind([], filters);
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

    test('preserves filter counts when toggling a filter', async () => {
        const filters = { language: { en: 100, es: 50, fr: 30 } };
        const mock = buildMockPagefind([], filters);
        const { window } = createWindow(mock);
        const inst = window.Scolta.defaultInstance;
        window.document.querySelector('#scolta-query').value = 'test';
        await inst.doSearch();

        // Toggle a filter — filterCounts should not change (preserveFilters=true).
        await inst.toggleFilter('language', 'en');

        const filterContainer = window.document.querySelector('#scolta-filters');
        // All three language options should still be visible after toggling.
        expect(filterContainer.innerHTML).toContain('English');
        expect(filterContainer.innerHTML).toContain('Spanish');
        expect(filterContainer.innerHTML).toContain('French');
    });
});
