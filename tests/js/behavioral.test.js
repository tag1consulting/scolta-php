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

    test('quoted forced-phrase query never calls the expand endpoint', async () => {
        // The user asked for the exact phrase; expansion terms would seed
        // documents that matched something other than that phrase, so the
        // expand call is skipped entirely (like the OR fallback already is).
        const { window } = createWindow();
        const input = window.document.querySelector('#scolta-query');
        const btn = window.document.querySelector('#scolta-search-btn');

        input.value = '"docker containers"';
        btn.click();

        await new Promise(r => setTimeout(r, 100));

        const expandCalls = window.fetch.mock.calls.filter(c => c[0] === '/e');
        expect(expandCalls).toHaveLength(0);
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
        expect(shared.score).toBe(0.9 + 0.05);
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
        expect(shared.score).toBe(0.9 + 0.05);

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
        expect(articleA.score).toBe(0.5 + 0.05);
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
        // The dual-match starts below the single-match; the 0.05 cross-list
        // bonus (current default) flips the ranking when the gap is within the
        // bonus. 0.4 + 0.05 = 0.45 > 0.43.
        const set1 = [
            makeResult('https://example.com/dual', 0.4, 'Dual Match'),
            makeResult('https://example.com/single', 0.43, 'Single Match'),
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

describe('sub-word expansion is frequency-guarded (issue #156)', () => {

    test('relevance path word-explodes expansion terms behind the frequency guard', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const relevancePath = jsSource.match(/async function rankExpansion\([\s\S]*?searchAndLoadParallel/);
        expect(relevancePath).not.toBeNull();
        // Sub-word decomposition is restored...
        expect(relevancePath[0]).toContain('extractSearchTerms(term, instanceStopwords())');
        // ...but only added when the frequency guard passes.
        expect(relevancePath[0]).toContain('await subwordAllowed(word)');
    });

    test('sort path word-explodes expansion terms behind the frequency guard', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const sortPath = jsSource.match(/const termSet = new Set\(\[searchQuery\]\);[\s\S]*?\.map\(t => pagefindSearch/);
        expect(sortPath).not.toBeNull();
        expect(sortPath[0]).toContain('extractSearchTerms(term, instanceStopwords())');
        expect(sortPath[0]).toContain('await subwordAllowed(word)');
    });

    test('the guard measures frequency against EXPAND_SUBWORD_MAX_FREQ with shared filter scope', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const guard = jsSource.match(/async function subwordAllowed\(word\)[\s\S]*?\n    }/);
        expect(guard).not.toBeNull();
        // 0 -> v1.0.0 (no sub-words); >= 1 -> all sub-words.
        expect(guard[0]).toContain('subwordMaxFreq <= 0');
        expect(guard[0]).toContain('subwordMaxFreq >= 1');
        // Numerator and denominator both scope to the filters the pass searches
        // under (the search page passes its active filters):
        // the denominator via cached totals, never a match-all search (which
        // downloads the entire word index; AI-Overview latency fix).
        expect(guard[0]).toContain('subwordCorpusSize(filters)');
        expect(guard[0]).not.toContain('pagefindSearch(null');
        expect(guard[0]).toContain('pagefindSearch(word, filters)');
        expect(jsSource).toContain('subwordGuard(searchQuery, activeFilters)');
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

    test('JS fallback CROSS_LIST_BONUS default matches PHP (0.05)', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const matches = jsSource.match(/CROSS_LIST_BONUS:\s*s\.CROSS_LIST_BONUS\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        expect(matches.length).toBeGreaterThanOrEqual(1);
        for (const m of matches) {
            expect(m).toContain('0.05');
        }
    });

    test('JS fallback TITLE_MATCH_BOOST default matches PHP (2.0)', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const matches = jsSource.match(/TITLE_MATCH_BOOST:\s*s\.TITLE_MATCH_BOOST\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        for (const m of matches) {
            expect(m).toContain('2.0');
        }
    });

    test('JS fallback RECENCY_BOOST_MAX default matches PHP (0.25)', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const matches = jsSource.match(/RECENCY_BOOST_MAX:\s*s\.RECENCY_BOOST_MAX\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        for (const m of matches) {
            expect(m).toContain('0.25');
        }
    });

    test('JS fallback EXPAND_SUBWORD_MAX_FREQ default matches PHP (0.05)', () => {
        const jsSource = fs.readFileSync(
            path.join(__dirname, '../../assets/js/scolta.js'), 'utf-8'
        );
        const matches = jsSource.match(/EXPAND_SUBWORD_MAX_FREQ:\s*s\.EXPAND_SUBWORD_MAX_FREQ\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        expect(matches.length).toBeGreaterThanOrEqual(2); // getConfig + getInstanceConfig
        for (const m of matches) {
            expect(m).toContain('0.05');
        }
    });
});

// =============================================================================
// Sort-drop guard: unmatched subject terms must not silently drop the sort
// =============================================================================
//
// Regression (apollo/terra, 2026-06-09): when the expand response carried
// sort_hint + subject_terms and the subject matched no facet ("posts" on a
// blog, "crystals" in a crystal shop — generic subjects that name the corpus
// itself), the sort was dropped with only a debug log: no badge, no reorder.
// The fix applies the sort unscoped in that case; a facet-matching subject
// still scopes the sort exactly as before.

describe('sort-drop guard: unmatched subject falls back to unscoped sort', () => {

    const sortFlowSource = patchedSource.replace(
        'pagefind = { init: function() { return Promise.resolve(); }, search: function() { return Promise.resolve({ results: [] }); } }',
        'pagefind = {' +
        '  init: function() { return Promise.resolve(); },' +
        '  search: function(q, opts) {' +
        '    (window.__pfCalls = window.__pfCalls || []).push({ q: q, opts: opts || null });' +
        '    return Promise.resolve({ results: (window.__docs || []).map(function(d) { return { data: function() { return Promise.resolve(d); } }; }) });' +
        '  },' +
        '  filters: function() { return Promise.resolve(window.__filters || {}); }' +
        '}'
    ).replace(
        '// SHARED SEARCH HELPERS',
        '// SHARED SEARCH HELPERS\n  window.__getSortState = function() { return { currentSortOverride, allScoredResults, activeFilters, llmAppliedFilters }; };'
    );

    function createSortFlowWindow(expandResponse, pagefindFilters, docs) {
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
            { url: 'https://example.com', runScripts: 'dangerously' }
        );
        const win = dom.window;
        win.__docs = docs;
        win.__filters = pagefindFilters;
        win.fetch = jest.fn().mockImplementation(url => {
            if (url === '/e') {
                return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(expandResponse) });
            }
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve('') });
        });
        win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
        win.scrollTo = () => {};
        win.eval(sortFlowSource);
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

    const CRYSTAL_DOCS = [
        { url: '/amethyst', meta: { title: 'Amethyst', price: '200' }, excerpt: 'purple crystal', content: 'purple quartz crystal' },
        { url: '/quartz', meta: { title: 'Clear Quartz', price: '51' }, excerpt: 'clear crystal', content: 'clear quartz crystal' },
        { url: '/citrine', meta: { title: 'Citrine', price: '480' }, excerpt: 'yellow crystal', content: 'yellow quartz crystal' },
    ];

    async function runSearch(win, query) {
        const input = win.document.querySelector('#scolta-query');
        input.value = query;
        win.document.querySelector('#scolta-search-btn').click();
        // Allow the primary search + expand + sort/merge promise chains to settle.
        await new Promise(r => setTimeout(r, 150));
    }

    test('sort_hint with unmatched generic subject applies the sort and shows the badge', async () => {
        const win = createSortFlowWindow(
            {
                terms: ['crystals', 'quartz', 'gem'],
                sort_hint: { field: 'price', direction: 'asc' },
                subject_terms: ['crystals'],
            },
            { category: { Gemstones: 3 } },  // 'crystals' matches no facet value
            CRYSTAL_DOCS
        );

        await runSearch(win, 'cheapest crystals');

        const state = win.__getSortState();
        expect(state.currentSortOverride).toEqual({ field: 'price', direction: 'asc' });

        // The sort must actually reorder: price ascending.
        const prices = state.allScoredResults.map(r => parseFloat(r.data.meta.price));
        expect(prices).toEqual([51, 200, 480]);

        // Badge visible and dismissible.
        const badge = win.document.querySelector('#scolta-sort-indicator');
        expect(badge.style.display).toBe('block');
        expect(badge.innerHTML).toContain('Sorted by: price');

        // No facet match → no LLM filter applied, no filter badge.
        expect(Object.keys(state.llmAppliedFilters)).toEqual([]);
        expect(win.document.querySelector('#scolta-filter-indicator').style.display).toBe('none');
    });

    test('sort_hint with facet-matching subject keeps the scoped-sort behavior', async () => {
        const win = createSortFlowWindow(
            {
                terms: ['gemstones', 'jewels'],
                sort_hint: { field: 'price', direction: 'desc' },
                subject_terms: ['gemstones'],
            },
            { category: { Gemstones: 3, Tools: 2 } },  // exact match for 'gemstones'
            CRYSTAL_DOCS
        );

        await runSearch(win, 'most expensive gemstones');

        const state = win.__getSortState();
        expect(state.currentSortOverride).toEqual({ field: 'price', direction: 'desc' });

        // Subject scoped the search: the matched facet is applied as a filter.
        expect(state.llmAppliedFilters).toEqual({ category: 'Gemstones' });
        expect([...state.activeFilters.category]).toEqual(['Gemstones']);

        const filterBadge = win.document.querySelector('#scolta-filter-indicator');
        expect(filterBadge.style.display).toBe('block');
        expect(filterBadge.innerHTML).toContain('category');
        expect(filterBadge.innerHTML).toContain('Gemstones');

        // Sort applied within the scope: price descending.
        const prices = state.allScoredResults.map(r => parseFloat(r.data.meta.price));
        expect(prices).toEqual([480, 200, 51]);

        expect(win.document.querySelector('#scolta-sort-indicator').style.display).toBe('block');
    });

    test('no sort_hint shows no sort badge', async () => {
        const win = createSortFlowWindow(
            { terms: ['crystals', 'quartz', 'gem'], sort_hint: null },
            { category: { Gemstones: 3 } },
            CRYSTAL_DOCS
        );

        await runSearch(win, 'crystals');

        const state = win.__getSortState();
        expect(state.currentSortOverride).toBeNull();
        expect(win.document.querySelector('#scolta-sort-indicator').style.display).toBe('none');
    });
});

// =============================================================================
// Retrieval pinning table
// =============================================================================

// What doSearch() ranks for one query of each pipeline path, against a fixed
// corpus (tests/js/retrieval-corpus.js), with the JS fallback scorer. These were
// recorded on the pipeline as it stood before the ranking moved into shared
// stages, so any change to what the search page returns fails here. Scores are
// compared to ten places; the Pagefind searches are compared as a sorted list,
// because the order two parallel searches start in is not part of the contract.
const {
    createCorpusWindow, searchPage, settle,
} = require('./retrieval-corpus');

const STATE_HOOK = '  window.__getState = function() { return { allScoredResults }; };';

const RETRIEVAL_TABLE = [
    {
        name: 'single term',
        query: 'retention',
        expandCalls: 1,
        searches: ['retention'],
        results: [['/retention', 3.4], ['/retention-guide', 2.9], ['/glossary', 0.4]],
    },
    {
        name: 'multi term AND hit',
        query: 'breach notification',
        expandCalls: 1,
        searches: ['breach notification'],
        results: [['/gdpr-breach', 4.2], ['/hipaa-breach', 3.95], ['/breach-response', 1.9], ['/encryption', 0.65], ['/glossary', 0.4]],
    },
    {
        name: 'zero AND hits runs the OR fallback',
        query: 'breach cookies',
        expandCalls: 1,
        searches: ['breach', 'breach cookies', 'cookies'],
        results: [
            ['/cookie', 0.8637866659042688], ['/gdpr-breach', 0.47029161224203325],
            ['/hipaa-breach', 0.44612559999545875], ['/breach-response', 0.4219595877488842],
            ['/fines', 0.1267139598668213], ['/encryption', 0.10254794762024677],
            ['/contractors', 0.0783819353736722], ['/glossary', 0.05421592312709767],
        ],
    },
    {
        // "notification" is too common to admit as a sub-word, "duties" and
        // "personal" are rare enough, and the typed "data" is exempt.
        name: 'expansion with a rejected and an admitted sub-word',
        query: 'data breach',
        scoring: { EXPAND_SUBWORD_MAX_FREQ: 0.2 },
        expansions: { 'data breach': ['personal data breach', 'notification duties'] },
        expandCalls: 1,
        searches: ['breach', 'data', 'data breach', 'duties', 'notification', 'notification duties', 'personal', 'personal data breach'],
        results: [
            ['/breach-response', 3.75], ['/gdpr-breach', 3.43017992427798],
            ['/encryption', 2.4859772820483528], ['/retention', 0.3069905434294352],
            ['/retention-guide', 0.2821286332221218], ['/hipaa-breach', 0.26023993333068424],
            ['/fines', 0.07391647658897908], ['/pci', 0.0713428727688124],
            ['/contractors', 0.04572279563464211], ['/glossary', 0.031625955157473636],
        ],
    },
    {
        name: 'typed terms lend agreement without seeding',
        query: 'contractors breach',
        scoring: { EXPAND_SUBWORD_MAX_FREQ: 0.2 },
        expansions: { 'contractors breach': ['processors report', 'controller'] },
        expandCalls: 1,
        searches: ['breach', 'contractors', 'contractors breach', 'controller', 'processors', 'processors report', 'report'],
        results: [['/contractors', 9.12211991921348], ['/glossary', 0.10513260206653652]],
    },
    {
        name: 'metadata boosts',
        query: 'breach',
        scoring: { METADATA_BOOSTS: { type: { guide: 3 } } },
        expandCalls: 1,
        searches: ['breach'],
        results: [
            ['/gdpr-breach', 3.4], ['/hipaa-breach', 3.2333333333333334],
            ['/breach-response', 3.066666666666667], ['/encryption', 2.2], ['/fines', 0.9],
            ['/contractors', 0.5666666666666667], ['/glossary', 0.4],
        ],
    },
    {
        name: 'title dedup off',
        query: 'retention policy',
        scoring: { TITLE_DEDUP: false },
        expandCalls: 1,
        searches: ['retention policy'],
        results: [['/retention-guide', 4.4], ['/retention', 3.2]],
    },
    {
        name: 'title dedup on',
        query: 'retention policy',
        scoring: { TITLE_DEDUP: true },
        expandCalls: 1,
        searches: ['retention policy'],
        results: [['/retention-guide', 4.4]],
    },
    {
        // A quoted query never expands, even when the endpoint has terms for it.
        name: 'forced phrase',
        query: '"breach notification"',
        expansions: { '"breach notification"': ['should not be used'] },
        expandCalls: 0,
        searches: ['breach notification'],
        results: [['/gdpr-breach', 1], ['/hipaa-breach', 0.75], ['/breach-response', 0.5], ['/encryption', 0.25], ['/glossary', 0]],
    },
];

function expectRanked(actual, expected) {
    expect(actual.map(r => r[0])).toEqual(expected.map(r => r[0]));
    actual.forEach((r, i) => expect(r[1]).toBeCloseTo(expected[i][1], 10));
}

describe('retrieval pinning table', () => {
    for (const row of RETRIEVAL_TABLE) {
        test(`doSearch: ${row.name}`, async () => {
            const { win, pfCalls, fetchCalls } = createCorpusWindow({
                scoring: row.scoring, expansions: row.expansions, inject: STATE_HOOK,
            });
            win.Scolta.init('#scolta-search');
            await settle();
            pfCalls.length = 0;
            expectRanked(await searchPage(win, row.query), row.results);
            const expands = fetchCalls.filter(c => c.url === '/e');
            expect(expands).toHaveLength(row.expandCalls);
            expands.forEach(c => expect(JSON.parse(c.body)).toEqual({ query: row.query }));
            expect([...pfCalls].sort()).toEqual(row.searches);
        });
    }
});

// =============================================================================
// Scolta.createRetriever(): parity with the search page, and buildContext()
// =============================================================================

async function retrieverFor(row, opts = {}) {
    const made = createCorpusWindow({
        scoring: row.scoring, expansions: row.expansions, container: false, wasm: opts.wasm,
    });
    const retriever = made.win.Scolta.createRetriever();
    await retriever.ready();
    return Object.assign(made, { retriever });
}

describe('Scolta.createRetriever() ranks exactly as the search page does', () => {
    for (const row of RETRIEVAL_TABLE) {
        test(`retrieve: ${row.name}`, async () => {
            const { retriever, fetchCalls } = await retrieverFor(row);
            const out = await retriever.retrieve(row.query);
            expectRanked(out.results.map(r => [r.data.url, r.score]), row.results);
            const expands = fetchCalls.filter(c => c.url === '/e');
            expect(expands).toHaveLength(row.expandCalls);
            expands.forEach(c => expect(JSON.parse(c.body)).toEqual({ query: row.query }));
        });
    }

    test('a retriever made after the search widget on the same page ranks the same', async () => {
        // The second instance on a page reuses Pagefind; it must still learn
        // the corpus size from pagefind-entry.json, which the sub-word guard
        // and specificity weighting rank with, and must not load the facet
        // index a search page is configured to load eagerly.
        const row = RETRIEVAL_TABLE.find(r => r.name.startsWith('expansion with'));
        const { win, fetchCalls } = createCorpusWindow({ scoring: row.scoring, expansions: row.expansions });
        win.scolta.facetMode = 'eager';
        win.Scolta.init('#scolta-search');
        await settle();
        const retriever = win.Scolta.createRetriever();
        await retriever.ready();
        const facetFetches = () => fetchCalls.filter(c => c.url.includes('.facets')).length;
        const before = facetFetches();

        const out = await retriever.retrieve(row.query);

        expectRanked(out.results.map(r => [r.data.url, r.score]), row.results);
        expect(facetFetches()).toBe(before);
    });

    test('planned terms skip the expand-query call and rank the same', async () => {
        const row = RETRIEVAL_TABLE.find(r => r.name.startsWith('expansion with'));
        const { retriever, fetchCalls } = await retrieverFor(row);
        const out = await retriever.retrieve(row.query, { expandedTerms: row.expansions[row.query] });
        expectRanked(out.results.map(r => [r.data.url, r.score]), row.results);
        expect(fetchCalls.filter(c => c.url === '/e')).toHaveLength(0);
        expect(out.expandedTerms).toEqual(row.expansions[row.query]);
    });

    test('reports the match count before the cap and honours limit', async () => {
        const row = RETRIEVAL_TABLE.find(r => r.name === 'multi term AND hit');
        const { retriever } = await retrieverFor(row);
        const out = await retriever.retrieve(row.query, { limit: 2 });
        expect(out.results.map(r => r.data.url)).toEqual(['/gdpr-breach', '/hipaa-breach']);
        expect(out.total).toBe(5);
    });

    test('an aborted signal rejects with an AbortError', async () => {
        const row = RETRIEVAL_TABLE[0];
        const { win, retriever } = await retrieverFor(row);
        const ctl = new win.AbortController();
        ctl.abort();
        await expect(retriever.retrieve(row.query, { signal: ctl.signal })).rejects.toMatchObject({ name: 'AbortError' });
    });

    test('keeps one memo across calls, so a repeated query searches once', async () => {
        const row = RETRIEVAL_TABLE[0];
        const { retriever, pfCalls } = await retrieverFor(row);
        pfCalls.length = 0;
        await retriever.retrieve(row.query);
        await retriever.retrieve(row.query);
        expect(pfCalls.filter(q => q === 'retention')).toHaveLength(1);
    });

    test('terms() drops stop words with the config it was made from', async () => {
        const { retriever } = await retrieverFor({ scoring: { CUSTOM_STOP_WORDS: ['policy'] } });
        expect(retriever.terms('What is the retention policy?')).toEqual(['retention']);
        expect(retriever.terms('Is it in the?')).toEqual([]);
    });
});

describe('retriever.buildContext()', () => {
    const page = (url, title, text, meta) => ({
        data: { url, meta: Object.assign({ title }, meta || {}), excerpt: text, content: text },
        score: 1,
    });
    const words = (n, w = 'word') => Array.from({ length: n }, (_, i) => `${w}${i}`).join(' ');

    async function build(results, options, wasm) {
        const { retriever } = await retrieverFor({}, { wasm });
        return retriever.buildContext(results, options);
    }

    test('splits the tiers and numbers pages across both', async () => {
        const results = Array.from({ length: 9 }, (_, i) => page(`/p${i}`, `Page ${i}`, `Line about page ${i}.`));
        const pages = await build(results, { topN: 3, broadN: 4 });
        expect(pages.map(p => [p.n, p.tier])).toEqual([[1, 1], [2, 1], [3, 1], [4, 2], [5, 2], [6, 2], [7, 2]]);
        expect(pages[0]).toEqual({ n: 1, tier: 1, title: 'Page 0', url: 'https://example.com/p0', excerpt: 'Line about page 0.' });
    });

    test('divides the tier one budget evenly and never below 100 characters', async () => {
        const results = [page('/a', 'A', words(400)), page('/b', 'B', words(400))];
        const even = await build(results, { topChars: 1000 });
        even.forEach(p => expect(p.excerpt.length).toBeLessThanOrEqual(500));
        expect(even[0].excerpt.length).toBeGreaterThan(400);
        const floored = await build(results, { topChars: 50 });
        floored.forEach(p => {
            expect(p.excerpt.length).toBeLessThanOrEqual(100);
            expect(p.excerpt.length).toBeGreaterThan(80);
        });
    });

    test('keeps tier two inside its budget, one line per page cut at a word', async () => {
        const results = [page('/top', 'Top', 'x')]
            .concat(Array.from({ length: 10 }, (_, i) => page(`/b${i}`, `Broad ${i}`, words(80, 'term'))));
        const pages = (await build(results, { topN: 1, broadChars: 600 })).filter(p => p.tier === 2);
        const used = pages.reduce((n, p) => n + p.title.length + p.url.length + p.excerpt.length, 0);
        expect(used).toBeLessThanOrEqual(600);
        expect(pages.length).toBeGreaterThan(0);
        pages.forEach(p => expect(p.excerpt).toMatch(/term\d+$/));
    });

    test('chooses URLs as search does and lists each URL once', async () => {
        const pages = await build([
            page('/one', 'One', 'a'),
            page('/two', 'Two', 'b', { url: 'https://docs.example.org/two' }),
            page('/one', 'One again', 'c'),
            page('/three', 'Three', 'd', { url: '/elsewhere/three' }),
        ]);
        expect(pages.map(p => p.url)).toEqual([
            'https://example.com/one', 'https://docs.example.org/two', 'https://example.com/elsewhere/three',
        ]);
        expect(pages.map(p => p.n)).toEqual([1, 2, 3]);
    });

    test('strips markup and uses the WASM extractor when it is loaded', async () => {
        const { getWasm } = require('./wasm-helper');
        const text = '<p>' + words(300) + ' breach notification within 72 hours ' + words(300, 'tail') + '</p>';
        const pages = await build([page('/w', 'W', text)], { query: 'breach notification', topChars: 400 }, getWasm());
        expect(pages[0].excerpt).not.toContain('<p>');
        expect(pages[0].excerpt).toContain('breach notification');
        expect(pages[0].excerpt.length).toBeLessThanOrEqual(400);
    });
});
