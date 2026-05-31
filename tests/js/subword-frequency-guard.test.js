/**
 * Sub-word frequency guard (issue #156).
 *
 * Multi-word expansion terms are decomposed into their constituent words and
 * each word is added as a standalone search term ONLY when its corpus
 * frequency (pagefind hit count / total indexed docs) is below
 * EXPAND_SUBWORD_MAX_FREQ. These tests execute scolta.js in JSDOM against a
 * recording Pagefind mock with a known corpus, and assert which sub-words
 * actually feed the result merge.
 *
 * Distinguishing signal: a sub-word that passes the guard is searched as a
 * real term, so its result rows have .data() loaded. A blocked sub-word is
 * only *probed* for its frequency (.results.length is read, .data() never is).
 * We record the queries whose .data() was invoked => the words that survived.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Patch the Pagefind dynamic import to use a recording mock from the window.
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = global.__pfMock'
);

const TOTAL_DOCS = 100;
// Corpus frequencies as document counts out of TOTAL_DOCS.
const CORPUS = {
    vegetarian: 3,   // 3%  — passes 0.05
    cuisine: 4,      // 4%  — passes 0.05
    meatless: 2,     // 2%  — passes 0.05
    recipes: 100,    // 100% — blocked at 0.05
    cooking: 60,     // 60%  — blocked at 0.05
    dishes: 28,      // 28%  — blocked at 0.05
};
// Multi-word expansion terms the LLM "returns"; each decomposes to the words above.
const EXPANSION_TERMS = ['vegetarian recipes', 'meatless cuisine', 'cooking dishes'];

function buildResults(query, loadedQueries) {
    // For known corpus words, return exactly that many rows so the guard can
    // compute frequency = count / TOTAL_DOCS. Other queries (multi-word expand
    // terms, the primary query) return a small fixed set.
    let n;
    if (query === null || query === undefined || query === '') {
        n = TOTAL_DOCS; // filter-only search => corpus size (denominator)
    } else if (Object.prototype.hasOwnProperty.call(CORPUS, query)) {
        n = CORPUS[query];
    } else {
        n = 5;
    }
    const results = [];
    for (let i = 0; i < n; i++) {
        results.push({
            id: `${query}-${i}`,
            // .data() is only called for terms that are actually searched and
            // merged — never for frequency probes. Record the query when loaded.
            data: () => {
                loadedQueries.push(query);
                return Promise.resolve({
                    url: `/${query}/${i}`,
                    meta: { title: `${query} ${i}` },
                    excerpt: '',
                    content: '',
                    locations: [],
                });
            },
        });
    }
    return { results };
}

function createWindow(subwordMaxFreq, loadedQueries) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (query) => Promise.resolve(buildResults(query, loadedQueries)),
    };

    // fetch: serve pagefind-entry.json and the expand endpoint.
    window.fetch = jest.fn((url, opts) => {
        const u = String(url);
        if (u.includes('pagefind-entry.json')) {
            return Promise.resolve({
                ok: true, status: 200,
                json: () => Promise.resolve({ languages: { en: { page_count: TOTAL_DOCS } } }),
                text: () => Promise.resolve('{}'),
            });
        }
        if (u === '/e') {
            return Promise.resolve({
                ok: true, status: 200,
                json: () => Promise.resolve({ terms: EXPANSION_TERMS }),
                text: () => Promise.resolve('{}'),
            });
        }
        return Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({}),
            text: () => Promise.resolve('{}'),
        });
    });

    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};
    global.__pfMockWindow = window;

    window.eval(patchedSource);

    window.scolta = {
        scoring: {
            EXPAND_SUBWORD_MAX_FREQ: subwordMaxFreq,
            AI_EXPAND_QUERY: true,
            AI_SUMMARIZE: false,
        },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        wasmPath: '/wasm.js', // import fails in JSDOM => JS fallback scoring (fine)
        siteName: 'Test',
        container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');
    return window;
}

const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

async function runSearch(subwordMaxFreq) {
    const loadedQueries = [];
    const window = createWindow(subwordMaxFreq, loadedQueries);
    // Let initPagefind()/initScoltaWasm() settle so `pagefind` is set.
    for (let i = 0; i < 10; i++) await tick(0);
    const input = window.document.querySelector('#scolta-query');
    input.value = 'chocolate desserts';
    await window.Scolta.doSearch();
    // Let the deferred expand -> mergeExpandedSearchResults chain run.
    for (let i = 0; i < 30; i++) await tick(0);
    return new Set(loadedQueries);
}

describe('sub-word frequency guard (relevance path)', () => {
    test('threshold 0.05: only low-frequency sub-words feed results', async () => {
        const loaded = await runSearch(0.05);
        // Allowed (below 5%): searched as real terms.
        expect(loaded.has('vegetarian')).toBe(true);
        expect(loaded.has('cuisine')).toBe(true);
        expect(loaded.has('meatless')).toBe(true);
        // Blocked (at/above 5%): probed for frequency, never loaded.
        expect(loaded.has('recipes')).toBe(false);
        expect(loaded.has('cooking')).toBe(false);
        expect(loaded.has('dishes')).toBe(false);
        // Multi-word expansion terms themselves are always searched.
        expect(loaded.has('vegetarian recipes')).toBe(true);
    });

    test('threshold 0 reproduces v1.0.0: no sub-words are added', async () => {
        const loaded = await runSearch(0);
        for (const w of Object.keys(CORPUS)) {
            expect(loaded.has(w)).toBe(false);
        }
        // The multi-word expansion terms are still searched.
        expect(loaded.has('meatless cuisine')).toBe(true);
    });

    test('threshold >= 1 reproduces pre-v1.0.0: all sub-words are added', async () => {
        const loaded = await runSearch(1.0);
        for (const w of Object.keys(CORPUS)) {
            expect(loaded.has(w)).toBe(true);
        }
    });
});
