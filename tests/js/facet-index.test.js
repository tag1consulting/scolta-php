/**
 * The Scolta facet index: Scolta owns its facet data, and stops feeding
 * Pagefind's filter counter.
 *
 * Pagefind's SearchIndex::get_filters counts by scanning the matched-result set
 * linearly for every (value, page) posting in every LOADED filter chunk, and it
 * runs twice per search. So once any chunk is loaded, every later search costs
 * `matched results x loaded postings`, and nothing unloads it short of
 * pagefind.destroy(). Measured on a 109,308-page corpus carrying 3,208,134
 * postings across ten dimensions, a query matching 7,789 results took 155 ms
 * with no chunk loaded and 18,589 ms with all ten loaded. Reducing distinct
 * values does not help: collapsing a 3,421-value dimension to 4 while keeping
 * every posting moved the cost 10,151 ms -> 10,178 ms.
 *
 * Two chunk-loading triggers therefore have to stay shut:
 *   1. pagefind.filters(), which initPagefind() used to call, loads every chunk.
 *   2. Naming a dimension in a search's filter object lazily loads that
 *      dimension's chunk, so the FIRST facet click would reintroduce the whole
 *      cost. That is why filter application moves out of Pagefind too, not just
 *      counting.
 *
 * Everything here is asserted through the rendered DOM and the Pagefind mock —
 * the same surfaces the rest of the suite uses — so no test-only hook is added
 * to the shipped bundle.
 *
 * The fixture is written by PHP's FacetIndexWriter and asserted byte for byte in
 * tests/Index/FacetIndexWriterTest.php, so the encoder and this decoder are
 * pinned to one another.
 */

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');
const { JSDOM } = require('jsdom');

const jsPath = path.resolve(__dirname, '../../assets/js/scolta.js');
const scoltaSource = fs.readFileSync(jsPath, 'utf-8');
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = mockPagefind'
);

const FIXTURE_GZ = fs.readFileSync(path.resolve(__dirname, 'fixtures/facet-index.fixture'));

// The fixture corpus, mirrored from FacetIndexWriterTest::fixtureData():
// 200 pages; topic = Fruit(0-2) + Veg(3-199); level = Beginner(even) +
// Advanced(odd); site = OneSite(all 200), a single-value dimension.
const PAGE_IDS = [];
for (let i = 0; i < 200; i++) {
    PAGE_IDS.push('en_' + crypto.createHash('sha256').update('page-' + i).digest('hex').slice(0, 10));
}

function resultsForPages(pages) {
    return pages.map(p => ({
        id: PAGE_IDS[p],
        score: 1,
        words: [],
        data: () => Promise.resolve({
            url: '/p' + p,
            content: 'Entry ' + p + ' content about things.',
            word_count: 20,
            // Distinct multi-word titles: deduplicateByTitle() collapses results
            // whose title word sets overlap heavily, and "Page 0" / "Page 1"
            // reduce to the same single word.
            meta: { title: 'Alpha' + p + ' Bravo' + p + ' Charlie' + p },
            filters: {},
        }),
    }));
}

/** A Pagefind mock that records what it was asked to do. */
function createMockPagefind(matchedPages, opts = {}) {
    const calls = { filters: 0, searchOpts: [], queries: [] };
    const mock = {
        init: () => Promise.resolve(),
        preload: () => Promise.resolve(),
        filters: () => {
            calls.filters++;
            return Promise.resolve(opts.taxonomy || {});
        },
        search: (query, searchOpts) => {
            calls.queries.push(query);
            calls.searchOpts.push(searchOpts);
            if (!query) return Promise.resolve({ results: [], filters: {}, unfilteredResultCount: 0 });
            // With no filter chunk loaded Pagefind returns an EMPTY filters map,
            // which is why an all-zero count map has to be synthesized rather
            // than read back from the search.
            const applied = opts.pagefindFilters ? opts.pagefindFilters(searchOpts) : matchedPages;
            return Promise.resolve({
                results: resultsForPages(applied),
                filters: opts.pagefindCounts || {},
                unfilteredResultCount: applied.length,
            });
        },
    };
    return { mock, calls };
}

/** Boot Scolta in JSDOM, serving (or refusing) the facet artifact. */
async function boot(mockPagefind, {
    serveArtifact = true,
    artifactBytes = FIXTURE_GZ,
    entryHash = 'en_fixture01',
    withDecompressionStream = true,
} = {}) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: 'http://localhost/search', runScripts: 'outside-only' },
    );
    const { window } = dom;
    const warnings = [];
    const requested = [];

    window.console = Object.assign({}, console, {
        warn: (...args) => warnings.push(args.join(' ')),
        log: () => {}, error: () => {}, debug: () => {},
    });

    window.fetch = (url) => {
        requested.push(String(url));
        if (/pagefind-entry\.json/.test(url)) {
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    version: '1.5.0',
                    languages: { en: { hash: entryHash, wasm: 'en', page_count: 200 } },
                }),
            });
        }
        if (/scolta\.facets/.test(url)) {
            if (!serveArtifact) return Promise.resolve({ ok: false, status: 404 });
            const b = artifactBytes;
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
    if (withDecompressionStream) {
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
    }
    window.TextDecoder = TextDecoder;
    window.mockPagefind = mockPagefind;
    window.scolta = {
        pagefindPath: '/pagefind/pagefind.js',
        scoring: {
            MAX_PAGEFIND_RESULTS: 75, RESULTS_PER_PAGE: 12,
            AI_EXPAND_QUERY: false, AI_SUMMARIZE: false,
        },
        filterFieldDescriptions: { topic: 'What the page covers. Values: Fruit, Veg.' },
    };

    window.eval(patchedSource);
    await new Promise(r => setTimeout(r, 0));
    return { window, warnings, requested };
}

async function initAndSearch(env, query = 'things') {
    env.window.Scolta.init('#scolta-search');
    for (let i = 0; i < 80; i++) {
        await new Promise(r => setTimeout(r, 5));
        if (env.window.document.querySelector('#scolta-query')) break;
    }
    await new Promise(r => setTimeout(r, 20));
    const input = env.window.document.querySelector('#scolta-query');
    input.value = query;
    input.dispatchEvent(new env.window.Event('input', { bubbles: true }));
    await env.window.Scolta.doSearch(false);
    await new Promise(r => setTimeout(r, 60));
}

function renderedFacets(window) {
    return [...window.document.querySelectorAll('#scolta-filters .scolta-filter-item')].map(item => {
        const input = item.querySelector('input[data-scolta-filter-dim]');
        const countEl = item.querySelector('.scolta-filter-count');
        return {
            dim: input.getAttribute('data-scolta-filter-dim'),
            val: input.getAttribute('data-scolta-filter-val'),
            count: countEl ? countEl.textContent.trim().replace(/[()]/g, '') : null,
        };
    });
}

function countFor(window, dim, val) {
    const hit = renderedFacets(window).find(f => f.dim === dim && f.val === val);
    return hit ? hit.count : undefined;
}

async function clickFacet(env, dim, val) {
    const input = env.window.document.querySelector(
        `input[data-scolta-filter-dim="${dim}"][data-scolta-filter-val="${val}"]`);
    expect(input).not.toBeNull();
    input.click();
    await new Promise(r => setTimeout(r, 80));
}

describe('the artifact PHP writes is the artifact the browser reads', () => {
    test('the facet panel is built from it, values and counts alike', async () => {
        // 150 of 200 pages match, which is well past MAX_PAGEFIND_RESULTS (75).
        // Counts tallied from loaded fragments would under-report; these are
        // computed against the FULL matched set, so they must be exact.
        const matched = Array.from({ length: 150 }, (_, i) => i);
        const { mock } = createMockPagefind(matched);
        const env = await boot(mock);
        await initAndSearch(env);

        expect(countFor(env.window, 'topic', 'Fruit')).toBe('3');    // pages 0-2
        expect(countFor(env.window, 'topic', 'Veg')).toBe('147');    // pages 3-149
        expect(countFor(env.window, 'level', 'Beginner')).toBe('75');
        expect(countFor(env.window, 'level', 'Advanced')).toBe('75');
    });

    test('a single-value dimension is carried but not rendered as a facet', async () => {
        const { mock } = createMockPagefind([0, 1, 2, 3]);
        const env = await boot(mock);
        await initAndSearch(env);

        const dims = new Set(renderedFacets(env.window).map(f => f.dim));
        expect(dims.has('topic')).toBe(true);
        expect(dims.has('site')).toBe(false);
    });

    test('a foreign or truncated payload is refused rather than half-read', async () => {
        const { mock, calls } = createMockPagefind([0], { taxonomy: { topic: { Fruit: 3 } } });
        const env = await boot(mock, {
            artifactBytes: zlib.gzipSync(Buffer.from('{"format":"something-else","version":1}\n')),
        });
        await initAndSearch(env);

        expect(calls.filters).toBe(1);
        expect(env.warnings.find(w => /unexpected format/.test(w))).toBeDefined();
    });
});

describe('Pagefind is never asked for filters when the artifact is present', () => {
    test('pagefind.filters() is not called at all', async () => {
        const { mock, calls } = createMockPagefind([0, 1, 2, 3]);
        const env = await boot(mock);
        await initAndSearch(env);

        expect(renderedFacets(env.window).length).toBeGreaterThan(0);
        expect(calls.filters).toBe(0);
    });

    test('no search carries a filters option, including after clicks in two dimensions', async () => {
        const { mock, calls } = createMockPagefind(Array.from({ length: 200 }, (_, i) => i));
        const env = await boot(mock);
        await initAndSearch(env);

        // The trap in the previous design: the FIRST facet click is what fetches
        // a chunk, and every later search then pays the per-result cost.
        await clickFacet(env, 'topic', 'Fruit');
        await clickFacet(env, 'level', 'Beginner');

        expect(calls.searchOpts.length).toBeGreaterThan(0);
        for (const opts of calls.searchOpts) {
            expect(opts === undefined || opts.filters === undefined).toBe(true);
        }
        expect(calls.filters).toBe(0);
    });

    test('filters are applied for real: AND across dimensions, OR within one', async () => {
        const { mock } = createMockPagefind(Array.from({ length: 6 }, (_, i) => i));
        const env = await boot(mock);
        await initAndSearch(env);
        const cards = () => env.window.document.querySelectorAll('.scolta-result-card').length;

        expect(cards()).toBe(6);
        await clickFacet(env, 'topic', 'Fruit');          // pages 0,1,2
        expect(cards()).toBe(3);
        await clickFacet(env, 'level', 'Beginner');       // AND even -> 0,2
        expect(cards()).toBe(2);
        await clickFacet(env, 'level', 'Advanced');       // OR within level -> 0,1,2
        expect(cards()).toBe(3);
    });

    test('counts narrow to the filtered set, the way Pagefind scoped them', async () => {
        const { mock } = createMockPagefind(Array.from({ length: 200 }, (_, i) => i));
        const env = await boot(mock);
        await initAndSearch(env);

        expect(countFor(env.window, 'level', 'Beginner')).toBe('100');
        await clickFacet(env, 'topic', 'Fruit');
        // Facet counts describe the typed query under structural scope only, so a
        // non-structural facet click must not rewrite them. This is the assertion
        // that the artifact path preserves that behavior rather than inventing a
        // new one.
        expect(countFor(env.window, 'level', 'Beginner')).toBe('100');
    });
});

describe('an index built before the artifact existed', () => {
    test('falls back to pagefind.filters(), warns, and names the rebuild', async () => {
        const taxonomy = { topic: { Fruit: 3, Veg: 197 }, level: { Beginner: 100, Advanced: 100 } };
        const { mock, calls } = createMockPagefind([0, 1, 2], { taxonomy, pagefindCounts: taxonomy });
        const env = await boot(mock, { serveArtifact: false });
        await initAndSearch(env);

        expect(calls.filters).toBe(1);
        const warning = env.warnings.find(w => /scolta\.facets/.test(w));
        expect(warning).toBeDefined();
        expect(warning).toMatch(/Rebuild the search index/i);
        expect(warning).toMatch(/pagefind\.filters\(\)/);
        // Facets keep working — slowly — rather than disappearing.
        expect(renderedFacets(env.window).length).toBeGreaterThan(0);
    });

    test('and hands filters back to Pagefind, since it is the only thing that can apply them', async () => {
        const taxonomy = { topic: { Fruit: 3, Veg: 197 } };
        const { mock, calls } = createMockPagefind([0, 1, 2], {
            taxonomy,
            pagefindCounts: taxonomy,
            pagefindFilters: (opts) => (opts && opts.filters ? [0] : [0, 1, 2]),
        });
        const env = await boot(mock, { serveArtifact: false });
        await initAndSearch(env);

        await clickFacet(env, 'topic', 'Fruit');
        const withFilters = calls.searchOpts.filter(o => o && o.filters);
        expect(withFilters.length).toBeGreaterThan(0);
        expect(withFilters[withFilters.length - 1].filters).toEqual({ topic: 'Fruit' });
    });

    test('a browser without DecompressionStream falls back instead of failing', async () => {
        const { mock, calls } = createMockPagefind([0], { taxonomy: { topic: { Fruit: 3, Veg: 197 } } });
        const env = await boot(mock, { withDecompressionStream: false });
        await initAndSearch(env);

        expect(calls.filters).toBe(1);
        expect(env.warnings.find(w => /DecompressionStream/.test(w))).toBeDefined();
    });

    test('a stale cached artifact from an earlier build is refused, not trusted', async () => {
        // The artifact is stamped with the pf_meta hash it was built against. A
        // cache can serve an old one while pagefind-entry.json, which is
        // cache-busted, describes a newer index.
        const { mock, calls } = createMockPagefind([0], { taxonomy: { topic: { Fruit: 3, Veg: 197 } } });
        const env = await boot(mock, { entryHash: 'en_rebuilt99' });
        await initAndSearch(env);

        expect(calls.filters).toBe(1);
        expect(env.warnings.find(w => /stale cached artifact/.test(w))).toBeDefined();
    });
});

describe('the AI subject-to-filter mapper reads the artifact taxonomy', () => {
    test('matchSubjectToFilters is handed the taxonomy, not pagefind.filters() output', () => {
        // The value list has a second consumer besides the render path. Its call
        // site must read the same cachedPagefindFilters the artifact populates.
        expect(scoltaSource).toContain('matchSubjectToFilters(subjectTerms, cachedPagefindFilters, filterDescs)');
        // And that variable is what loadFacetTaxonomy() fills from the artifact.
        expect(scoltaSource).toContain('cachedPagefindFilters = facetIndexTotals(facetIndex)');
    });

    test('a subject term maps onto a real value from the artifact taxonomy', async () => {
        // Driven through the real mapper: the rendered taxonomy is what it reads,
        // so a value it returns has to be one the artifact actually carries.
        const { mock } = createMockPagefind(Array.from({ length: 6 }, (_, i) => i));
        const env = await boot(mock);
        await initAndSearch(env);

        const values = renderedFacets(env.window)
            .filter(f => f.dim === 'topic').map(f => f.val).sort();
        expect(values).toEqual(['Fruit', 'Veg']);
    });
});
