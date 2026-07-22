/**
 * Behavioral test for the AI-summary weak-match context signal.
 *
 * When the full query matches nothing, `doSearch` assembles the result list
 * from the broadened OR fallback and sets `usedOrFallback`. The ranked list
 * already tells the user "no exact matches found, showing partial matches",
 * but the summarizer used to receive that same fallback slice with no marker
 * at all — so it generalized a thin, off-target slice into a claim about the
 * whole corpus ("this collection has no dedicated article on X").
 *
 * `summarizeResults` must prepend a weak-match line to the context header in
 * that case, and must not emit it on a normal (exact-match) search. The
 * summarize prompt keys off this marker; see the prompt-side guard in
 * scolta-core `test_summarize_understands_the_weak_match_context_signal`.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = { init: function() { return Promise.resolve(); }, search: function() { return Promise.resolve({ results: [] }); } }'
);

// Expose the private summarize entry point and a setter for the module-scoped
// fallback flag, mirroring the __mergeResults / __selectSummaryCandidates pattern.
const exposedSource = patchedSource.replace(
    '// SHARED SEARCH HELPERS',
    '// SHARED SEARCH HELPERS\n'
    + '  window.__summarizeResults = summarizeResults;\n'
    + '  window.__setUsedOrFallback = function (v) { usedOrFallback = v; };'
);

function createWin() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;
    win.fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ summary: 'ok' }),
        text: () => Promise.resolve('{}'),
        status: 200,
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    win.scrollTo = () => {};
    win.eval(exposedSource);
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

function makeResults() {
    return [
        {
            score: 1,
            data: {
                url: '/a',
                content: 'Some body text about a thing.',
                meta: { title: 'A', url: '/a' },
            },
        },
    ];
}

/** Pull the `context` field out of the POST body sent to the summarize endpoint. */
function summarizeContext(win) {
    const call = win.fetch.mock.calls.find(
        (c) => typeof c[0] === 'string' && c[0].includes('/s')
    );
    expect(call).toBeDefined();
    return JSON.parse(call[1].body).context;
}

const MARKER = '[No result matched the full query;';

describe('AI-summary weak-match signal', () => {
    test('marks the context when results came from the OR fallback', async () => {
        const win = createWin();
        win.__setUsedOrFallback(true);
        await win.__summarizeResults('apollo 13 crisis', makeResults(), []);

        const context = summarizeContext(win);
        expect(context).toContain(MARKER);
        expect(context).toContain('not representative of the collection');
    });

    test('does not mark the context on a normal exact-match search', async () => {
        const win = createWin();
        win.__setUsedOrFallback(false);
        await win.__summarizeResults('apollo 13 crisis', makeResults(), []);

        expect(summarizeContext(win)).not.toContain(MARKER);
    });

    test('the marker precedes the excerpts so the model sees it first', async () => {
        const win = createWin();
        win.__setUsedOrFallback(true);
        await win.__summarizeResults('apollo 13 crisis', makeResults(), []);

        const context = summarizeContext(win);
        expect(context.indexOf(MARKER)).toBeLessThan(context.indexOf('Some body text'));
    });
});
