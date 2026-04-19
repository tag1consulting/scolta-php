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
});
