/**
 * Pagefind index-chunk preloading (scolta-php#191).
 *
 * Typing into the search box warms the alphabetical index chunk(s) for the
 * term via pagefind.preload(), so the search that fires on Enter finds them
 * already resolved. These tests drive the real input handler in JSDOM against
 * a Pagefind mock whose preload() is a spy.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Must stay in sync with PRELOAD_DEBOUNCE_MS in scolta.js.
const DEBOUNCE_MS = 150;

// Patch the dynamic import so `pagefind` resolves to a per-window mock that
// the test controls (window.__pagefindMock), rather than a fixed literal.
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = window.__pagefindMock'
);

/**
 * Build a Pagefind mock. Pass `withPreload: false` to simulate an index built
 * by a Pagefind release that predates preload().
 */
function makePagefindMock({ withPreload = true, preloadImpl = null } = {}) {
    const mock = {
        init: jest.fn().mockResolvedValue(undefined),
        search: jest.fn().mockResolvedValue({ results: [] }),
        filters: jest.fn().mockResolvedValue({}),
        mergeIndex: jest.fn().mockResolvedValue(undefined),
    };
    if (withPreload) {
        mock.preload = jest.fn(preloadImpl || (() => Promise.resolve()));
    }
    return mock;
}

/**
 * Create a JSDOM window with scolta.js initialized against `pagefindMock`.
 */
async function createWindow(pagefindMock) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );

    const window = dom.window;
    window.__pagefindMock = pagefindMock;

    window.fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
        text: () => Promise.resolve('[]'),
        status: 200,
    });

    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    window.scrollTo = () => {};

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

    // Let initPagefind() run far enough to assign the module.
    await tick(0);

    return window;
}

/** Real-timer wait — scolta.js schedules on the JSDOM window's setTimeout. */
function tick(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Type `value` into the search box, firing the real input event. */
function type(window, value) {
    const input = window.document.querySelector('#scolta-query');
    input.value = value;
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
}

describe('pagefind index chunk preloading', () => {

    test('typing a term preloads it after the debounce', async () => {
        const pf = makePagefindMock();
        const window = await createWindow(pf);

        type(window, 'kamado');
        expect(pf.preload).not.toHaveBeenCalled();

        await tick(DEBOUNCE_MS + 50);
        expect(pf.preload).toHaveBeenCalledTimes(1);
        expect(pf.preload).toHaveBeenCalledWith('kamado');
    });

    test('the term is trimmed before preloading', async () => {
        const pf = makePagefindMock();
        const window = await createWindow(pf);

        type(window, '  grill  ');
        await tick(DEBOUNCE_MS + 50);

        expect(pf.preload).toHaveBeenCalledWith('grill');
    });

    test('rapid typing collapses to a single preload of the final term', async () => {
        const pf = makePagefindMock();
        const window = await createWindow(pf);

        for (const value of ['k', 'ka', 'kam', 'kama', 'kamad', 'kamado']) {
            type(window, value);
            await tick(10);
        }
        await tick(DEBOUNCE_MS + 50);

        expect(pf.preload).toHaveBeenCalledTimes(1);
        expect(pf.preload).toHaveBeenCalledWith('kamado');
    });

    test('a single character never triggers a preload', async () => {
        const pf = makePagefindMock();
        const window = await createWindow(pf);

        type(window, 'k');
        await tick(DEBOUNCE_MS + 50);

        expect(pf.preload).not.toHaveBeenCalled();
    });

    test('input that leaves the trimmed term unchanged does not preload again', async () => {
        const pf = makePagefindMock();
        const window = await createWindow(pf);

        type(window, 'grill');
        await tick(DEBOUNCE_MS + 50);
        expect(pf.preload).toHaveBeenCalledTimes(1);

        // Trailing whitespace, or any input event that does not change the
        // trimmed term, is already warm.
        type(window, 'grill ');
        await tick(DEBOUNCE_MS + 50);
        type(window, 'grill');
        await tick(DEBOUNCE_MS + 50);

        expect(pf.preload).toHaveBeenCalledTimes(1);
        expect(pf.preload).toHaveBeenCalledWith('grill');
    });

    test('clearing the search cancels a pending preload', async () => {
        const pf = makePagefindMock();
        const window = await createWindow(pf);

        type(window, 'kamado');
        window.document.querySelector('#scolta-search-clear').click();
        await tick(DEBOUNCE_MS + 50);

        expect(pf.preload).not.toHaveBeenCalled();
    });

    test('a Pagefind build without preload() does not break typing', async () => {
        const pf = makePagefindMock({ withPreload: false });
        const window = await createWindow(pf);

        type(window, 'kamado');
        await tick(DEBOUNCE_MS + 50);

        // No throw, and the clear button still tracked the input.
        expect(window.document.querySelector('#scolta-search-clear').style.display)
            .toBe('block');
        expect(window.console.error).not.toHaveBeenCalled();
    });

    test('a rejected preload is swallowed', async () => {
        const rejection = jest.fn();
        process.on('unhandledRejection', rejection);

        const pf = makePagefindMock({
            preloadImpl: () => Promise.reject(new Error('chunk fetch failed')),
        });
        const window = await createWindow(pf);

        type(window, 'kamado');
        await tick(DEBOUNCE_MS + 50);

        expect(pf.preload).toHaveBeenCalledTimes(1);
        expect(rejection).not.toHaveBeenCalled();
        process.off('unhandledRejection', rejection);
    });

    test('a synchronously throwing preload is swallowed', async () => {
        const pf = makePagefindMock({
            preloadImpl: () => { throw new Error('boom'); },
        });
        const window = await createWindow(pf);

        type(window, 'kamado');
        await expect(tick(DEBOUNCE_MS + 50)).resolves.toBeUndefined();
        expect(pf.preload).toHaveBeenCalledTimes(1);
    });
});
