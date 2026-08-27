/**
 * A URL carrying facet state runs on load (SML-2791 follow-up).
 *
 * A browse writes no q parameter — its shareable link carries the f_*
 * parameters alone — but the bootstrap only called doSearch() when a
 * non-empty q was present, so following that link (or any platform-built
 * facet URL) landed on an idle search page. The gate is now "a non-empty q
 * OR at least one f_ parameter": landing on facet state runs the filtered
 * browse the URL names.
 *
 * What deliberately did not change: a bare /search still does nothing on
 * load, and so does /search?q= with an empty value and no facet state. The
 * alternative — a full-corpus browse on every search-page load — stays off;
 * an f_ parameter is explicit intent, an empty page is not.
 *
 * The cost invariant from the browse work carries over: the landing browse
 * filters against the facet artifact, hands Pagefind no filters object, and
 * fetches no .pf_filter chunk. Asserted against the real committed artifact
 * fixture, the same one facet-index.test.js uses.
 */

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = mockPagefind'
);

const FIXTURE_GZ = fs.readFileSync(path.resolve(__dirname, 'fixtures/facet-index.fixture'));

// The fixture corpus, mirrored from FacetIndexWriterTest::fixtureData():
// 200 pages; topic = Fruit(0-2) + Veg(3-199); level = Beginner(even) +
// Advanced(odd); site = OneSite(all 200).
const PAGE_IDS = [];
for (let i = 0; i < 200; i++) {
    PAGE_IDS.push('en_' + crypto.createHash('sha256').update('page-' + i).digest('hex').slice(0, 10));
}

const isFruit = (p) => p < 3;

function resultsForPages(pages) {
    return pages.map(p => ({
        id: PAGE_IDS[p],
        score: 1,
        words: [],
        data: () => Promise.resolve({
            url: '/p' + p,
            content: 'Entry ' + p + ' content about things.',
            word_count: 20,
            excerpt: 'Entry ' + p + ' excerpt.',
            locations: [],
            meta: { title: 'Alpha' + p + ' Bravo' + p + ' Charlie' + p, url: '/p' + p },
            filters: {},
        }),
    }));
}

const ALL_PAGES = Array.from({ length: 200 }, (_, i) => i);

function createMockPagefind() {
    const calls = { filters: 0, searchOpts: [], queries: [] };
    const mock = {
        init: () => Promise.resolve(),
        preload: () => Promise.resolve(),
        filters: () => {
            calls.filters++;
            return Promise.resolve({});
        },
        search: (query, searchOpts) => {
            calls.queries.push(query);
            calls.searchOpts.push(searchOpts || {});
            const matched = (query === null || query === undefined)
                ? ALL_PAGES
                : ALL_PAGES.slice(100, 120);
            return Promise.resolve({
                results: resultsForPages(matched),
                filters: {},
                unfilteredResultCount: matched.length,
            });
        },
    };
    return { mock, calls };
}

/**
 * Boots at the given URL and lets the load bootstrap run to completion.
 *
 * Unlike the browse-no-query harness, the call record is NOT cleared after
 * init: the auto-executed search is the subject here. The one init call that
 * is not — initPagefind()'s warm-up pagefind.search("") — is what the
 * userQueries() helper below strips.
 */
async function boot(mockPagefind, calls, url, scoringExtra) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: url, runScripts: 'outside-only' },
    );
    const { window } = dom;
    const requested = [];

    window.console = Object.assign({}, console, {
        warn: () => {}, log: () => {}, error: () => {}, debug: () => {},
    });

    window.fetch = (u) => {
        requested.push(String(u));
        if (/pagefind-entry\.json/.test(u)) {
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    version: '1.5.0',
                    languages: { en: { hash: 'en_fixture01', wasm: 'en', page_count: 200 } },
                }),
            });
        }
        if (/scolta\.facets/.test(u)) {
            const b = FIXTURE_GZ;
            return Promise.resolve({
                ok: true,
                status: 200,
                arrayBuffer: () => Promise.resolve(
                    b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength)),
            });
        }
        return Promise.resolve({ ok: false, status: 404 });
    };

    // JSDOM ships neither DecompressionStream nor a streaming Response. Node's
    // zlib stands in for the browser's gzip decoder, over the identical bytes.
    window.DecompressionStream = class { constructor(format) { this.format = format; } };
    window.Response = class {
        constructor(body) { this._body = body; }
        get body() {
            const bytes = this._body;
            return { pipeThrough: () => ({ __gunzip: bytes }) };
        }
        arrayBuffer() {
            const src = this._body && this._body.__gunzip ? this._body.__gunzip : this._body;
            const out = zlib.gunzipSync(Buffer.from(src));
            return Promise.resolve(out.buffer.slice(out.byteOffset, out.byteOffset + out.byteLength));
        }
    };
    window.TextDecoder = TextDecoder;
    window.scrollTo = () => {};
    window.mockPagefind = mockPagefind;
    window.scolta = {
        pagefindPath: '/pagefind/pagefind.js',
        scoring: Object.assign({
            MAX_PAGEFIND_RESULTS: 75, RESULTS_PER_PAGE: 12,
            AI_EXPAND_QUERY: false, AI_SUMMARIZE: false,
        }, scoringExtra),
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
    };

    window.eval(patchedSource);
    await new Promise(r => setTimeout(r, 0));

    window.Scolta.init('#scolta-search');
    for (let i = 0; i < 80; i++) {
        await new Promise(r => setTimeout(r, 5));
        if (window.document.querySelector('#scolta-query')) break;
    }
    // The bootstrap fires after Pagefind + WASM init resolve; give the
    // auto-search (when there is one) time to run and paint.
    await new Promise(r => setTimeout(r, 150));
    return { window, requested };
}

/** The search terms doSearch() ran — the init warm-up search("") stripped. */
const userQueries = (calls) => calls.queries.filter(q => q !== '');

const cards = (window) =>
    window.document.querySelectorAll('#scolta-results .scolta-result-card');

const headerText = (window) =>
    window.document.querySelector('#scolta-results-header').textContent;

const shownUrls = (window) =>
    [...window.document.querySelectorAll('#scolta-results .scolta-result-url')]
        .map(a => a.textContent.trim());

async function popstateTo(env, url) {
    env.window.history.pushState({}, '', url);
    env.window.dispatchEvent(new env.window.PopStateEvent('popstate'));
    await new Promise(r => setTimeout(r, 100));
}

describe('a facet-only URL browses on load', () => {

    test('landing on f_ state runs the filtered browse', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?f_topic=Fruit');

        const urls = shownUrls(env.window);
        expect(urls.length).toBeGreaterThan(0);
        // Fruit is pages 0-2 in the fixture.
        expect(urls.every(u => isFruit(Number(u.replace('/p', ''))))).toBe(true);
    });

    test('the landing browse carries a null term, never an empty string', async () => {
        const { mock, calls } = createMockPagefind();
        await boot(mock, calls, 'http://localhost/search?f_topic=Fruit');

        expect(userQueries(calls).length).toBeGreaterThan(0);
        expect(userQueries(calls).some(q => q === null)).toBe(true);
    });

    test('the landing browse hands Pagefind no filters and fetches no filter chunk', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?f_topic=Fruit');

        // Scolta applies facets against the artifact. A filters option here is
        // the .pf_filter chunk fetch this whole design exists to avoid, and
        // pagefind.filters() is the other way to trigger it.
        expect(calls.searchOpts.every(o => !o.filters)).toBe(true);
        expect(calls.filters).toBe(0);
        expect(env.requested.some(u => /pf_filter/.test(u))).toBe(false);
    });

    test('the count header names no query', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?f_topic=Fruit');

        const text = headerText(env.window);
        expect(text).toMatch(/\d+ results?/);
        expect(text).not.toContain('for ""');
        expect(text).not.toContain('for "undefined"');
    });

    test('a bare /search still runs nothing on load', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search');

        expect(userQueries(calls)).toEqual([]);
        expect(cards(env.window).length).toBe(0);
    });

    test('an empty q with no facet state still runs nothing on load', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?q=');

        expect(userQueries(calls)).toEqual([]);
        expect(cards(env.window).length).toBe(0);
    });

    test('q and f_ together restore both', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?q=things&f_topic=Fruit');

        expect(env.window.document.querySelector('#scolta-query').value).toBe('things');
        expect(userQueries(calls)).toContain('things');
    });

    test('popstate to a facet-only state re-runs the filtered browse', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?f_topic=Fruit');
        const before = userQueries(calls).length;

        await popstateTo(env, '/search?f_topic=Fruit');

        expect(userQueries(calls).length).toBeGreaterThan(before);
        const urls = shownUrls(env.window);
        expect(urls.length).toBeGreaterThan(0);
        expect(urls.every(u => isFruit(Number(u.replace('/p', ''))))).toBe(true);
        // The box stays empty and its clear control hidden: this is a browse.
        expect(env.window.document.querySelector('#scolta-query').value).toBe('');
    });

    test('the landing browse requests no summary and reserves no slot', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?f_topic=Fruit',
            { AI_SUMMARIZE: true });

        // No query, nothing to summarize — the endpoint 400s an empty one.
        expect(env.requested.some(u => /\/s$/.test(u))).toBe(false);
        expect(env.window.document.querySelector('#scolta-ai-summary').style.display)
            .toBe('none');
    });

    test('the landing browse renders no expansion toggle', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?f_topic=Fruit',
            { AI_EXPAND_QUERY: true });

        expect(env.window.document.querySelector('[data-scolta-expansion-toggle]'))
            .toBeNull();
    });

    test('popstate to a bare state clears', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls, 'http://localhost/search?f_topic=Fruit');
        const layout = env.window.document.querySelector('#scolta-layout');
        expect(layout.style.display).not.toBe('none');

        await popstateTo(env, '/search');

        // clearSearch() hides the layout rather than emptying it.
        expect(layout.style.display).toBe('none');
        expect(env.window.document.querySelector('#scolta-query').value).toBe('');
    });
});
