/**
 * Behavioral tests for scolta.js — execute code in real JSDOM.
 *
 * These tests load scolta.js by evaluating it in a jsdom window context
 * with dynamic import() patched to return a mock Pagefind module.
 * This verifies actual runtime behavior, not just source strings.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Patch the dynamic import before each test.
// Replace `pagefind = await import(pagefindPath)` with a synchronous mock.
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = { init: function() { return Promise.resolve(); }, search: function() { return Promise.resolve({ results: [] }); } }'
);

/**
 * Create a fresh JSDOM window with scolta.js loaded.
 */
function createWindow(html = '<div id="scolta-search"></div>', config = {}) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body>${html}</body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );

    const window = dom.window;

    // Mock fetch.
    window.fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
        text: () => Promise.resolve('[]'),
        status: 200,
    });

    // Suppress console noise.
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };

    // JSDOM doesn't implement scrollTo — stub it so the VirtualConsole
    // doesn't emit "Not implemented" noise on every clearSearch call.
    window.scrollTo = () => {};

    // Load scolta.js WITHOUT auto-init (don't set config before eval).
    window.eval(patchedSource);

    // Set config and init manually — auto-init has a JSDOM timing issue
    // where innerHTML mutations during eval() don't persist.
    const container = config.container || '#scolta-search';
    window.scolta = {
        scoring: config.scoring || {},
        endpoints: config.endpoints || { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: config.siteName || 'Test',
        container: container,
        allowedLinkDomains: [],
        disclaimer: '',
    };

    // Manual init if container exists.
    if (window.document.querySelector(container)) {
        window.Scolta.init(container);
    }

    return { dom, window };
}

describe('scolta.js behavioral tests', () => {

    test('Scolta namespace is created', () => {
        const { window } = createWindow();
        expect(window.Scolta).toBeDefined();
        expect(typeof window.Scolta.init).toBe('function');
        expect(typeof window.Scolta.createInstance).toBe('function');
    });

    test('auto-init creates search UI in container', () => {
        const { window } = createWindow();
        const container = window.document.querySelector('#scolta-search');
        expect(container.children.length).toBeGreaterThan(0);
        expect(container.innerHTML).toContain('scolta-search-box');
    });

    test('search input exists after init', () => {
        const { window } = createWindow();
        const input = window.document.querySelector('#scolta-query');
        expect(input).not.toBeNull();
        expect(input.tagName).toBe('INPUT');
    });

    test('search button exists', () => {
        const { window } = createWindow();
        const btn = window.document.querySelector('#scolta-search-btn');
        expect(btn).not.toBeNull();
        expect(btn.textContent).toBe('Search');
    });

    test('clear button is hidden initially', () => {
        const { window } = createWindow();
        const clear = window.document.querySelector('#scolta-search-clear');
        expect(clear).not.toBeNull();
        expect(clear.style.display).toBe('none');
    });

    test('layout is hidden until search', () => {
        const { window } = createWindow();
        const layout = window.document.querySelector('#scolta-layout');
        expect(layout).not.toBeNull();
        expect(layout.style.display).toBe('none');
    });

    test('layout does not have has-filters class initially', () => {
        const { window } = createWindow();
        const layout = window.document.querySelector('#scolta-layout');
        expect(layout.classList.contains('has-filters')).toBe(false);
    });

    test('filters aside is empty initially', () => {
        const { window } = createWindow();
        const filters = window.document.querySelector('#scolta-filters');
        expect(filters).not.toBeNull();
        expect(filters.innerHTML).toBe('');
    });

    test('no-results is hidden initially', () => {
        const { window } = createWindow();
        const noResults = window.document.querySelector('#scolta-no-results');
        expect(noResults).not.toBeNull();
        expect(noResults.style.display).toBe('none');
    });

    test('typing in input shows clear button', () => {
        const { window } = createWindow();
        const input = window.document.querySelector('#scolta-query');
        const clear = window.document.querySelector('#scolta-search-clear');

        input.value = 'docker';
        input.dispatchEvent(new window.Event('input'));
        expect(clear.style.display).toBe('block');
    });

    test('clearing input hides clear button', () => {
        const { window } = createWindow();
        const input = window.document.querySelector('#scolta-query');
        const clear = window.document.querySelector('#scolta-search-clear');

        input.value = 'test';
        input.dispatchEvent(new window.Event('input'));
        expect(clear.style.display).toBe('block');

        input.value = '';
        input.dispatchEvent(new window.Event('input'));
        expect(clear.style.display).toBe('none');
    });

    test('missing container does not crash', () => {
        // No scolta-search div in DOM.
        const { window } = createWindow('', { container: '#nonexistent' });
        expect(window.Scolta).toBeDefined();
    });

    test('double init does not duplicate UI', () => {
        const { window } = createWindow();
        const firstCount = window.document.querySelector('#scolta-search').children.length;
        window.Scolta.init('#scolta-search');
        const secondCount = window.document.querySelector('#scolta-search').children.length;
        expect(secondCount).toBeLessThanOrEqual(firstCount);
    });

    test('custom scoring config accepted without error', () => {
        const { window } = createWindow('<div id="scolta-search"></div>', {
            scoring: { RESULTS_PER_PAGE: 42, TITLE_MATCH_BOOST: 3.5 },
        });
        expect(window.document.querySelector('#scolta-search').children.length).toBeGreaterThan(0);
    });

    test('exact title match boost config accepted without error', () => {
        const { window } = createWindow('<div id="scolta-search"></div>', {
            scoring: { EXACT_TITLE_MATCH_BOOST: 10.0 },
        });
        expect(window.document.querySelector('#scolta-search').children.length).toBeGreaterThan(0);
    });

    test('search button click calls fetch for expand', async () => {
        const { window } = createWindow();
        const input = window.document.querySelector('#scolta-query');
        const btn = window.document.querySelector('#scolta-search-btn');

        input.value = 'docker containers';
        btn.click();

        // Allow async callbacks to run.
        await new Promise(r => setTimeout(r, 100));

        // fetch should have been called (for the expand endpoint).
        expect(window.fetch).toHaveBeenCalled();
        const fetchCall = window.fetch.mock.calls[0];
        // The endpoint URL matches what we configured ('/e').
        expect(fetchCall[0]).toBe('/e');
    });

    test('Enter key in search input triggers search', async () => {
        const { window } = createWindow();
        const input = window.document.querySelector('#scolta-query');

        input.value = 'test query';
        const event = new window.KeyboardEvent('keydown', { key: 'Enter' });
        input.dispatchEvent(event);

        await new Promise(r => setTimeout(r, 100));

        expect(window.fetch).toHaveBeenCalled();
    });

    test('doSearch updates URL with query parameter', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const doSearchBody = jsSource.match(/async function doSearch[\s\S]*?els\.layout\.style\.display/);
        expect(doSearchBody).not.toBeNull();
        expect(doSearchBody[0]).toContain("searchParams.set('q'");
        expect(doSearchBody[0]).toContain('replaceState');
    });

    test('clearSearch removes query from URL', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const clearBody = jsSource.match(/function clearSearch[\s\S]*?queryInput\.focus/);
        expect(clearBody).not.toBeNull();
        expect(clearBody[0]).toContain("searchParams.delete('q'");
        expect(clearBody[0]).toContain('replaceState');
    });

    test('init reads query from URL after Pagefind loads', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const initBody = jsSource.match(/Promise\.all\(\[initPagefind[\s\S]*?Initialized/);
        expect(initBody).not.toBeNull();
        expect(initBody[0]).toContain(".get('q')");
    });

    test('popstate listener registered for back/forward navigation', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        expect(jsSource).toContain('"popstate"');
    });

    test('doSearch sets ?q= parameter via replaceState', async () => {
        const { window } = createWindow();
        const replaceStateSpy = jest.spyOn(window.history, 'replaceState');

        const input = window.document.querySelector('#scolta-query');
        const btn = window.document.querySelector('#scolta-search-btn');

        input.value = 'containers';
        btn.click();

        await new Promise(r => setTimeout(r, 100));

        const calls = replaceStateSpy.mock.calls;
        expect(calls.length).toBeGreaterThan(0);
        const lastUrl = calls[calls.length - 1][2];
        expect(lastUrl).toMatch(/[?&]q=/);
    });

    test('clearSearch removes ?q= from URL', async () => {
        const { window } = createWindow();
        const replaceStateSpy = jest.spyOn(window.history, 'replaceState');

        const input = window.document.querySelector('#scolta-query');
        const btn = window.document.querySelector('#scolta-search-btn');
        const clear = window.document.querySelector('#scolta-search-clear');

        input.value = 'containers';
        btn.click();
        await new Promise(r => setTimeout(r, 100));

        clear.click();
        await new Promise(r => setTimeout(r, 50));

        const calls = replaceStateSpy.mock.calls;
        const lastUrl = calls[calls.length - 1][2];
        expect(lastUrl).not.toMatch(/[?&]q=/);
    });

    test('popstate with ?q= restores search input value', async () => {
        const { window } = createWindow();

        window.history.pushState({}, '', '?q=kubernetes');
        window.dispatchEvent(new window.PopStateEvent('popstate', { state: {} }));

        await new Promise(r => setTimeout(r, 100));

        const input = window.document.querySelector('#scolta-query');
        expect(input.value).toBe('kubernetes');
    });

    test('popstate without ?q= clears search input', async () => {
        const { window } = createWindow();

        const input = window.document.querySelector('#scolta-query');
        const btn = window.document.querySelector('#scolta-search-btn');
        input.value = 'test query';
        btn.click();
        await new Promise(r => setTimeout(r, 100));

        window.history.pushState({}, '', '/');
        window.dispatchEvent(new window.PopStateEvent('popstate', { state: {} }));
        await new Promise(r => setTimeout(r, 50));

        expect(input.value).toBe('');
    });

    test('results from a single site do not add has-filters class', async () => {
        const { window } = createWindow();
        const layout = window.document.querySelector('#scolta-layout');

        const input = window.document.querySelector('#scolta-query');
        const btn = window.document.querySelector('#scolta-search-btn');

        input.value = 'kubernetes';
        btn.click();

        await new Promise(r => setTimeout(r, 100));

        expect(layout.classList.contains('has-filters')).toBe(false);
    });

    test('no-results not shown while expansion is in flight (foreign language query)', async () => {
        const { window } = createWindow();
        const noResults = window.document.querySelector('#scolta-no-results');

        // Hold the expand response pending so we can inspect mid-flight state.
        let resolveExpand;
        window.fetch = jest.fn().mockImplementation(url => {
            if (url !== '/e') {
                return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
            }
            return new Promise(resolve => {
                resolveExpand = () => resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({ terms: [], sort_hint: null }),
                });
            });
        });

        const input = window.document.querySelector('#scolta-query');
        input.value = 'hola mundo';
        window.document.querySelector('#scolta-search-btn').click();

        // Primary search resolves with 0 results; expansion is still pending.
        await new Promise(r => setTimeout(r, 50));
        expect(noResults.style.display).not.toBe('block');

        // Resolve expansion — no valid terms, no results.
        resolveExpand();
        await new Promise(r => setTimeout(r, 50));

        // Now "No Results Found" should appear.
        expect(noResults.style.display).toBe('block');
    });

    test('no-results shown immediately when AI expansion is disabled', async () => {
        const { window } = createWindow('<div id="scolta-search"></div>', {
            scoring: { AI_EXPAND_QUERY: false },
        });
        const noResults = window.document.querySelector('#scolta-no-results');

        const input = window.document.querySelector('#scolta-query');
        input.value = 'hola mundo';
        window.document.querySelector('#scolta-search-btn').click();

        await new Promise(r => setTimeout(r, 50));

        expect(noResults.style.display).toBe('block');
    });
});

// =============================================================================
// Native sort behavioral tests
// =============================================================================

// Patch patchedSource to:
// 1. Record pagefind.search call arguments so tests can verify sort options.
// 2. Expose pagefindSearch for direct invocation.
// 3. Expose instance state (currentSortOverride, allScoredResults).
const nativeSortSource = patchedSource
    .replace(
        'search: function() { return Promise.resolve({ results: [] }); }',
        'search: function(q, opts) { window.__lastPfSearchArgs = { q, opts: opts || null }; return Promise.resolve({ results: [] }); }'
    )
    .replace(
        '// SHARED SEARCH HELPERS',
        '// SHARED SEARCH HELPERS\n  window.__pagefindSearch = pagefindSearch;\n  window.__getState = function() { return { currentSortOverride, allScoredResults }; };'
    );

function createWindowForSort() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;
    win.fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ terms: ['stone', 'gem'], sort_hint: { field: 'price', direction: 'desc' } }),
        text: () => Promise.resolve(''),
        status: 200,
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    win.scrollTo = () => {};
    win.eval(nativeSortSource);
    win.scolta = {
        scoring: {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    };
    win.Scolta.init('#scolta-search');
    return win;
}

describe('native sort behavioral tests', () => {

    test('sort indicator element exists after init', () => {
        const win = createWindowForSort();
        const el = win.document.querySelector('#scolta-sort-indicator');
        expect(el).not.toBeNull();
    });

    test('sort indicator is hidden initially', () => {
        const win = createWindowForSort();
        const el = win.document.querySelector('#scolta-sort-indicator');
        expect(el.style.display).toBe('none');
    });

    test('pagefindSearch passes sort option when sortHint provided', async () => {
        const win = createWindowForSort();
        await new Promise(r => setTimeout(r, 50)); // wait for initPagefind async chain
        await win.__pagefindSearch('expensive stone', {}, { field: 'price', direction: 'desc' });
        expect(win.__lastPfSearchArgs).not.toBeNull();
        expect(win.__lastPfSearchArgs.opts).toMatchObject({ sort: { price: 'desc' } });
    });

    test('pagefindSearch does not pass sort option when sortHint absent', async () => {
        const win = createWindowForSort();
        await new Promise(r => setTimeout(r, 50));
        await win.__pagefindSearch('popular crystals', {});
        expect(win.__lastPfSearchArgs).not.toBeNull();
        expect(win.__lastPfSearchArgs.opts?.sort).toBeUndefined();
    });

    test('pagefindSearch passes ascending sort option correctly', async () => {
        const win = createWindowForSort();
        await new Promise(r => setTimeout(r, 50));
        await win.__pagefindSearch('cheapest stone', {}, { field: 'price', direction: 'asc' });
        expect(win.__lastPfSearchArgs.opts).toMatchObject({ sort: { price: 'asc' } });
    });

    test('pagefindSearch passes filter and sort options together', async () => {
        const win = createWindowForSort();
        await new Promise(r => setTimeout(r, 50));
        // Use win.Set so instanceof check inside the eval'd script works cross-context.
        const filters = { language: new win.Set(['en']) };
        await win.__pagefindSearch('expensive stone', filters, { field: 'price', direction: 'desc' });
        expect(win.__lastPfSearchArgs.opts).toMatchObject({
            filters: { language: 'en' },
            sort: { price: 'desc' },
        });
    });

    test('pagefindSearch passes null sortHint without adding sort option', async () => {
        const win = createWindowForSort();
        await new Promise(r => setTimeout(r, 50));
        await win.__pagefindSearch('tourmaline', {}, null);
        expect(win.__lastPfSearchArgs.opts?.sort).toBeUndefined();
    });
});

// =============================================================================
// mergeResults behavioral tests (JS fallback — no WASM in test environment)
//
// Gap 4a: the existing string-match test only confirmed the source contains
// "sets:" and doesn't contain "original:". These tests exercise the actual
// runtime path: deduplication by URL and score-wins semantics.
// =============================================================================

// Patch the source to expose the private mergeResults function on window so it
// can be called directly. The comment anchor is unique in the file.
const mergeResultsExposedSource = patchedSource.replace(
    '// SHARED SEARCH HELPERS',
    '// SHARED SEARCH HELPERS\n  window.__mergeResults = mergeResults;'
);

function createWindowForMerge() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;
    win.fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
        text: () => Promise.resolve('[]'),
        status: 200,
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    win.scrollTo = () => {};
    win.eval(mergeResultsExposedSource);
    // Initialize the instance — this triggers createInstance(), which is where
    // mergeResults is defined and our window.__mergeResults injection runs.
    win.scolta = {
        scoring: {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    };
    win.Scolta.init('#scolta-search');
    return win;
}

function makeResult(url, score, title = '') {
    return {
        score,
        data: { url, excerpt: '', meta: { title, url } },
    };
}

describe('mergeResults behavioral tests (JS fallback)', () => {

    test('mergeResults is exposed and callable after patching', () => {
        const win = createWindowForMerge();
        expect(typeof win.__mergeResults).toBe('function');
    });

    test('deduplication: overlapping URL appears exactly once in merged output', () => {
        const win = createWindowForMerge();
        const set1 = [makeResult('https://example.com/page-a', 0.9)];
        const set2 = [makeResult('https://example.com/page-a', 0.5)];

        const merged = win.__mergeResults(set1, set2);
        const urls = merged.map(r => r.data.meta?.url || r.data.url);

        expect(urls.filter(u => u === 'https://example.com/page-a').length).toBe(1);
    });

    test('weight: when same URL appears in both sets, score includes cross-list bonus', () => {
        const win = createWindowForMerge();
        const set1 = [makeResult('https://example.com/shared', 0.9)];
        const set2 = [makeResult('https://example.com/shared', 0.4)];

        const merged = win.__mergeResults(set1, set2);
        const shared = merged.find(r => (r.data.meta?.url || r.data.url) === 'https://example.com/shared');

        expect(shared).toBeDefined();
        expect(shared.score).toBe(0.9 + 0.15);
    });

    test('unique URLs from both sets all appear in the merged result', () => {
        const win = createWindowForMerge();
        const set1 = [
            makeResult('https://example.com/a', 0.9),
            makeResult('https://example.com/b', 0.7),
        ];
        const set2 = [
            makeResult('https://example.com/c', 0.8),
            makeResult('https://example.com/d', 0.6),
        ];

        const merged = win.__mergeResults(set1, set2);

        expect(merged.length).toBe(4);
        const urls = new Set(merged.map(r => r.data.meta?.url || r.data.url));
        expect(urls.has('https://example.com/a')).toBe(true);
        expect(urls.has('https://example.com/b')).toBe(true);
        expect(urls.has('https://example.com/c')).toBe(true);
        expect(urls.has('https://example.com/d')).toBe(true);
    });

    test('two-set fixture: overlapping + unique URLs yields correct count and winning score', () => {
        const win = createWindowForMerge();
        const set1 = [
            makeResult('https://example.com/shared', 0.9),
            makeResult('https://example.com/only-in-primary', 0.8),
        ];
        const set2 = [
            makeResult('https://example.com/shared', 0.3),
            makeResult('https://example.com/only-in-expanded', 0.7),
        ];

        const merged = win.__mergeResults(set1, set2);

        // Three distinct URLs.
        expect(merged.length).toBe(3);

        // Shared URL uses the higher score from set1 plus cross-list bonus.
        const shared = merged.find(r => (r.data.meta?.url || r.data.url) === 'https://example.com/shared');
        expect(shared.score).toBe(0.9 + 0.15);

        // Both unique-only URLs are present.
        const urls = new Set(merged.map(r => r.data.meta?.url || r.data.url));
        expect(urls.has('https://example.com/only-in-primary')).toBe(true);
        expect(urls.has('https://example.com/only-in-expanded')).toBe(true);
    });

    test('cross-list results receive additive bonus above max score', () => {
        const win = createWindowForMerge();
        const primaryResults = [
            makeResult('https://example.com/article-a', 0.5, 'Article A'),
            makeResult('https://example.com/article-b', 0.8, 'Article B'),
        ];
        const expandedResults = [
            makeResult('https://example.com/article-a', 0.4, 'Article A'),
            makeResult('https://example.com/article-c', 0.6, 'Article C'),
        ];
        const merged = win.__mergeResults(primaryResults, expandedResults);
        const articleA = merged.find(r => (r.data.meta?.url || r.data.url) === 'https://example.com/article-a');
        expect(articleA.score).toBeGreaterThan(0.5);
        expect(articleA.score).toBe(0.5 + 0.15);
    });

    test('unique results in one set receive no cross-list bonus', () => {
        const win = createWindowForMerge();
        const set1 = [makeResult('https://example.com/only-primary', 0.7, 'Only Primary')];
        const set2 = [makeResult('https://example.com/only-expanded', 0.6, 'Only Expanded')];
        const merged = win.__mergeResults(set1, set2);
        const primary = merged.find(r => (r.data.meta?.url || r.data.url) === 'https://example.com/only-primary');
        const expanded = merged.find(r => (r.data.meta?.url || r.data.url) === 'https://example.com/only-expanded');
        expect(primary.score).toBe(0.7);
        expect(expanded.score).toBe(0.6);
    });

    test('cross-list bonus makes mediocre dual-match beat strong single-match', () => {
        const win = createWindowForMerge();
        const set1 = [
            makeResult('https://example.com/dual', 0.4, 'Dual Match'),
            makeResult('https://example.com/single', 0.5, 'Single Match'),
        ];
        const set2 = [
            makeResult('https://example.com/dual', 0.35, 'Dual Match'),
        ];
        const merged = win.__mergeResults(set1, set2);
        const dual = merged.find(r => (r.data.meta?.url || r.data.url) === 'https://example.com/dual');
        const single = merged.find(r => (r.data.meta?.url || r.data.url) === 'https://example.com/single');
        expect(dual.score).toBeGreaterThan(single.score);
    });
});

// =============================================================================
// Word explosion removal tests
// =============================================================================

describe('expansion phrase preservation (no word explosion)', () => {

    test('multi-word expansion terms are not split into individual word searches', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const relevancePath = jsSource.match(/Relevance path:[\s\S]*?searchAndLoadParallel/);
        expect(relevancePath).not.toBeNull();
        expect(relevancePath[0]).not.toContain('extractSearchTerms(term)');
    });

    test('sort path does not word-explode expansion terms into individual searches', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const sortPath = jsSource.match(/const termSet = new Set\(\[searchQuery\]\);[\s\S]*?\.map\(t => pagefindSearch/);
        expect(sortPath).not.toBeNull();
        expect(sortPath[0]).not.toContain('extractSearchTerms');
    });

    test('highlight term splitting is preserved for display purposes', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const highlightBlock = jsSource.match(/for \(const term of validTerms\)[\s\S]*?allHighlightTerms/);
        expect(highlightBlock).not.toBeNull();
        expect(highlightBlock[0]).toContain('split');
    });
});

// =============================================================================
// EXPAND_PRIMARY_WEIGHT default alignment test
// =============================================================================

describe('config default alignment', () => {

    test('JS fallback EXPAND_PRIMARY_WEIGHT default matches PHP (0.5)', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const matches = jsSource.match(/EXPAND_PRIMARY_WEIGHT:\s*s\.EXPAND_PRIMARY_WEIGHT\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        for (const m of matches) {
            expect(m).toContain('0.5');
        }
    });

    test('CROSS_LIST_BONUS config key exists with default 0.15', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const matches = jsSource.match(/CROSS_LIST_BONUS:\s*s\.CROSS_LIST_BONUS\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        expect(matches.length).toBeGreaterThanOrEqual(1);
        for (const m of matches) {
            expect(m).toContain('0.15');
        }
    });
});
