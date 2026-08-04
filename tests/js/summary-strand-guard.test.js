/**
 * A summarize failure before the request must not strand the loading skeleton.
 *
 * The AI summary slot is reserved from the frame the result list paints, so
 * from that moment something has to take the skeleton down again. The search
 * flow's expansion chain ends with an un-awaited `summarizeResults(...)`: the
 * summary is allowed to land after the chain settles, which is correct, but it
 * means nothing is chained onto the promise that call returns.
 *
 * `summarizeResults()` handles its own fetch failures — abort, network,
 * non-2xx — but the work before that fetch (candidate selection, context
 * assembly) sits outside them, and `buildLLMContext()` dereferences
 * `r.data.meta` unguarded. On a malformed result set that throws, and because
 * the function is `async` the throw becomes a rejected promise rather than a
 * synchronous error. Un-chained, that is an unhandled rejection: the expansion
 * chain's own `.catch` never sees it (its callback already returned), so the
 * reserved skeleton shimmered forever with no way back.
 *
 * What is pinned here is that such a failure collapses the slot for the cycle
 * that owns it, and only for that cycle.
 *
 * The throw is injected by patching the bundle source at a named anchor, the
 * same technique this suite already uses to replace the Pagefind import. The
 * shipped code is not modified to make this testable: the reachable version of
 * this failure needs a malformed result the happy-path pipeline will not
 * produce, and forcing one through the pipeline would distort it.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

const CANDIDATE_ANCHOR = 'const topN = selectSummaryCandidates(results, query, CONFIG);';

// Fail fast and loudly if the anchor moves, rather than silently testing
// nothing.
if (!scoltaSource.includes(CANDIDATE_ANCHOR)) {
    throw new Error('summary-strand-guard: pre-fetch anchor not found in scolta.js');
}

const patchedSource = scoltaSource
    .replace(
        /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
        'pagefind = window.__pfMock'
    )
    .replace(
        CANDIDATE_ANCHOR,
        'if (window.__failBeforeFetch) { throw new Error("pre-fetch boom"); }\n    ' + CANDIDATE_ANCHOR
    )
    .replace(
        '// SHARED SEARCH HELPERS',
        '// SHARED SEARCH HELPERS\n'
        + '  window.__summarizeResults = summarizeResults;'
    );

const tick = () => new Promise(r => setTimeout(r, 0));
async function ticks(n) { for (let i = 0; i < n; i++) await tick(); }

function setup({ failBeforeFetch = false } = {}) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;

    win.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        preload: () => Promise.resolve(),
        search: () => Promise.resolve({
            results: [{
                id: 'a-0',
                data: () => Promise.resolve({
                    url: '/a',
                    meta: { title: 'Alpha', url: '/a' },
                    excerpt: 'Alpha excerpt about testing.',
                    content: 'Alpha content about testing and more testing.',
                    filters: {},
                    locations: [],
                }),
            }],
            filters: {},
        }),
    };

    win.fetch = jest.fn((url) => {
        const u = String(url);
        const respond = (body) => Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        });
        if (u.includes('pagefind-entry.json')) return respond({ languages: { en: {} } });
        if (u === '/s') return respond({ summary: 'A resolved summary.' });
        return respond({});
    });

    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    win.scrollTo = () => {};
    win.Element.prototype.scrollIntoView = () => {};

    win.__failBeforeFetch = failBeforeFetch;

    win.eval(patchedSource);
    win.scolta = {
        scoring: {
            AI_SUMMARIZE: true,
            AI_EXPAND_QUERY: false,
            AUTO_LANGUAGE_FILTER: false,
        },
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

const panel = (win) => win.document.querySelector('#scolta-ai-summary');

/** Commit a search and let the whole cycle, including the summarize tail, settle. */
async function runSearch(win) {
    win.document.querySelector('#scolta-query').value = 'testing';
    await win.Scolta.doSearch();
    await ticks(40);
}

describe('summary slot is never stranded by a pre-request failure', () => {
    test('the happy path resolves the summary into the reserved slot', async () => {
        const win = setup();
        await runSearch(win);

        expect(panel(win).className).toContain('scolta-ai-summary');
        expect(panel(win).className).not.toContain('loading');
        expect(panel(win).style.display).toBe('');
        expect(panel(win).textContent).toContain('A resolved summary.');
    });

    test('a throw before the request collapses the slot instead of shimmering forever', async () => {
        const win = setup({ failBeforeFetch: true });
        await runSearch(win);

        // The defect: this used to stay `scolta-ai-summary loading ...` with a
        // reserved height and an animating skeleton, permanently.
        expect(panel(win).className).not.toContain('loading');
        expect(panel(win).style.display).toBe('none');
        expect(panel(win).className).toBe('');
        expect(panel(win).innerHTML).toBe('');
    });

    test('the failure is reported rather than swallowed', async () => {
        const win = setup({ failBeforeFetch: true });
        await runSearch(win);

        const warned = win.console.warn.mock.calls.map(c => String(c[0]));
        expect(warned.some(m => m.includes('[scolta:summarize]'))).toBe(true);
    });

    test('the summarize request is never sent when the failure precedes it', async () => {
        const win = setup({ failBeforeFetch: true });
        await runSearch(win);

        const summarizeCalls = win.fetch.mock.calls.filter(c => String(c[0]) === '/s');
        expect(summarizeCalls).toHaveLength(0);
    });

    test('a stale cycle does not collapse a newer search\'s slot', async () => {
        const win = setup({ failBeforeFetch: true });
        win.document.querySelector('#scolta-query').value = 'testing';

        // Start a failing cycle, then supersede it before its tail runs. The
        // guard keys off the search version, so the abandoned cycle must leave
        // the live one's panel alone.
        const first = win.Scolta.doSearch();
        win.__failBeforeFetch = false;
        const second = win.Scolta.doSearch();
        await Promise.all([first, second]);
        await ticks(40);

        expect(panel(win).style.display).toBe('');
        expect(panel(win).textContent).toContain('A resolved summary.');
    });
});
