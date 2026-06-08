/**
 * Regression tests for four shared scolta.js render bugs.
 *
 * Each test drives the REAL scolta.js inside JSDOM against a synthetic pagefind
 * corpus (the same harness shape as result-count-baseline.test.js) and would
 * have failed before the corresponding fix:
 *
 *   Bug 1 — zero-result state blanked the panel for the whole AI-expansion
 *           round-trip instead of showing an in-progress state then the
 *           "No results found." message.
 *   Bug 2 — header read "1 results" (no singular/plural).
 *   Bug 3 — a quoted-phrase query rendered the header as ""merge conflict"".
 *   Bug 4 — buildLLMContext handed the summarizer duplicate numbered sources
 *           when two results resolved to the same URL.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'), 'utf-8'
);
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = global.__pfMock'
);

const tick = () => new Promise(r => setTimeout(r, 0));
async function ticks(n) { for (let i = 0; i < n; i++) await tick(); }

// Boot the real scolta.js against a synthetic corpus.
//   rowsFor(query)  -> array of { url, title, content, excerpt } for that query
//   config          -> scoring config overrides
//   holdExpand      -> if true, the /expand fetch stays pending until
//                      controls.resolveExpand(body) is called (Bug 1 timing).
function setup({ rowsFor, config = {}, holdExpand = false } = {}) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;
    const summarizeBodies = [];

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (q) => {
            const rows = (rowsFor ? rowsFor(q) : []) || [];
            const results = rows.map((row, i) => ({
                id: `${q}-${i}`,
                data: () => Promise.resolve({
                    url: row.url,
                    meta: Object.assign({ title: row.title }, row.meta || {}),
                    excerpt: row.excerpt || '',
                    content: row.content || '',
                    locations: [],
                }),
            }));
            return Promise.resolve({ results });
        },
    };

    let resolveExpandResp;
    const expandRespPromise = new Promise(res => { resolveExpandResp = res; });

    window.fetch = jest.fn((url, opts) => {
        const u = String(url);
        const respond = (body) => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        });
        if (u.includes('pagefind-entry.json')) {
            return respond({ languages: { en: {} } });
        }
        if (u === '/expand') {
            return holdExpand ? expandRespPromise : respond({ terms: [] });
        }
        if (u === '/summarize') {
            summarizeBodies.push(JSON.parse(opts.body));
            return respond({ summary: 'ok' });
        }
        return respond({});
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);
    window.scolta = {
        scoring: Object.assign({
            AI_EXPAND_QUERY: false,
            AI_SUMMARIZE: false,
            AUTO_LANGUAGE_FILTER: false,
            MAX_PAGEFIND_RESULTS: 30,
        }, config),
        endpoints: { expand: '/expand', summarize: '/summarize', followup: '/followup' },
        pagefindPath: '/pf.js', wasmPath: '/wasm.js',
        siteName: 'Test', container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');

    const $ = sel => window.document.querySelector(sel);
    const visible = el => !!el && window.getComputedStyle(el).display !== 'none';

    async function search(query) {
        await ticks(15);
        $('#scolta-query').value = query;
        const p = window.Scolta.doSearch();
        return p;
    }

    return {
        window, $, visible, summarizeBodies, search,
        resolveExpand: (body = { terms: [] }) => resolveExpandResp(Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        })),
    };
}

describe('Bug 1 — zero-result state always settles on a visible message', () => {
    test('shows an in-progress state (not a blank panel) while expansion is in flight, then "No results found." once it settles empty', async () => {
        const h = setup({
            rowsFor: () => [],                       // primary + expansion both return nothing
            config: { AI_EXPAND_QUERY: true },
            holdExpand: true,
        });

        // Phase 1 has rendered and the expand request is still pending.
        await h.search('rama');
        await ticks(3);

        const results = h.$('#scolta-results');
        const header = h.$('#scolta-results-header');
        const noResults = h.$('#scolta-no-results');

        // Transitional state: neutral in-progress, NOT a blank panel, and NOT a
        // premature "No results found." that would flash away if expansion adds
        // hits. (Before the fix the panel was wiped to empty here.)
        expect(results.textContent).toMatch(/Searching/i);
        expect(h.visible(noResults)).toBe(false);
        expect(header.innerHTML).toBe('');

        // Expansion settles with no new terms -> terminal zero-result state.
        h.resolveExpand({ terms: [] });
        await ticks(30);

        expect(h.visible(noResults)).toBe(true);
        expect(noResults.textContent).toMatch(/No results found/i);
    });
});

describe('Bug 2 — singular/plural result count', () => {
    function headerText(h) { return h.$('#scolta-results-header').textContent; }

    test('exactly one result reads "1 result for"', async () => {
        const h = setup({
            rowsFor: () => [{ url: '/a', title: 'Git Branches', content: 'branch' }],
        });
        await h.search('branch');
        await ticks(20);
        expect(headerText(h)).toContain('1 result for');
        expect(headerText(h)).not.toContain('1 results for');
    });

    test('two results read "2 results for"', async () => {
        const h = setup({
            rowsFor: () => [
                { url: '/a', title: 'Git Branches', content: 'branch' },
                { url: '/b', title: 'Remote Tracking', content: 'branch' },
            ],
        });
        await h.search('branch');
        await ticks(20);
        expect(headerText(h)).toContain('2 results for');
    });
});

describe('Bug 3 — no doubled quotes for a quoted-phrase query', () => {
    function headerText(h) { return h.$('#scolta-results-header').textContent; }

    test('a quoted phrase renders a single quote level', async () => {
        const h = setup({
            rowsFor: () => [{ url: '/a', title: 'Resolving Merge Conflicts', content: 'merge conflict' }],
        });
        await h.search('"merge conflict"');
        await ticks(20);
        expect(headerText(h)).toContain('for "merge conflict"');
        expect(headerText(h)).not.toContain('""');
    });

    test('a bare query still renders correctly quoted', async () => {
        const h = setup({
            rowsFor: () => [{ url: '/a', title: 'Resolving Merge Conflicts', content: 'merge conflict' }],
        });
        await h.search('merge conflict');
        await ticks(20);
        expect(headerText(h)).toContain('for "merge conflict"');
        expect(headerText(h)).not.toContain('""');
    });
});

describe('Bug 4 — AI-summary context dedups duplicate URLs', () => {
    test('two results sharing a URL appear once in the summarizer context', async () => {
        const SHARED = '/git/branching';
        const h = setup({
            // Same URL, deliberately dissimilar titles so deduplicateByTitle does
            // NOT collapse them — both reach buildLLMContext.
            rowsFor: () => [
                { url: SHARED, title: 'Alpha Quickstart Guide', content: 'branch one' },
                { url: SHARED, title: 'Beta Reference Manual', content: 'branch two' },
            ],
            config: { AI_SUMMARIZE: true, AI_SUMMARY_TOP_N: 10 },
        });
        await h.search('branch');
        await ticks(40);

        expect(h.summarizeBodies.length).toBeGreaterThan(0);
        const context = h.summarizeBodies[0].context;
        const absoluteUrl = 'https://example.com' + SHARED;
        const occurrences = context.split(absoluteUrl).length - 1;
        expect(occurrences).toBe(1);
    });
});
