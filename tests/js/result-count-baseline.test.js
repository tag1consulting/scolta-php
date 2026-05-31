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
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ languages: { en: {} } }), text: () => Promise.resolve('{}') });
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
