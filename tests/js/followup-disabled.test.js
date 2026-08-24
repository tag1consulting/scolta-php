/**
 * A deployment with follow-ups turned off renders no follow-up affordance.
 *
 * AI_MAX_FOLLOWUPS is a per-instance setting, and 0 is a supported value:
 * AiEndpointHandler answers every follow-up with a 429 in that case, and
 * submitFollowUp() returns before it ever gets there. The resolved-summary
 * template used to emit the thread and the input regardless, leaving a dead
 * field labelled "0 remaining" that answered nothing when used.
 *
 * What is pinned here: with the limit at 0 neither the thread nor the input
 * is in the DOM at all, and with a positive limit both are, with the counter
 * carrying the configured number.
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

// Same exposure pattern as summary-cls-reservation.test.js: reach the private
// summarize entry point without exporting it.
const exposedSource = patchedSource.replace(
    '// SHARED SEARCH HELPERS',
    '// SHARED SEARCH HELPERS\n'
    + '  window.__summarizeResults = summarizeResults;'
);

function createWin(scoring = {}) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;
    win.fetch = jest.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ summary: 'A useful summary.' }),
        text: () => Promise.resolve('{}'),
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    win.scrollTo = () => {};
    win.Element.prototype.scrollIntoView = () => {};
    win.eval(exposedSource);
    win.scolta = {
        scoring: { AI_SUMMARIZE: true, ...scoring },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
    };
    win.Scolta.init('#scolta-search');
    return win;
}

const makeResults = () => [{
    score: 1,
    data: { url: '/a', content: 'Body text.', meta: { title: 'A', url: '/a' } },
}];

const query = (win, sel) => win.document.querySelector(sel);

describe('follow-up affordance gating on AI_MAX_FOLLOWUPS', () => {
    test('a limit of 0 renders no thread and no input', async () => {
        const win = createWin({ AI_MAX_FOLLOWUPS: 0 });
        await win.__summarizeResults('q', makeResults(), []);

        // The summary itself still renders — only the follow-up UI is gone.
        expect(query(win, '.scolta-ai-summary-text').textContent)
            .toContain('A useful summary.');
        expect(query(win, '#scolta-followup-thread')).toBeNull();
        expect(query(win, '#scolta-followup-input')).toBeNull();
        expect(query(win, '[data-scolta-followup-input]')).toBeNull();
        expect(query(win, '[data-scolta-followup-submit]')).toBeNull();
        expect(query(win, '#scolta-followup-counter')).toBeNull();
        // Not merely hidden: no "0 remaining" text anywhere in the panel.
        expect(query(win, '#scolta-ai-summary').textContent).not.toContain('remaining');
    });

    test('a positive limit renders both, with the configured count', async () => {
        const win = createWin({ AI_MAX_FOLLOWUPS: 2 });
        await win.__summarizeResults('q', makeResults(), []);

        expect(query(win, '#scolta-followup-thread')).not.toBeNull();
        expect(query(win, '#scolta-followup-input')).not.toBeNull();
        expect(query(win, '[data-scolta-followup-input]')).not.toBeNull();
        expect(query(win, '#scolta-followup-counter').textContent).toBe('2 remaining');
    });

    test('the default (unset) limit renders the follow-up input', async () => {
        const win = createWin();
        await win.__summarizeResults('q', makeResults(), []);

        expect(query(win, '#scolta-followup-input')).not.toBeNull();
        expect(query(win, '#scolta-followup-counter').textContent).toBe('3 remaining');
    });
});
