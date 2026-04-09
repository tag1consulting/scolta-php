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
});
