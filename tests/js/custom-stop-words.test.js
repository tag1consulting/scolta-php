/**
 * CUSTOM_STOP_WORDS JS/WASM consistency (issue #156 follow-up).
 *
 * extractSearchTerms() previously filtered against only the built-in STOPWORDS
 * set, so JS query tokenization ignored customStopWords while the WASM scorer
 * honored it. It now strips the union of STOPWORDS and CONFIG.CUSTOM_STOP_WORDS.
 *
 * extractSearchTerms is closure-internal (not exported), so we assert the fix
 * end-to-end: a custom stop word in the query must NOT appear in the primary
 * Pagefind search query string. We record every search() query the engine runs.
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
    'pagefind = global.__pfMock'
);

const TOTAL_DOCS = 100;

function createWindow(customStopWords, searchedQueries) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (query) => {
            if (query !== null && query !== undefined && query !== '') {
                searchedQueries.push(query);
            }
            const results = [];
            for (let i = 0; i < 5; i++) {
                results.push({
                    id: `${query}-${i}`,
                    data: () => Promise.resolve({
                        url: `/${query}/${i}`,
                        meta: { title: `${query} ${i}` },
                        excerpt: '', content: '', locations: [],
                    }),
                });
            }
            return Promise.resolve({ results });
        },
    };

    window.fetch = jest.fn((url) => {
        const u = String(url);
        if (u.includes('pagefind-entry.json')) {
            return Promise.resolve({
                ok: true, status: 200,
                json: () => Promise.resolve({ languages: { en: { page_count: TOTAL_DOCS } } }),
                text: () => Promise.resolve('{}'),
            });
        }
        // No expansion terms — keep the run focused on primary tokenization.
        return Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ terms: [] }),
            text: () => Promise.resolve('{}'),
        });
    });

    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);

    window.scolta = {
        scoring: {
            AI_EXPAND_QUERY: false,
            AI_SUMMARIZE: false,
            CUSTOM_STOP_WORDS: customStopWords,
        },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        wasmPath: '/wasm.js',
        siteName: 'Test',
        container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');
    return window;
}

const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

async function primarySearchQuery(customStopWords, query) {
    const searchedQueries = [];
    const window = createWindow(customStopWords, searchedQueries);
    for (let i = 0; i < 10; i++) await tick(0);
    const input = window.document.querySelector('#scolta-query');
    input.value = query;
    await window.Scolta.doSearch();
    for (let i = 0; i < 20; i++) await tick(0);
    return searchedQueries[0];
}

describe('customStopWords applied in JS query tokenization', () => {
    test('a custom stop word is stripped from the primary search query', async () => {
        const q = await primarySearchQuery(['desserts'], 'chocolate desserts');
        expect(q).toBe('chocolate');
    });

    test('without the custom stop word, the term is retained', async () => {
        const q = await primarySearchQuery([], 'chocolate desserts');
        expect(q).toBe('chocolate desserts');
    });
});
