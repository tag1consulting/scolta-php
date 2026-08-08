/**
 * An empty text query renders the result list (SML-2791).
 *
 * doSearch() used to short-circuit on `if (!query || !pagefind) return;`, so a
 * user who selected a facet with an empty search box got no list at all — not
 * an empty state, nothing, with the previous paint left on screen.
 *
 * Browse and search are one code path that differs only in whether a filter is
 * applied. Pagefind returns the whole corpus for a null term and applies any
 * active filters, so what these pin is that the browse path reaches the same
 * search the query path reaches, that it costs no more than that path costs,
 * and that the transitions in and out of it are clean.
 *
 * The cost assertions are load-bearing rather than decorative. Naming a
 * dimension in a search's filter object makes Pagefind lazily fetch that
 * dimension's .pf_filter chunk, and every later search on the page then costs
 * `matched results x loaded postings` for the life of the page — the entire
 * cost the scolta.facets artifact exists to remove. So these run against the
 * real artifact, the same committed fixture tests/js/facet-index.test.js uses,
 * and assert that a browse with an active facet hands Pagefind no filters and
 * fetches no chunk.
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

/**
 * Distinct multi-word titles: deduplicateByTitle() collapses results whose
 * title word sets overlap heavily, and "Page 0" / "Page 1" reduce to the same
 * single word.
 */
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

/**
 * A Pagefind mock that records every search it is asked to run.
 *
 * A null query matches the whole corpus, the way Pagefind does; a term matches
 * a fixed subset so a query search is distinguishable from a browse.
 */
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
            // A distinct slice for a term, so a query's list is
            // distinguishable from the browse's first page.
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

async function boot(mockPagefind, calls) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: 'http://localhost/search', runScripts: 'outside-only' },
    );
    const { window } = dom;
    const requested = [];

    window.console = Object.assign({}, console, {
        warn: () => {}, log: () => {}, error: () => {}, debug: () => {},
    });

    window.fetch = (url) => {
        requested.push(String(url));
        if (/pagefind-entry\.json/.test(url)) {
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    version: '1.5.0',
                    languages: { en: { hash: 'en_fixture01', wasm: 'en', page_count: 200 } },
                }),
            });
        }
        if (/scolta\.facets/.test(url)) {
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
        scoring: {
            MAX_PAGEFIND_RESULTS: 75, RESULTS_PER_PAGE: 12,
            AI_EXPAND_QUERY: false, AI_SUMMARIZE: false,
        },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
    };

    window.eval(patchedSource);
    await new Promise(r => setTimeout(r, 0));

    window.Scolta.init('#scolta-search');
    for (let i = 0; i < 80; i++) {
        await new Promise(r => setTimeout(r, 5));
        if (window.document.querySelector('#scolta-query')) break;
    }
    await new Promise(r => setTimeout(r, 20));
    // initPagefind() warms the index with pagefind.search(""). That is a
    // pre-existing init step, not a search this file is about, so the record
    // starts clean here.
    calls.queries.length = 0;
    calls.searchOpts.length = 0;
    return { window, requested };
}

async function search(env, text) {
    const input = env.window.document.querySelector('#scolta-query');
    input.value = text;
    input.dispatchEvent(new env.window.Event('input', { bubbles: true }));
    await env.window.Scolta.doSearch(false);
    await new Promise(r => setTimeout(r, 60));
}

async function clickFacet(env, dim, val) {
    const input = env.window.document.querySelector(
        `input[data-scolta-filter-dim="${dim}"][data-scolta-filter-val="${val}"]`);
    expect(input).not.toBeNull();
    input.click();
    await new Promise(r => setTimeout(r, 80));
}

const cards = (window) =>
    window.document.querySelectorAll('#scolta-results .scolta-result-card');

const headerText = (window) =>
    window.document.querySelector('#scolta-results-header').textContent;

const shownUrls = (window) =>
    [...window.document.querySelectorAll('#scolta-results .scolta-result-url')]
        .map(a => a.textContent.trim());

describe('an empty query browses the corpus (SML-2791)', () => {

    test('no text and nothing active renders the full listing', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');

        expect(cards(env.window).length).toBeGreaterThan(0);
        expect(env.window.document.querySelector('#scolta-no-results').style.display)
            .toBe('none');
    });

    test('the browse asks Pagefind for a null term, never an empty string', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');

        expect(calls.queries.length).toBeGreaterThan(0);
        // '' is a term like any other and would match nothing.
        expect(calls.queries.every(q => q !== '')).toBe(true);
        expect(calls.queries.some(q => q === null)).toBe(true);
    });

    test('no text plus an active facet renders the filtered list', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');
        await clickFacet(env, 'topic', 'Fruit');

        const urls = shownUrls(env.window);
        expect(urls.length).toBeGreaterThan(0);
        // Fruit is pages 0-2 in the fixture.
        expect(urls.every(u => isFruit(Number(u.replace('/p', ''))))).toBe(true);
    });

    test('the full listing is wider than the filtered one', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');
        const browsed = shownUrls(env.window).length;

        await clickFacet(env, 'topic', 'Fruit');
        expect(shownUrls(env.window).length).toBeLessThan(browsed);
    });

    test('a browse hands Pagefind no filters and fetches no filter chunk', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');
        await clickFacet(env, 'topic', 'Fruit');

        // Scolta applies facets against the artifact. A filters option here is
        // the .pf_filter chunk fetch this whole design exists to avoid, and
        // pagefind.filters() is the other way to trigger it.
        expect(calls.searchOpts.every(o => !o.filters)).toBe(true);
        expect(calls.filters).toBe(0);
        expect(env.requested.some(u => /pf_filter/.test(u))).toBe(false);
    });

    test('the count header names no query when there is none', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');

        const text = headerText(env.window);
        expect(text).toMatch(/\d+ results?/);
        expect(text).not.toContain('for ""');
        expect(text).not.toContain('for "undefined"');
    });

    test('the browse writes no q parameter', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, 'things');
        expect(env.window.location.search).toContain('q=things');

        await search(env, '');
        expect(env.window.location.search).not.toContain('q=');
    });

    test('typing after a browse transitions to a normal search', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');
        expect(cards(env.window).length).toBeGreaterThan(0);

        await search(env, 'things');
        expect(cards(env.window).length).toBeGreaterThan(0);
        expect(headerText(env.window)).toContain('for "things"');
        expect(calls.queries[calls.queries.length - 1]).toBe('things');
    });

    test('clearing the box returns to the browse list', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, 'things');
        const searched = shownUrls(env.window);
        expect(headerText(env.window)).toContain('for "things"');

        await search(env, '');
        expect(headerText(env.window)).not.toContain('for "things"');
        // The term matched pages 100-119; the browse starts at page 0.
        expect(shownUrls(env.window).length).toBeGreaterThan(0);
        expect(shownUrls(env.window)).not.toEqual(searched);
    });

    test('the result cap and pagination still apply', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');

        // RESULTS_PER_PAGE is 12 against a 200-page corpus, so the first paint
        // is a page rather than the whole listing.
        expect(cards(env.window).length).toBe(12);
        expect(headerText(env.window)).toContain('Showing 12');
        expect(env.window.document.querySelector('#scolta-load-more').style.display)
            .toBe('block');
    });

    test('the cap bounds a browse of a large corpus the way it bounds a search', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');

        // MAX_PAGEFIND_RESULTS is 75: the corpus is 200 and nothing loads more
        // than the cap, so the header can never claim the whole corpus.
        const total = Number(headerText(env.window).match(/^(\d+)/)[1]);
        expect(total).toBeLessThanOrEqual(75);
    });

    test('a browse runs no query expansion', async () => {
        const { mock, calls } = createMockPagefind();
        const env = await boot(mock, calls);
        await search(env, '');

        expect(env.requested.some(u => /\/e$/.test(u))).toBe(false);
    });
});
