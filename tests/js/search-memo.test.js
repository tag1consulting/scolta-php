/**
 * Per-cycle Pagefind search memo.
 *
 * Scolta ran the same Pagefind search twice for every query. The primary search
 * in doSearch() and the facet-count search in computeQueryFacetCounts() are
 * byte-identical whenever the user has applied no non-structural facet, which is
 * the common case; on the OR-fallback path computeUnionFacetCounts() repeated
 * every per-term search the result path had just run. On a production-size index
 * that doubled the cost of every query (a 109,308-page Drupal corpus spent
 * 24,516 ms in the primary search and then 11,053 ms in the identical second
 * one), because Pagefind computes per-value counts across every distinct filter
 * value on each search once filters() has loaded the filter chunks.
 *
 * These tests count pagefind.search calls PER QUERY STRING. The correctness
 * boundary is the second describe block: when a real facet is applied,
 * structuralFilters differs from activeFilters, both searches must still run,
 * and a memo that collapsed them would silently change the facet counts.
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
    language: { en: 100, es: 40 },                 // structural — never a user facet
    difficulty: { Beginner: 10, Intermediate: 20, Advanced: 8 },
};

function makeResult(filterObj, id) {
    return {
        id,
        data: () => Promise.resolve({
            url: '/' + id,
            meta: { title: id, url: '/' + id },
            filters: filterObj,
            excerpt: 'excerpt',
            content: 'content',
            locations: [],
        }),
    };
}

function createWindow(mockPagefind, expansionTerms) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;
    // 503 on every endpoint: AI expansion must not add searches of its own.
    // Unless the test is specifically about the expansion pass, which serves the
    // expand endpoint and the entry file the sub-word guard reads its corpus
    // size from.
    window.fetch = expansionTerms
        ? jest.fn((url) => {
            const u = String(url);
            if (u.includes('pagefind-entry.json')) {
                return Promise.resolve({
                    ok: true, status: 200,
                    json: () => Promise.resolve({ languages: { en: { page_count: 100 } } }),
                    text: () => Promise.resolve('{}'),
                });
            }
            if (u === '/e') {
                return Promise.resolve({
                    ok: true, status: 200,
                    json: () => Promise.resolve({ terms: expansionTerms }),
                    text: () => Promise.resolve('{}'),
                });
            }
            return Promise.resolve({
                ok: false, status: 503,
                json: () => Promise.resolve({}), text: () => Promise.resolve(''),
            });
        })
        : jest.fn().mockResolvedValue({
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

// Calls for one query string, ignoring the "" warm-up search initPagefind runs.
const callsFor = (mock, query) => mock.search.mock.calls.filter(c => c[0] === query);

describe('search memo: no active facets', () => {
    test('one typed query issues exactly ONE pagefind.search for the query', async () => {
        // The primary search and the structural-only count search are the same
        // search here (activeFilters is {}, so structuralFilters is {} too).
        const search = jest.fn(() => Promise.resolve({
            results: [makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-1')],
            filters: { difficulty: { Beginner: 1, Intermediate: 0, Advanced: 0 } },
        }));
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock);
        const inst = await ready(window, mock);
        window.document.querySelector('#scolta-query').value = 'fractions';

        await inst.doSearch();
        await settle(window);

        expect(callsFor(mock, 'fractions')).toHaveLength(1);
        // And the counts still landed in the panel, from that single search.
        const count = window.document.querySelector(
            'input[data-scolta-filter-dim="difficulty"][data-scolta-filter-val="Beginner"]'
        );
        expect(count).not.toBeNull();
    });

    test('the memo does not survive into the next cycle', async () => {
        // A second typed query is a new cycle: its own search runs, and re-typing
        // the first query later must search again rather than replay a stale
        // in-memory result.
        const search = jest.fn(() => Promise.resolve({
            results: [makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-1')],
            filters: { difficulty: { Beginner: 1 } },
        }));
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock);
        const inst = await ready(window, mock);

        window.document.querySelector('#scolta-query').value = 'fractions';
        await inst.doSearch();
        await settle(window);
        window.document.querySelector('#scolta-query').value = 'geometry';
        await inst.doSearch();
        await settle(window);
        window.document.querySelector('#scolta-query').value = 'fractions';
        await inst.doSearch();
        await settle(window);

        expect(callsFor(mock, 'geometry')).toHaveLength(1);
        // Two cycles typed 'fractions' → two searches, one per cycle.
        expect(callsFor(mock, 'fractions')).toHaveLength(2);
    });
});

describe('search memo: correctness boundary — a real facet makes the two searches differ', () => {
    test('primary search AND structural-only count search both run', async () => {
        const search = jest.fn(() => Promise.resolve({
            results: [makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-1')],
            filters: { difficulty: { Beginner: 1 } },
        }));
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock);
        const inst = await ready(window, mock);
        window.document.querySelector('#scolta-query').value = 'fractions';

        // difficulty is a user-facing facet: activeFilters carries it, and
        // computeQueryFacetCounts drops it (only SKIP_FILTER_DIMENSIONS survive).
        await inst.doSearch(false, { difficulty: new window.Set(['Beginner']) });
        await settle(window);

        const calls = callsFor(mock, 'fractions');
        expect(calls).toHaveLength(2);
        const shapes = calls.map(c => JSON.stringify((c[1] || {}).filters ?? null));
        // One scoped to the user's facet (the result list), one unscoped (counts).
        expect(shapes).toContain(JSON.stringify({ difficulty: 'Beginner' }));
        expect(shapes).toContain(JSON.stringify(null));
    });

    test('a structural-only filter DOES collapse the pair', async () => {
        // language is in SKIP_FILTER_DIMENSIONS, so structuralFilters keeps it and
        // the two searches are once again identical — exactly one call.
        const search = jest.fn(() => Promise.resolve({
            results: [makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-1')],
            filters: { difficulty: { Beginner: 1 } },
        }));
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock);
        const inst = await ready(window, mock);
        window.document.querySelector('#scolta-query').value = 'fractions';

        await inst.doSearch(false, { language: new window.Set(['en']) });
        await settle(window);

        const calls = callsFor(mock, 'fractions');
        expect(calls).toHaveLength(1);
        expect(calls[0][1].filters).toEqual({ language: 'en' });
    });
});

describe('search memo: the post-expansion facet-count pass', () => {
    // The count pass that runs when expansion lands re-searches the typed query
    // and every seeding expansion term under structural-only filters. When the
    // user has applied no non-structural facet — the common case — those are
    // byte-identical to the searches the expansion pass just ran, so the memo
    // must serve every one of them. Without this assertion the fix would
    // silently cost a second full search pass per expansion term, which is
    // invisible in a correctness test and expensive on a production index.
    test('adds ZERO additional pagefind.search calls per query string', async () => {
        const byQuery = {
            fractions: [makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-1')],
            geometry: [makeResult({ difficulty: 'Advanced', language: 'en' }, 'doc-2')],
            algebra: [makeResult({ difficulty: 'Intermediate', language: 'en' }, 'doc-3')],
        };
        const search = jest.fn((query) => Promise.resolve({
            results: byQuery[query] || [],
            filters: { difficulty: { Beginner: 1, Intermediate: 0, Advanced: 0 } },
        }));
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock, ['geometry', 'algebra']);
        const inst = await ready(window, mock);
        window.document.querySelector('#scolta-query').value = 'fractions';

        await inst.doSearch();
        await settle(window);

        // One search each: primary/count for the typed query, and one per
        // seeding expansion term, shared between the result path and the delta.
        expect(callsFor(mock, 'fractions')).toHaveLength(1);
        expect(callsFor(mock, 'geometry')).toHaveLength(1);
        expect(callsFor(mock, 'algebra')).toHaveLength(1);
        const real = mock.search.mock.calls.filter(c => c[0] !== '');
        expect(real).toHaveLength(3);

        // And the counts did land: doc-2 and doc-3 came from expansion alone.
        const countOf = (val) => {
            const item = [...window.document.querySelectorAll('#scolta-filters .scolta-filter-item')]
                .find(el => el.querySelector(`input[data-scolta-filter-val="${val}"]`));
            return item ? item.querySelector('.scolta-filter-count').textContent.trim() : null;
        };
        expect(countOf('Advanced')).toBe('(1)');
        expect(countOf('Intermediate')).toBe('(1)');
    });
});

describe('search memo: OR fallback', () => {
    test('per-term count searches reuse the per-term searches the result path ran', async () => {
        // The AND query matches nothing → the result path runs one search per
        // meaningful term, then the count path unions the same per-term searches.
        // Six terms used to mean 6 + 6 searches plus the AND; now 6 plus the AND.
        const TERMS = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta'];
        const AND_QUERY = TERMS.join(' ');
        const perTerm = {};
        TERMS.forEach((t, i) => {
            perTerm[t] = [makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-' + i)];
        });
        const search = jest.fn((query) => {
            if (query === AND_QUERY) return Promise.resolve({ results: [], filters: {} });
            return Promise.resolve({ results: perTerm[query] || [], filters: {} });
        });
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock);
        const inst = await ready(window, mock);
        window.document.querySelector('#scolta-query').value = AND_QUERY;

        await inst.doSearch();
        await settle(window);

        // The AND search: primary + the count path's mode probe = one call.
        expect(callsFor(mock, AND_QUERY)).toHaveLength(1);
        // Each term: the result-path fallback search, reused by the count union.
        for (const term of TERMS) {
            expect(callsFor(mock, term)).toHaveLength(1);
        }
        // Total Pagefind searches for this cycle, excluding the "" warm-up.
        const real = mock.search.mock.calls.filter(c => c[0] !== '');
        expect(real).toHaveLength(TERMS.length + 1);
    });

    test('OR-fallback counts still tally the union (memo did not change what counts contain)', async () => {
        // doc-2 is returned by both 'alpha' and 'beta'; the union must count it
        // once. Sharing the memoized search must not change the tally.
        const search = jest.fn((query) => {
            if (query === 'alpha beta') return Promise.resolve({ results: [], filters: {} });
            if (query === 'alpha') {
                return Promise.resolve({
                    results: [
                        makeResult({ difficulty: 'Beginner', language: 'en' }, 'doc-1'),
                        makeResult({ difficulty: 'Intermediate', language: 'en' }, 'doc-2'),
                    ],
                    filters: {},
                });
            }
            if (query === 'beta') {
                return Promise.resolve({
                    results: [
                        makeResult({ difficulty: 'Intermediate', language: 'en' }, 'doc-2'),
                        makeResult({ difficulty: 'Advanced', language: 'en' }, 'doc-3'),
                    ],
                    filters: {},
                });
            }
            return Promise.resolve({ results: [], filters: {} });
        });
        const mock = { init: () => Promise.resolve(), filters: () => Promise.resolve(TAXONOMY), search };
        const window = createWindow(mock);
        const inst = await ready(window, mock);
        window.document.querySelector('#scolta-query').value = 'alpha beta';

        await inst.doSearch();
        await settle(window);

        const countOf = (val) => {
            const item = [...window.document.querySelectorAll('#scolta-filters .scolta-filter-item')]
                .find(el => el.querySelector(`input[data-scolta-filter-val="${val}"]`));
            return item ? item.querySelector('.scolta-filter-count').textContent.trim() : null;
        };
        expect(countOf('Beginner')).toBe('(1)');
        expect(countOf('Intermediate')).toBe('(1)');   // doc-2 counted once, not twice
        expect(countOf('Advanced')).toBe('(1)');
    });
});
