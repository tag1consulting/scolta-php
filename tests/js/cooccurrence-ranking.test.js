/**
 * Co-occurrence ranking regression (guards the non-seeding-load change).
 *
 * searchAndLoadParallel used to call data() on EVERY sub-query — the seeding
 * expansion terms AND the non-seeding ones (the user's typed words and
 * agreement-only phrase sub-words) — then discard the non-seeding documents it
 * could not seed. That inflated the per-query loaded-document count (issue #156)
 * and, because the term rank prior is 1 - i/(loaded.length - 1), the size of a
 * non-seeding term's loaded set fed into the agreement magnitude it lent.
 *
 * The fix loads full fragments only for seeding terms and decides the
 * non-seeding contribution from result ids: a non-seeding term's matched ids
 * are intersected against the seeded documents, and each survivor is scored
 * against the already-loaded seeded fragment (never a fresh data() copy).
 * Shrinking each non-seeding term's scored set to its survivors changes
 * loaded.length, which is a ranking effect — this test pins the ranked order so
 * that effect cannot move silently.
 *
 * Corpus (see CORPUS below), typed query "solar power", expansion "renewable":
 *   d1 — the AND primary hit, plus "renewable" + typed "solar" + typed "power"
 *   d2 — "renewable" + typed "solar"
 *   d3 — "renewable" only
 *   d4, d5 — matched ONLY by the non-seeding typed word "solar"
 *
 * Only "renewable" is a seeding term (a single word, so no sub-words); "solar"
 * and "power" are the user's typed words and seed nothing here. "renewable"
 * lists its docs d3, d2, d1, so the best-single-term (base) order alone would be
 * d1 (AND primary), then d3, then d2. Co-occurrence agreement from the typed
 * words lifts d2 (two agreeing axes) above d3 (one axis), giving d1, d2, d3.
 * d4 and d5 are matched only by a non-seeding term, so they must never enter the
 * result set — the invariant the id-set intersection preserves without a load.
 *
 * WASM import fails under JSDOM, so the JS fallback scorer runs; the seeding
 * split, id-set intersection, and agreement accumulation are all in scolta.js,
 * independent of the WASM path. Shipped scoring defaults are used (no
 * SPECIFICITY_COOCCURRENCE / gate overrides) so this tracks real behaviour.
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

const TOTAL_DOCS = 100;
// term -> the doc urls it retrieves, in Pagefind relevance order. Shared urls
// across terms are the co-occurrence signal; d4/d5 belong only to "solar".
const CORPUS = {
    'solar power': ['/d1'],                       // full-query AND: one doc, no OR fallback
    'renewable':   ['/d3', '/d2', '/d1'],         // the only seeding term
    'solar':       ['/d4', '/d2', '/d5', '/d1'],  // typed, non-seeding, discriminating
    'power':       ['/d1'],                        // typed, non-seeding, discriminating
};

function searchFor(query) {
    if (query === null || query === undefined || query === '') {
        // Match-all denominator: never actually loaded, just needs a length.
        const results = [];
        for (let i = 0; i < TOTAL_DOCS; i++) results.push({ id: `/all-${i}`, data: () => Promise.resolve({ url: `/all-${i}`, meta: {}, excerpt: '', content: '', locations: [] }) });
        return { results };
    }
    const urls = CORPUS[query] || [];
    return {
        results: urls.map(u => ({
            id: u,
            // Neutral title/excerpt (no query words) so only the positional prior
            // and co-occurrence agreement drive the score in the fallback scorer.
            data: () => Promise.resolve({
                url: u, meta: { title: u.slice(1) },
                excerpt: 'sample text', content: 'sample text', locations: [],
            }),
        })),
    };
}

const tick = () => new Promise(r => setTimeout(r, 0));

async function runSearch() {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;
    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (q) => Promise.resolve(searchFor(q)),
    };
    window.fetch = jest.fn((url) => {
        const u = String(url);
        if (u.includes('pagefind-entry.json')) {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ languages: { en: { page_count: TOTAL_DOCS } } }), text: () => Promise.resolve('{}') });
        }
        if (u === '/e') {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ terms: ['renewable'] }), text: () => Promise.resolve('{}') });
        }
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve('{}') });
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);
    window.scolta = {
        scoring: { AI_EXPAND_QUERY: true, AI_SUMMARIZE: false },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js', wasmPath: '/wasm.js', siteName: 'Test', container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');
    for (let i = 0; i < 10; i++) await tick();
    window.document.querySelector('#scolta-query').value = 'solar power';
    await window.Scolta.doSearch();
    for (let i = 0; i < 40; i++) await tick();

    return [...window.document.querySelectorAll('#scolta-results .scolta-result-title')]
        .map(a => a.textContent.trim());
}

describe('co-occurrence ranking after skipping non-seeding loads', () => {
    test('multi-axis agreement ranks d2 above d3, and the ranked order is pinned', async () => {
        const titles = await runSearch();
        // d1 (three agreeing axes) > d2 (two axes) > d3 (one axis). d2 above d3
        // is the agreement effect: base-only order would place d3 first (it is
        // "renewable"'s top-listed doc). Locked so the id-set intersection and
        // survivor-scoped agreement magnitude cannot silently move results.
        expect(titles).toEqual(['d1', 'd2', 'd3']);
    });

    test('non-seeding terms never introduce documents of their own', async () => {
        const titles = await runSearch();
        // d4 and d5 are matched only by the non-seeding typed word "solar"; the
        // id-set intersection keeps them out of the result set without ever
        // loading their fragments.
        expect(titles).not.toContain('d4');
        expect(titles).not.toContain('d5');
    });
});
