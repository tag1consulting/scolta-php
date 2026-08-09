/**
 * Stopwords must never be wrapped in <mark> (SML-2790).
 *
 * The primary term path was always clean: extractSearchTerms() filters the
 * query against STOPWORDS plus the site's CUSTOM_STOP_WORDS, so the meaningful
 * terms carry no conjunctions. Two secondary paths reintroduced them, both
 * guarded by `length > 2` alone — which passes "and", "the", "for", "but",
 * "with" and "that":
 *
 *   1. The empty-meaningful fallback, which split the raw query.
 *   2. The expansion decomposition, which splits each AI expansion term into
 *      its constituent words. An expansion term of "reading and writing" put
 *      an "and" on the highlight list, and every excerpt on the page then
 *      showed it marked.
 *
 * These drive the real bundle in JSDOM and assert on the painted markup, so
 * what is pinned is what a user sees rather than the contents of an array.
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

// One document, worded so that a leaked stopword is unmissable: "and" appears
// in the title and twice in the excerpt, next to words that must stay marked.
const DOC = {
    url: '/literacy',
    title: 'Reading and Writing',
    excerpt: 'Reading and writing skills, and the habits behind them.',
};

/**
 * @param {object} opts
 * @param {string[]} opts.expansionTerms Terms the expand endpoint returns.
 * @param {string[]} opts.customStopWords Site-configured extra stopwords.
 */
function createWindow({ expansionTerms = [], customStopWords = [] } = {}) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: () => Promise.resolve({
            results: [{
                id: 'literacy',
                data: () => Promise.resolve({
                    url: DOC.url,
                    excerpt: DOC.excerpt,
                    content: DOC.excerpt,
                    locations: [],
                    meta: { title: DOC.title, url: DOC.url },
                }),
            }],
        }),
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
        if (u.includes('/e')) {
            return Promise.resolve({
                ok: true, status: 200,
                json: () => Promise.resolve({ terms: expansionTerms }),
                text: () => Promise.resolve('{}'),
            });
        }
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
            AI_EXPAND_QUERY: expansionTerms.length > 0,
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

/**
 * Run a search and return the painted results markup once expansion has had
 * time to land and re-render.
 */
async function paintedResults(query, opts) {
    const window = createWindow(opts);
    for (let i = 0; i < 10; i++) await tick(0);
    window.document.querySelector('#scolta-query').value = query;
    await window.Scolta.doSearch();
    for (let i = 0; i < 40; i++) await tick(0);
    return window.document.querySelector('#scolta-results').innerHTML;
}

/** Every word this markup wrapped in <mark>, lowercased and deduplicated. */
function markedWords(html) {
    const marks = html.match(/<mark>([^<]*)<\/mark>/g) || [];
    return [...new Set(marks.map(m => m.replace(/<\/?mark>/g, '').toLowerCase()))];
}

describe('stopwords are never highlighted (SML-2790)', () => {

    test('a stopword inside an expansion term is not marked', async () => {
        const html = await paintedResults('literacy', {
            expansionTerms: ['reading and writing'],
        });

        expect(html).toContain('<mark>');
        expect(markedWords(html)).not.toContain('and');
        expect(html).not.toContain('<mark>and</mark>');
    });

    test('the real words of that same expansion term are still marked', async () => {
        const html = await paintedResults('literacy', {
            expansionTerms: ['reading and writing'],
        });
        const marked = markedWords(html);

        expect(marked).toContain('reading');
        expect(marked).toContain('writing');
    });

    test('a query of nothing but stopwords marks nothing', async () => {
        // extractSearchTerms() finds no meaningful term and falls back to the
        // raw query, which is where the length-only guard used to leak.
        const html = await paintedResults('and the but', {});

        expect(html).not.toContain('<mark>');
    });

    test('the meaningful path still marks the words the user typed', async () => {
        const html = await paintedResults('reading writing', {});
        const marked = markedWords(html);

        expect(marked).toContain('reading');
        expect(marked).toContain('writing');
        expect(marked).not.toContain('and');
    });

    test("a site's custom stop word is not marked, in the query or an expansion", async () => {
        const html = await paintedResults('reading literacy', {
            expansionTerms: ['writing skills'],
            customStopWords: ['reading', 'skills'],
        });
        const marked = markedWords(html);

        expect(marked).not.toContain('reading');
        expect(marked).not.toContain('skills');
        expect(marked).toContain('writing');
    });

    test('punctuation cannot smuggle a stopword onto the highlight list', async () => {
        const html = await paintedResults('literacy', {
            expansionTerms: ['reading, and, writing'],
        });

        expect(markedWords(html)).not.toContain('and');
        expect(markedWords(html)).toContain('writing');
    });
});
