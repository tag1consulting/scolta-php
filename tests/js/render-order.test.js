/**
 * Render ordering: results paint before the facet-count pass.
 *
 * doSearch() used to await computeQueryFacetCounts() and only then render, so the
 * result list the user was waiting for was held behind a second full Pagefind
 * search. On a 109,308-page Drupal corpus the results were ready at 24,558 ms and
 * did not paint until 35,626 ms.
 *
 * Both tests drive a deliberately GATED count search rather than a timer, so the
 * ordering assertion is not timing dependent: while the gate is closed the count
 * pass cannot possibly have resolved, and anything already in the DOM was
 * therefore rendered ahead of it.
 *
 * A user-facing facet is applied so the count search is a genuinely separate
 * Pagefind call (computeQueryFacetCounts drops non-structural dimensions, so its
 * filters differ from the primary search's and the per-cycle search memo
 * correctly misses).
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const jsPath = path.resolve(__dirname, '../../assets/js/scolta.js');
const scoltaSource = fs.readFileSync(jsPath, 'utf-8');
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = mockPagefind'
);

const TAXONOMY = {
    language: { en: 100 },                                       // structural
    difficulty: { Beginner: 10, Intermediate: 20, Advanced: 8 },
};

function makeResult(filterObj, id) {
    return {
        id,
        data: () => Promise.resolve({
            url: '/' + id,
            meta: { title: 'Doc ' + id, url: '/' + id },
            filters: filterObj,
            excerpt: 'excerpt',
            content: 'content',
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
    return window;
}

async function settle(window) {
    for (let i = 0; i < 5; i++) {
        await new Promise(r => window.setTimeout(r, 0));
    }
}

// Let Scolta.init() finish before the test drives a search, then zero the call
// log. init() awaits initPagefind() (which fires the "" warm-up search) and only
// afterwards inspects the URL for ?q=; a doSearch() started before that lands
// writes ?q= via history.replaceState and would be replayed as an auto-search,
// double-counting every call the test is here to count.
async function ready(window, mock) {
    await settle(window);
    mock.search.mockClear();
    return window.Scolta.defaultInstance;
}

const resultCards = (window) =>
    window.document.querySelectorAll('#scolta-results .scolta-result-card').length;

function captureFacetTuples(window) {
    return [...window.document.querySelectorAll('#scolta-filters .scolta-filter-item')].map(item => {
        const input = item.querySelector('input[data-scolta-filter-dim]');
        const countEl = item.querySelector('.scolta-filter-count');
        return {
            dim: input.getAttribute('data-scolta-filter-dim'),
            val: input.getAttribute('data-scolta-filter-val'),
            count: countEl ? countEl.textContent.trim() : null,
        };
    });
}

// A pagefind mock whose UNFILTERED search for `gatedQuery` (the count pass) waits
// on a gate, while every filtered/other search resolves immediately.
function gatedCountMock(gatedQuery, gatedFilters, fastResults) {
    let release;
    const gate = new Promise(r => { release = r; });
    const search = jest.fn((query, opts) => {
        const hasUserFacet = !!(opts && opts.filters && opts.filters.difficulty);
        if (query === gatedQuery && !hasUserFacet) {
            return gate.then(() => ({ results: fastResults, filters: gatedFilters }));
        }
        return Promise.resolve({ results: fastResults, filters: {} });
    });
    return {
        mock: { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search },
        release,
    };
}

describe('render ordering: results paint before the facet-count pass', () => {
    test('result cards are in the DOM while the count search is still pending', async () => {
        const results = [
            makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-1'),
            makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-2'),
        ];
        const { mock, release } = gatedCountMock(
            'fractions', { difficulty: { Beginner: 7, Intermediate: 0, Advanced: 0 } }, results);
        const window = createWindow(mock);
        const inst = await ready(window, mock);
        window.document.querySelector('#scolta-query').value = 'fractions';

        const pending = inst.doSearch(false, { difficulty: new window.Set(['Beginner']) });
        await settle(window);

        // The count search has NOT resolved (its gate is closed), yet the results
        // are already painted. Under the old order this was still "Searching...".
        expect(mock.search.mock.calls.filter(c => c[0] === 'fractions')).toHaveLength(2);
        expect(resultCards(window)).toBe(2);
        expect(window.document.querySelector('#scolta-results').innerHTML)
            .not.toContain('scolta-searching');
        expect(window.document.querySelector('#scolta-results-header').textContent)
            .toContain('2 results');

        // The panel is not repainted in the gap: it holds its previous state
        // (nothing yet, on the first search of the page) rather than flashing.
        expect(captureFacetTuples(window)).toHaveLength(0);

        // Counts land afterwards and fill the panel in.
        release();
        await settle(window);
        await pending;
        expect(captureFacetTuples(window)).toEqual([
            { dim: 'difficulty', val: 'Beginner', count: '(7)' },
        ]);
        // Results are untouched by the count pass.
        expect(resultCards(window)).toBe(2);
    });

    test("a superseded cycle's late counts do not repaint the panel", async () => {
        const staleResults = [makeResult({ difficulty: 'Advanced', language: 'en' }, 'stale-1')];
        const freshResults = [makeResult({ difficulty: 'Beginner', language: 'en' }, 'fresh-1')];
        let release;
        const gate = new Promise(r => { release = r; });
        const search = jest.fn((query, opts) => {
            const hasUserFacet = !!(opts && opts.filters && opts.filters.difficulty);
            if (query === 'stale' && !hasUserFacet) {
                // The abandoned cycle's count pass — resolves last, with counts
                // that must never reach the panel.
                return gate.then(() => ({
                    results: staleResults,
                    filters: { difficulty: { Advanced: 99 } },
                }));
            }
            if (query === 'stale') return Promise.resolve({ results: staleResults, filters: {} });
            return Promise.resolve({
                results: freshResults,
                filters: { difficulty: { Beginner: 4 } },
            });
        });
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock);
        const inst = await ready(window, mock);

        // Cycle 1 ('stale'): results paint, count pass blocks on the gate.
        window.document.querySelector('#scolta-query').value = 'stale';
        const first = inst.doSearch(false, { difficulty: new window.Set(['Beginner']) });
        await settle(window);

        // Cycle 2 ('fresh') supersedes it and completes, counts included.
        window.document.querySelector('#scolta-query').value = 'fresh';
        await inst.doSearch();
        await settle(window);
        const afterFresh = captureFacetTuples(window);
        expect(afterFresh).toEqual([{ dim: 'difficulty', val: 'Beginner', count: '(4)' }]);

        // Now let the abandoned cycle's counts resolve.
        release();
        await settle(window);
        await first;

        // The panel still shows the current query's counts — no Advanced (99).
        expect(captureFacetTuples(window)).toEqual(afterFresh);
    });
});
