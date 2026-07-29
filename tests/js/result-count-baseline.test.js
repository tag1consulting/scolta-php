/**
 * Result-count regression baseline (issue #156).
 *
 * This is the test whose absence let commit 690a2288 ship a silent recall
 * collapse (the sub-word expansion block was removed and broad-query result
 * counts dropped 4-50x). It drives the REAL scolta.js search/guard against a
 * synthetic pagefind corpus built from real measured corpus frequencies (see
 * tests/fixtures/result-count-baseline.json) and asserts the merged result
 * count stays within a per-demo band.
 *
 * The band flags BOTH directions:
 *   - recall collapse  -> count falls below band.min (sub-words removed / guard 0)
 *   - precision spike   -> count exceeds band.max  (high-frequency noise admitted / guard >= 1)
 *
 * Result count is the number of distinct pagefind URLs in the merged set. The
 * synthetic corpus gives every query/term a disjoint URL space, so the union
 * is deterministic; each loaded term contributes min(freq, MAX_PAGEFIND_RESULTS)
 * rows, matching scolta.js's per-term load cap.
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
const baseline = JSON.parse(fs.readFileSync(
    path.resolve(__dirname, '../fixtures/result-count-baseline.json'), 'utf-8'
));
const MAX_PF = baseline.maxPagefindResults;

const tick = () => new Promise(r => setTimeout(r, 0));

// Run a query through scolta.js against a synthetic corpus and return the
// number of distinct loaded result URLs (the merged result count).
async function countResults(demo, query, queryData, thresholdOverride) {
    const threshold = thresholdOverride ?? demo.threshold;
    const freq = queryData.subwordFreq;
    const loadedUrls = new Set();

    function rowsFor(q) {
        if (q === null || q === undefined || q === '') return demo.totalDocs; // denominator
        if (Object.prototype.hasOwnProperty.call(freq, q)) return freq[q];
        if (q === query) return queryData.primaryCount;
        return 5; // multi-word expansion term (no standalone frequency)
    }

    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;
    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (q) => {
            const n = rowsFor(q);
            const results = [];
            for (let i = 0; i < n; i++) {
                results.push({
                    id: `${q}-${i}`,
                    data: () => {
                        loadedUrls.add(`${q}#${i}`);
                        return Promise.resolve({
                            url: `/${q}/${i}`, meta: { title: `${q} ${i}` },
                            excerpt: '', content: '', locations: [],
                        });
                    },
                });
            }
            return Promise.resolve({ results });
        },
    };
    window.fetch = jest.fn((url) => {
        const u = String(url);
        if (u.includes('pagefind-entry.json')) {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ languages: { en: { page_count: demo.totalDocs } } }), text: () => Promise.resolve('{}') });
        }
        if (u === '/e') {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ terms: queryData.expansionTerms }), text: () => Promise.resolve('{}') });
        }
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve('{}') });
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);
    window.scolta = {
        scoring: { EXPAND_SUBWORD_MAX_FREQ: threshold, AI_EXPAND_QUERY: true, AI_SUMMARIZE: false, MAX_PAGEFIND_RESULTS: MAX_PF },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js', wasmPath: '/wasm.js', siteName: 'Test', container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');
    for (let i = 0; i < 10; i++) await tick();
    window.document.querySelector('#scolta-query').value = query;
    await window.Scolta.doSearch();
    for (let i = 0; i < 30; i++) await tick();
    return loadedUrls.size;
}

describe('result-count baseline regression (issue #156)', () => {
    for (const [demoName, demo] of Object.entries(baseline.demos)) {
        for (const [query, queryData] of Object.entries(demo.queries)) {
            describe(`${demoName} :: "${query}"`, () => {
                test(`stays within baseline band at the shipped threshold (${demo.threshold})`, async () => {
                    const count = await countResults(demo, query, queryData);
                    expect(count).toBeGreaterThanOrEqual(queryData.band.min);
                    expect(count).toBeLessThanOrEqual(queryData.band.max);
                });

                const collapseTest = queryData.expectsRecovery ? test : test.skip;
                collapseTest('threshold 0 (sub-word block removed) collapses below the band — regression detected', async () => {
                    // Reproduces commit 690a2288: removing the sub-word block must
                    // drop the count below the recall floor for demos that recover.
                    const count = await countResults(demo, query, queryData, 0);
                    expect(count).toBeLessThan(queryData.band.min);
                });
            });
        }
    }

    // Spike detection: admitting every sub-word (>=1.0, the pre-v1.0.0 noise
    // behavior) pushes counts above the band.
    test('threshold >= 1 (all sub-words admitted) exceeds the band — noise detected', async () => {
        const recipes = baseline.demos['recipes'];
        const q = 'meatless recipes';
        const count = await countResults(recipes, q, recipes.queries[q], 1.0);
        expect(count).toBeGreaterThan(recipes.queries[q].band.max);
    });

    // Reference corpora must not be broadened: at >=1.0 the count blows past the
    // precision ceiling, proving the band would catch an over-permissive threshold.
    test('reference corpus precision ceiling is guarded', async () => {
        const git = baseline.demos['git-manual'];
        const q = 'stash uncommitted changes';
        const count = await countResults(git, q, git.queries[q], 1.0);
        expect(count).toBeGreaterThan(git.queries[q].band.max);
    });
});

/**
 * The facet-count path's own document-load budget.
 *
 * When AI expansion lands, the counts are recomputed as the typed set plus the
 * documents expansion added, and on an index with no `scolta.facets` artifact
 * that delta is tallied from loaded fragments. The cap is what keeps it
 * proportional: MAX_PAGEFIND_RESULTS per term, the same cap the result path
 * loads under, so the panel describes the documents that actually reached the
 * list. Dropping it would turn a broad expansion term into a whole-match-set
 * fetch — invisible in every correctness assertion, and ruinous on a production
 * corpus where one term can match tens of thousands of pages.
 */
describe('facet-count-path document-load tripwire', () => {
    const TERM_MATCHES = 400;

    async function loadsByQuery() {
        const loads = [];
        const dom = new JSDOM(
            `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
            { url: 'https://example.com', runScripts: 'dangerously' }
        );
        const window = dom.window;
        window.__pfMock = {
            init: () => Promise.resolve(),
            mergeIndex: () => Promise.resolve(),
            filters: () => Promise.resolve({}),
            search: (q) => {
                const n = q === 'widgets' ? 3 : (q === 'gadgets' ? TERM_MATCHES : 0);
                const results = [];
                for (let i = 0; i < n; i++) {
                    results.push({
                        id: `${q}-${i}`,
                        data: () => {
                            loads.push(`${q}-${i}`);
                            return Promise.resolve({
                                url: `/${q}/${i}`,
                                meta: { title: `${q} document number ${i}` },
                                filters: { topic: q },
                                excerpt: '', content: '', locations: [],
                            });
                        },
                    });
                }
                return Promise.resolve({ results, filters: {} });
            },
        };
        window.fetch = jest.fn((url) => {
            const u = String(url);
            if (u.includes('pagefind-entry.json')) {
                return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ languages: { en: { page_count: 10000 } } }), text: () => Promise.resolve('{}') });
            }
            if (u === '/e') {
                return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ terms: ['gadgets'] }), text: () => Promise.resolve('{}') });
            }
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve('{}') });
        });
        window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
        window.scrollTo = () => {};

        window.eval(patchedSource);
        window.scolta = {
            scoring: { AI_EXPAND_QUERY: true, AI_SUMMARIZE: false, MAX_PAGEFIND_RESULTS: MAX_PF },
            endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
            pagefindPath: '/pf.js', wasmPath: '/wasm.js', siteName: 'Test', container: '#scolta-search',
        };
        window.Scolta.init('#scolta-search');
        for (let i = 0; i < 10; i++) await tick();
        window.document.querySelector('#scolta-query').value = 'widgets';
        await window.Scolta.doSearch();
        for (let i = 0; i < 30; i++) await tick();
        return loads;
    }

    test('the expansion delta stays capped per term, and never fetches the whole match set', async () => {
        const loads = await loadsByQuery();
        const distinct = new Set(loads.filter(id => id.startsWith('gadgets-')));

        // The cap applies to the count path exactly as it applies to the result
        // path: 50 of the 400 matches, not 400.
        expect(distinct.size).toBe(MAX_PF);
        expect(TERM_MATCHES).toBeGreaterThan(MAX_PF);
        // Each delta document is fetched at most once by the result path and
        // once by the count path (Pagefind serves the second from cache).
        const total = loads.filter(id => id.startsWith('gadgets-')).length;
        expect(total).toBeLessThanOrEqual(2 * MAX_PF);
    });
});

/**
 * The same guard, one order of magnitude tighter, for the suggest path.
 *
 * A committed search may load MAX_PAGEFIND_RESULTS fragments per term because
 * the user asked for it and waits once. A suggest cycle runs on a keystroke
 * timer against the same corpus, so the only acceptable budget is
 * saytMaxSuggestions fragments per pass — and there is no band here, just a
 * ceiling. If a future change routes the suggest path through
 * loadAndScoreSearch() or drops the slice, this is what catches it before a
 * production index turns every keystroke into fifty fragment fetches.
 */
describe('suggest-cycle document-load tripwire', () => {
    async function countSuggestLoads(sayt) {
        const loaded = new Set();
        const dom = new JSDOM(
            `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
            { url: 'https://example.com', runScripts: 'dangerously' }
        );
        const window = dom.window;
        window.__pfMock = {
            init: () => Promise.resolve(),
            mergeIndex: () => Promise.resolve(),
            filters: () => Promise.resolve({}),
            preload: () => Promise.resolve(),
            search: (q) => {
                // 400 matches, the shape a two-letter prefix has on any real
                // corpus. Only the cap decides how many fragments are fetched.
                const results = [];
                if (q !== '') {
                    for (let i = 0; i < 400; i++) {
                        results.push({
                            id: `${q}-${i}`,
                            data: () => {
                                loaded.add(`${q}#${i}`);
                                return Promise.resolve({
                                    url: `/${q}/${i}`,
                                    meta: { title: `${q} document number ${i}` },
                                    excerpt: '', content: '', locations: [],
                                });
                            },
                        });
                    }
                }
                return Promise.resolve({ results });
            },
        };
        window.fetch = jest.fn(() => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ languages: { en: { page_count: 400 } } }),
            text: () => Promise.resolve('{}'),
        }));
        window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
        window.scrollTo = () => {};

        window.eval(patchedSource);
        window.scolta = Object.assign({
            scoring: { AI_EXPAND_QUERY: false, AI_SUMMARIZE: false, MAX_PAGEFIND_RESULTS: MAX_PF },
            endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
            pagefindPath: '/pf.js', wasmPath: '/wasm.js', siteName: 'Test',
            container: '#scolta-search',
            saytDebounceMs: 10,
        }, sayt);
        window.Scolta.init('#scolta-search');
        for (let i = 0; i < 15; i++) await tick();

        const input = window.document.querySelector('#scolta-query');
        input.value = 'gi';
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
        await new Promise(r => setTimeout(r, 80));
        for (let i = 0; i < 20; i++) await tick();

        return loaded.size;
    }

    test('a suggest cycle loads exactly saytMaxSuggestions fragments, not MAX_PAGEFIND_RESULTS', async () => {
        expect(await countSuggestLoads({ saytMaxSuggestions: 6 })).toBe(6);
        expect(MAX_PF).toBeGreaterThan(6);   // the cap that must NOT apply here
    });

    test('the cap tracks the setting', async () => {
        expect(await countSuggestLoads({ saytMaxSuggestions: 2 })).toBe(2);
        expect(await countSuggestLoads({ saytMaxSuggestions: 12 })).toBe(12);
    });
});
