/**
 * Overlapping searches and result order: a search's outcome must not depend
 * on network timing.
 *
 * Phase 1 of doSearch() awaits Pagefind and the fragment loads before it
 * paints, and the abortController stops neither. When a user commits a second
 * query while the first is still loading, the second can finish first, and the
 * first then resolved last: it replaced allScoredResults with its own results
 * under the second query's header and appended them past the second cycle's
 * displayedCount. These tests hold each Pagefind search open until the test
 * releases it, so the older cycle can be made to finish last on purpose.
 *
 * The sort path (an AI sort_hint) used to build its list as each search's
 * fragments finished loading. A stable sort keeps tied documents in insertion
 * order, so documents sharing the sort value came out in network order. The
 * last describe block resolves the same fragments in two different orders and
 * requires the same list both times.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(path.resolve(__dirname, '../../assets/js/scolta.js'), 'utf-8');
const patchedSource = scoltaSource
    .replace(/pagefind\s*=\s*await\s+import\s*\([^)]+\)/, 'pagefind = mockPagefind')
    .replace(
        '// SHARED SEARCH HELPERS',
        '// SHARED SEARCH HELPERS\n  window.__state = function() { return { allScoredResults, usedOrFallback, activeFilters }; };'
    );

function doc(id, meta) {
    return { url: '/' + id, meta: Object.assign({ title: id }, meta || {}), excerpt: id, content: id, locations: [] };
}

function createWindow(mockPagefind, expandResponse) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;
    window.fetch = jest.fn((url) => {
        if (url === '/e' && expandResponse) {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(expandResponse) });
        }
        return Promise.resolve({
            ok: false, status: 503, json: () => Promise.resolve({}), text: () => Promise.resolve(''),
        });
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    window.scrollTo = () => {};
    window.mockPagefind = mockPagefind;
    window.eval(patchedSource);
    window.scolta = {
        scoring: { AI_EXPAND_QUERY: !!expandResponse, AI_SUMMARIZE: false },
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

async function settle(window, rounds = 10) {
    for (let i = 0; i < rounds; i++) {
        await new Promise(r => window.setTimeout(r, 0));
    }
}

/**
 * A Pagefind whose first search for each query in `held` stays pending until
 * release(query) is called. Every other search resolves at once.
 */
function gatedPagefind(docsByQuery, held) {
    const toHold = new Set(held);
    const gates = new Map();
    const search = jest.fn((query) => {
        const results = (docsByQuery[query] || []).map(d => ({ id: d.url, data: () => Promise.resolve(d) }));
        if (!toHold.has(query)) return Promise.resolve({ results, filters: {} });
        toHold.delete(query);
        return new Promise(resolve => gates.set(query, () => resolve({ results, filters: {} })));
    });
    return {
        mock: { init: () => Promise.resolve(), filters: () => Promise.resolve({}), search },
        release(query) {
            const open = gates.get(query);
            if (!open) throw new Error('no pending search for ' + query);
            gates.delete(query);
            open();
        },
    };
}

function paintedTitles(window) {
    return [...window.document.querySelectorAll('#scolta-results .scolta-result-title')]
        .map(el => el.textContent.trim());
}

async function start(window, query, filters) {
    window.document.querySelector('#scolta-query').value = query;
    // Not awaited: the point is a second commit while this one is in flight.
    return window.Scolta.defaultInstance.doSearch(false, filters);
}

describe('a superseded doSearch() cycle that resolves last', () => {
    const OLDER = ['older-1', 'older-2', 'older-3', 'older-4', 'older-5'].map(id => doc(id));
    const NEWER = [doc('newer-1')];

    test('stores and paints only the newer query\'s results', async () => {
        const pf = gatedPagefind({ older: OLDER, newer: NEWER }, ['older']);
        const window = createWindow(pf.mock);
        await settle(window);

        const rendered = [];
        window.document.querySelector('#scolta-results')
            .addEventListener('scolta:results-rendered', e => rendered.push(e.detail.results.map(r => r.data.meta.title)));

        const older = start(window, 'older');
        await settle(window);
        const newer = start(window, 'newer');
        await newer;
        await settle(window);
        expect(paintedTitles(window)).toEqual(['newer-1']);

        // The older search now resolves, after the newer cycle has painted.
        pf.release('older');
        await older;
        await settle(window);

        expect(paintedTitles(window)).toEqual(['newer-1']);
        expect(window.__state().allScoredResults.map(r => r.data.meta.title)).toEqual(['newer-1']);
        expect(window.document.querySelector('#scolta-results-header').textContent).toContain('"newer"');
        expect(window.document.querySelector('#scolta-load-more').style.display).toBe('none');
        // No paint event after the newer one carried an older result.
        expect(rendered.flat().filter(t => t.startsWith('older'))).toEqual([]);
    });

    test('runs no OR fallback, and so never searches with the newer cycle\'s filters', async () => {
        // 'alpha beta' matches nothing as a phrase, which would send it to the
        // OR fallback, one search per term, under whatever filters it reads.
        const pf = gatedPagefind({ 'alpha beta': [], alpha: [doc('a-1')], beta: [doc('b-1')], newer: NEWER }, ['alpha beta']);
        const window = createWindow(pf.mock);
        await settle(window);

        const older = start(window, 'alpha beta');
        await settle(window);
        const newer = start(window, 'newer', { category: new window.Set(['News']) });
        await newer;
        await settle(window);

        pf.release('alpha beta');
        await older;
        await settle(window);

        const perTerm = pf.mock.search.mock.calls.filter(c => c[0] === 'alpha' || c[0] === 'beta');
        expect(perTerm).toEqual([]);
        expect(window.__state().usedOrFallback).toBe(false);
        expect(paintedTitles(window)).toEqual(['newer-1']);
    });

    test('a single search with no overlap still runs its OR fallback and paints it', async () => {
        // Control for the test above: the staleness checks must not cost a
        // current cycle anything.
        const pf = gatedPagefind({ 'alpha beta': [], alpha: [doc('a-1')], beta: [doc('b-1')] }, []);
        const window = createWindow(pf.mock);
        await settle(window);

        await start(window, 'alpha beta');
        await settle(window);

        expect(window.__state().usedOrFallback).toBe(true);
        expect(paintedTitles(window).sort()).toEqual(['a-1', 'b-1']);
    });
});

describe('sort path: documents tied on the sort field keep one order', () => {
    // Every document carries the same date, so the sort decides nothing and
    // the order is whatever the list was built in.
    const SAME_DAY = { date: '2026-04-30' };

    // Each search's fragments resolve after a per-document delay, so the test
    // decides which search's documents finish loading first.
    function delayedPagefind(docsByQuery, delayFor) {
        const search = jest.fn((query) => Promise.resolve({
            filters: {},
            results: (docsByQuery[query] || []).map(d => ({
                id: d.url,
                data: () => new Promise(r => setTimeout(() => r(d), delayFor(d))),
            })),
        }));
        return { init: () => Promise.resolve(), filters: () => Promise.resolve({}), search };
    }

    async function sortedTitles(docsByQuery, delayFor) {
        const window = createWindow(
            delayedPagefind(docsByQuery, delayFor),
            { terms: ['gamma', 'delta'], sort_hint: { field: 'date', direction: 'desc' } }
        );
        await settle(window);
        await start(window, 'trends');
        // The primary search, the expansion fetch and the sort-path loads.
        await new Promise(r => setTimeout(r, 200));
        await settle(window);
        return window.__state().allScoredResults.map(r => r.data.meta.title);
    }

    // Both list sizes: under 20 dated documents the sort path re-runs the
    // searches unsorted and rebuilds its list from those; at 20 or more it
    // keeps the sorted searches' list.
    test.each([
        ['under the 20-result threshold (unsorted re-run)', 3],
        ['over the 20-result threshold (sorted searches)', 12],
    ])('%s', async (_label, perTerm) => {
        const docsByQuery = { trends: [doc('t-0', SAME_DAY)], gamma: [], delta: [] };
        for (let i = 0; i < perTerm; i++) {
            docsByQuery.gamma.push(doc('g-' + i, SAME_DAY));
            docsByQuery.delta.push(doc('d-' + i, SAME_DAY));
        }

        const gammaFirst = await sortedTitles(docsByQuery, d => (d.url.startsWith('/g-') ? 1 : 30));
        const deltaFirst = await sortedTitles(docsByQuery, d => (d.url.startsWith('/d-') ? 1 : 30));

        expect(gammaFirst).toHaveLength(1 + 2 * perTerm);
        expect(deltaFirst).toEqual(gammaFirst);
        // And the order is the searches' own: the typed query first, then each
        // expansion term in turn, each in Pagefind's order.
        expect(gammaFirst.slice(0, 2)).toEqual(['t-0', 'g-0']);
    });
});
