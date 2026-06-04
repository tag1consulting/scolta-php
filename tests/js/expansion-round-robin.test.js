/**
 * Behavioral tests for the AI-summary candidate selector (issue #170).
 *
 * `selectSummaryCandidates` chooses which results feed the AI summarizer.
 * Under `relevance_union` (default) it takes the relevance-sorted top-N; under
 * `round_robin` it deals top-K from each expansion sub-query in turn so the
 * summarizer sees breadth across distinct sub-topics instead of only the single
 * largest sub-query.
 *
 * These tests execute the real function in JSDOM by exposing the private
 * selectSummaryCandidates on window, mirroring the __mergeResults pattern.
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

// Expose the private function. The comment anchor is unique in the file and
// sits inside the per-instance scope where selectSummaryCandidates is defined.
const exposedSource = patchedSource.replace(
    '// SHARED SEARCH HELPERS',
    '// SHARED SEARCH HELPERS\n  window.__selectSummaryCandidates = selectSummaryCandidates;'
);

function createWin() {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;
    win.fetch = jest.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
        text: () => Promise.resolve('[]'),
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

/**
 * Build a scored result with expansion provenance stamped on its data, the way
 * searchAndLoadParallel stamps __scoltaSourceTerm after loading each sub-query.
 */
function res(url, score, term) {
    const data = { url, excerpt: '', meta: { title: url, url } };
    if (term) data.__scoltaSourceTerm = term;
    return { score, data };
}

const RR = (extra = {}) => ({
    AI_SUMMARY_TOP_N: 10,
    EXPANSION_COMBINE_MODE: 'round_robin',
    EXPANSION_PER_TERM_TOP_K: 3,
    ...extra,
});

const UNION = (extra = {}) => ({
    AI_SUMMARY_TOP_N: 10,
    EXPANSION_COMBINE_MODE: 'relevance_union',
    EXPANSION_PER_TERM_TOP_K: 3,
    ...extra,
});

// Mock an unequal multi-sub-query expansion: Thai dominates the relevance pool,
// Vietnamese and Indonesian have a few lower-scored matches each. The pool is
// already relevance-sorted (the visible order), so Thai occupies the entire
// top-N under relevance_union.
function unequalPool() {
    const pool = [];
    for (let i = 0; i < 12; i++) pool.push(res(`/thai-${i}`, 0.9 - i * 0.01, 'thai'));
    for (let i = 0; i < 3; i++) pool.push(res(`/viet-${i}`, 0.5 - i * 0.01, 'vietnamese'));
    for (let i = 0; i < 3; i++) pool.push(res(`/indo-${i}`, 0.4 - i * 0.01, 'indonesian'));
    pool.sort((a, b) => b.score - a.score);
    return pool;
}

const termsOf = (cands) =>
    [...new Set(cands.map(r => r.data.__scoltaSourceTerm))];

describe('selectSummaryCandidates (issue #170)', () => {

    test('is exposed and callable', () => {
        const win = createWin();
        expect(typeof win.__selectSummaryCandidates).toBe('function');
    });

    test('relevance_union: top-N is dominated by the single largest sub-query', () => {
        const win = createWin();
        const cands = win.__selectSummaryCandidates(unequalPool(), 'southeast asian dishes', UNION());

        expect(cands.length).toBe(10);
        // Every candidate is Thai — the smaller sub-queries are never seen.
        expect(termsOf(cands)).toEqual(['thai']);
    });

    test('round_robin: candidate set spans more than one sub-query', () => {
        const win = createWin();
        const cands = win.__selectSummaryCandidates(unequalPool(), 'southeast asian dishes', RR());

        expect(cands.length).toBe(10);
        const terms = termsOf(cands);
        expect(terms.length).toBeGreaterThan(1);
        // The two smaller cuisines are now represented, not just Thai.
        expect(terms).toEqual(expect.arrayContaining(['thai', 'vietnamese', 'indonesian']));
    });

    test('round_robin: respects AI_SUMMARY_TOP_N — never exceeds the budget', () => {
        const win = createWin();
        const cands = win.__selectSummaryCandidates(unequalPool(), 'q', RR({ AI_SUMMARY_TOP_N: 6 }));
        expect(cands.length).toBe(6);
    });

    test('round_robin: deals top-K per sub-query, strongest sub-query first', () => {
        const win = createWin();
        // K=2, top-N=6, three buckets → 2 of each, strongest-first ordering.
        const cands = win.__selectSummaryCandidates(
            unequalPool(), 'q', RR({ AI_SUMMARY_TOP_N: 6, EXPANSION_PER_TERM_TOP_K: 2 })
        );
        const terms = cands.map(r => r.data.__scoltaSourceTerm);
        // First two are Thai (highest-scored bucket), then Vietnamese, then Indonesian.
        expect(terms.slice(0, 2)).toEqual(['thai', 'thai']);
        expect(terms.slice(2, 4)).toEqual(['vietnamese', 'vietnamese']);
        expect(terms.slice(4, 6)).toEqual(['indonesian', 'indonesian']);
    });

    test('round_robin: within a bucket, relevance order is preserved', () => {
        const win = createWin();
        const cands = win.__selectSummaryCandidates(unequalPool(), 'q', RR());
        const thai = cands.filter(r => r.data.__scoltaSourceTerm === 'thai');
        const scores = thai.map(r => r.score);
        expect(scores).toEqual([...scores].sort((a, b) => b - a));
    });

    test('round_robin with a single sub-query is identical to relevance_union', () => {
        const win = createWin();
        const pool = [];
        for (let i = 0; i < 15; i++) pool.push(res(`/thai-${i}`, 0.9 - i * 0.01, 'thai'));

        const rr = win.__selectSummaryCandidates(pool, 'q', RR());
        const union = win.__selectSummaryCandidates(pool, 'q', UNION());

        expect(rr.map(r => r.data.url)).toEqual(union.map(r => r.data.url));
    });

    test('unstamped results (no expansion) fall under the original query bucket', () => {
        const win = createWin();
        // Primary-query results carry no __scoltaSourceTerm; they form one bucket
        // keyed by the original query, so round_robin == top-N (backward safe).
        const pool = [];
        for (let i = 0; i < 12; i++) pool.push(res(`/p-${i}`, 0.9 - i * 0.01));
        const cands = win.__selectSummaryCandidates(pool, 'my query', RR());
        expect(cands.length).toBe(10);
        expect(cands.map(r => r.data.url)).toEqual(pool.slice(0, 10).map(r => r.data.url));
    });

    test('round_robin reaches into smaller buckets even when one is huge', () => {
        const win = createWin();
        // One dominant bucket (20) plus two tiny ones (1 each). Round-robin must
        // surface both singletons within the top-N rather than 10 from the big one.
        const pool = [];
        for (let i = 0; i < 20; i++) pool.push(res(`/big-${i}`, 0.9 - i * 0.01, 'big'));
        pool.push(res('/small-a', 0.3, 'alpha'));
        pool.push(res('/small-b', 0.2, 'beta'));
        pool.sort((a, b) => b.score - a.score);

        const cands = win.__selectSummaryCandidates(pool, 'q', RR());
        const terms = termsOf(cands);
        expect(terms).toEqual(expect.arrayContaining(['big', 'alpha', 'beta']));
    });
});
