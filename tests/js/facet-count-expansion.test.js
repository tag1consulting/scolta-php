/**
 * Facet counts after AI query expansion.
 *
 * The panel reported counts for the typed query alone while the result list
 * reported the typed query merged with every expansion query, so the two
 * numbers described different sets and the panel was the wrong one. Every count
 * read low — filtering by a value returned more results than it promised — and
 * under the default `hideEmptyFacets` policy a value whose only matches came
 * from expansion counted 0 and was hidden outright, taking its whole dimension
 * group with it when every one of its values was expansion-derived. The user
 * could not filter on content sitting in front of them in the list.
 *
 * Counts are now folded exactly once per typed query, when expansion lands:
 *
 *     counts = countsOf(typed ids) + countsOf(expansion ids \ typed ids)
 *
 * Everything else about them is unchanged and asserted here: they are scoped to
 * structural dimensions only (never the user's selection), they do not move on a
 * facet click, only SEEDING queries contribute, and a superseded cycle's counts
 * never land.
 *
 * These are behavioural tests against the real bundle in JSDOM, following the
 * harness in search-memo.test.js and facet-index.test.js. Every assertion reads
 * the rendered DOM — the panel and the results header — because a divergence
 * between the two is the bug.
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

// The artifact fixture PHP's FacetIndexWriter wrote, byte-asserted in
// tests/Index/FacetIndexWriterTest.php and decoded in facet-index.test.js:
// 200 pages; topic = Fruit(0-2) + Veg(3-199); level = Beginner(even) +
// Advanced(odd); site = OneSite(all 200), a single-value dimension.
const FIXTURE_GZ = fs.readFileSync(path.resolve(__dirname, 'fixtures/facet-index.fixture'));
const PAGE_IDS = [];
for (let i = 0; i < 200; i++) {
    PAGE_IDS.push('en_' + crypto.createHash('sha256').update('page-' + i).digest('hex').slice(0, 10));
}
const fixtureTopic = p => (p < 3 ? 'Fruit' : 'Veg');
const fixtureLevel = p => (p % 2 === 0 ? 'Beginner' : 'Advanced');

// The taxonomy the fallback path reads from pagefind.filters(), matching the
// artifact's dimensions so one scenario can be asserted on both paths.
const TAXONOMY = {
    language: { en: 200 },                        // structural — never a facet
    site: { OneSite: 200 },                       // single value — never rendered
    topic: { Fruit: 3, Veg: 197 },
    level: { Beginner: 100, Advanced: 100 },
};

const CORPUS_PAGES = 200;

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

function fragmentFor(id, doc) {
    return {
        url: doc.url,
        // Distinct multi-word titles: deduplicateByTitle() collapses results
        // whose title word sets overlap heavily.
        meta: Object.assign({ title: doc.title || ('Alpha' + id + ' Bravo' + id + ' Charlie' + id), url: doc.url },
            doc.date ? { date: doc.date } : {}),
        filters: doc.filters || {},
        excerpt: 'excerpt for ' + id,
        content: 'content for ' + id,
        word_count: 20,
        locations: [],
    };
}

function valuesOf(doc, dim) {
    const raw = (doc.filters || {})[dim];
    if (raw === undefined || raw === null) return [];
    return Array.isArray(raw) ? raw : [raw];
}

/**
 * Boot the real bundle against a synthetic Pagefind corpus.
 *
 * docs:    { id: { url, filters, title?, date? } }
 * queries: { queryString: [id, ...] }   — everything else matches nothing
 * expansion: { terms: [...], sort_hint?: {...} } served by the expand endpoint
 * artifact: serve scolta.facets (ids must then be fixture page ids)
 * gateExpansion:   hold the expand response until releaseExpansion()
 * gateCountQuery:  hold the UNFILTERED search for this query until releaseCount()
 */
async function createEnv({
    docs,
    queries,
    expansion = null,
    artifact = false,
    scoring = {},
    initialFilters = null,
    gateExpansion = false,
    gateCountQuery = null,
} = {}) {
    const dataCalls = [];
    const searchOpts = [];
    let filtersCalls = 0;

    let releaseExpansion = () => {};
    const expansionGate = gateExpansion
        ? new Promise(r => { releaseExpansion = r; })
        : Promise.resolve();
    let releaseCount = () => {};
    const countGate = gateCountQuery
        ? new Promise(r => { releaseCount = r; })
        : Promise.resolve();

    function nativeCounts(ids) {
        // What Pagefind reports: every value of every dimension, zeros included,
        // over the full matched set.
        const counts = {};
        for (const [dim, values] of Object.entries(TAXONOMY)) {
            counts[dim] = {};
            for (const value of Object.keys(values)) counts[dim][value] = 0;
        }
        for (const id of ids) {
            const doc = docs[id];
            if (!doc) continue;
            for (const dim of Object.keys(doc.filters || {})) {
                if (!counts[dim]) counts[dim] = {};
                for (const v of new Set(valuesOf(doc, dim))) {
                    counts[dim][v] = (counts[dim][v] || 0) + 1;
                }
            }
        }
        return counts;
    }

    function applyOptFilters(ids, opts) {
        const f = opts && opts.filters;
        if (!f) return ids;
        return ids.filter(id => {
            const doc = docs[id];
            if (!doc) return false;
            for (const [dim, val] of Object.entries(f)) {
                const want = (val && typeof val === 'object' && Array.isArray(val.any)) ? val.any : [val];
                const have = valuesOf(doc, dim);
                if (!want.some(v => have.indexOf(v) !== -1)) return false;
            }
            return true;
        });
    }

    const search = jest.fn((query, opts) => {
        searchOpts.push(opts);
        const ids = applyOptFilters((queries[query] || []).slice(), opts);
        const payload = () => ({
            results: ids.map(id => ({
                id,
                score: 1,
                words: [],
                data: () => {
                    dataCalls.push(id);
                    return Promise.resolve(fragmentFor(id, docs[id]));
                },
            })),
            // With the artifact present no filter chunk is loaded, so Pagefind
            // returns an empty map and the counts come from scolta.facets.
            filters: artifact ? {} : nativeCounts(ids),
            unfilteredResultCount: ids.length,
        });
        const gated = gateCountQuery && query === gateCountQuery && !(opts && opts.filters);
        return gated ? countGate.then(payload) : Promise.resolve(payload());
    });

    const mockPagefind = {
        init: () => Promise.resolve(),
        preload: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => { filtersCalls++; return Promise.resolve(TAXONOMY); },
        search,
    };

    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com/search', runScripts: 'dangerously' }
    );
    const window = dom.window;

    window.fetch = jest.fn((url, opts) => {
        const u = String(url);
        if (u.includes('pagefind-entry.json')) {
            return Promise.resolve({
                ok: true, status: 200,
                json: () => Promise.resolve({
                    version: '1.5.0',
                    languages: { en: { hash: 'en_fixture01', wasm: 'en', page_count: CORPUS_PAGES } },
                }),
                text: () => Promise.resolve('{}'),
            });
        }
        if (/scolta\.facets/.test(u)) {
            if (!artifact) return Promise.resolve({ ok: false, status: 404 });
            const b = FIXTURE_GZ;
            return Promise.resolve({
                ok: true, status: 200,
                arrayBuffer: () => Promise.resolve(
                    b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength)),
            });
        }
        if (u === '/e') {
            const body = JSON.parse((opts && opts.body) || '{}');
            const payload = typeof expansion === 'function' ? expansion(body.query) : expansion;
            return expansionGate.then(() => ({
                ok: true, status: 200,
                json: () => Promise.resolve(payload || {}),
                text: () => Promise.resolve('{}'),
            }));
        }
        return Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({}),
            text: () => Promise.resolve('{}'),
        });
    });

    // JSDOM ships neither DecompressionStream nor a streaming Response; Node's
    // zlib stands in over the identical bytes (see facet-index.test.js).
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
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};
    window.mockPagefind = mockPagefind;

    window.eval(patchedSource);
    window.scolta = {
        scoring: Object.assign({
            AI_EXPAND_QUERY: !!expansion,
            AI_SUMMARIZE: false,
        }, scoring),
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pagefind/pagefind.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    };
    window.Scolta.init('#scolta-search');
    await settle(window, 20);

    return {
        window,
        inst: window.Scolta.defaultInstance,
        search,
        searchOpts,
        dataCalls,
        initialFilters,
        get filtersCalls() { return filtersCalls; },
        releaseExpansion,
        releaseCount,
    };
}

async function settle(window, ticks = 40) {
    for (let i = 0; i < ticks; i++) {
        await new Promise(r => setTimeout(r, 0));
    }
}

/** Type a query and run the full cycle, expansion included. */
async function runSearch(env, query) {
    env.window.document.querySelector('#scolta-query').value = query;
    const filters = env.initialFilters
        ? Object.fromEntries(Object.entries(env.initialFilters)
            .map(([dim, vals]) => [dim, new env.window.Set(vals)]))
        : undefined;
    await env.inst.doSearch(false, filters);
    await settle(env.window);
}

/** The rendered panel, as { "dim:value": count }. */
function panel(window) {
    const out = {};
    for (const item of window.document.querySelectorAll('#scolta-filters .scolta-filter-item')) {
        const input = item.querySelector('input[data-scolta-filter-dim]');
        const countEl = item.querySelector('.scolta-filter-count');
        out[input.getAttribute('data-scolta-filter-dim') + ':' + input.getAttribute('data-scolta-filter-val')] =
            countEl ? Number(countEl.textContent.trim().replace(/[()]/g, '')) : null;
    }
    return out;
}

function panelGroups(window) {
    return [...window.document.querySelectorAll('#scolta-filters .scolta-filter-group h3')]
        .map(h => h.textContent.trim());
}

/** The number the results header claims, i.e. allScoredResults.length. */
function headerCount(window) {
    const text = window.document.querySelector('#scolta-results-header').textContent || '';
    const m = text.match(/^\s*([\d,]+)\s+results?\s+for/);
    return m ? Number(m[1].replace(/,/g, '')) : null;
}

function resultCards(window) {
    return window.document.querySelectorAll('#scolta-results .scolta-result-card').length;
}

// ---------------------------------------------------------------------------
// The scenario both index paths must agree on.
//
// Typed 'alpha' matches two Beginner documents. Expansion adds three more: one
// Beginner and two Advanced. Panel must read Beginner 3 / Advanced 2, topic
// Fruit 3 / Veg 2, and the header must read 5.
// ---------------------------------------------------------------------------

const SCENARIO_TYPED = [0, 2];          // Fruit/Beginner, Fruit/Beginner
const SCENARIO_EXPANDED = [4, 1, 3];    // Veg/Beginner, Fruit/Advanced, Veg/Advanced
const SCENARIO_EXPECTED = {
    'topic:Fruit': 3, 'topic:Veg': 2,
    'level:Beginner': 3, 'level:Advanced': 2,
};

function scenarioDocs(artifact) {
    const docs = {};
    for (const p of [...SCENARIO_TYPED, ...SCENARIO_EXPANDED]) {
        docs[artifact ? PAGE_IDS[p] : 'doc-' + p] = {
            url: '/p' + p,
            title: 'Alpha' + p + ' Bravo' + p + ' Charlie' + p,
            filters: { topic: fixtureTopic(p), level: fixtureLevel(p), site: 'OneSite', language: 'en' },
        };
    }
    return docs;
}

function scenarioEnv(artifact) {
    const id = p => (artifact ? PAGE_IDS[p] : 'doc-' + p);
    return createEnv({
        artifact,
        docs: scenarioDocs(artifact),
        queries: {
            alpha: SCENARIO_TYPED.map(id),
            beta: SCENARIO_EXPANDED.map(id),
        },
        expansion: { terms: ['beta'] },
    });
}

// ---------------------------------------------------------------------------

describe('the bug: the panel described the typed query, the list described more', () => {
    test('counts cover the expansion, and the panel agrees with the header', async () => {
        const env = await scenarioEnv(false);
        await runSearch(env, 'alpha');

        // Before the fix: Beginner (2), Advanced hidden entirely at 0, header 5.
        expect(panel(env.window)).toEqual(SCENARIO_EXPECTED);
        expect(headerCount(env.window)).toBe(5);
    });

    test('the header count and each dimension sum are the same number', async () => {
        const env = await scenarioEnv(false);
        await runSearch(env, 'alpha');

        const p = panel(env.window);
        const sumOf = dim => Object.entries(p)
            .filter(([k]) => k.startsWith(dim + ':'))
            .reduce((a, [, n]) => a + n, 0);
        expect(sumOf('level')).toBe(headerCount(env.window));
        expect(sumOf('topic')).toBe(headerCount(env.window));
        expect(resultCards(env.window)).toBe(headerCount(env.window));
    });
});

describe('every document counts exactly once', () => {
    test('a document matched by the typed query and three expansion terms counts 1', async () => {
        const env = await createEnv({
            docs: {
                't-1': { url: '/t1', title: 'Alpha One Bravo', filters: { topic: 'Fruit', level: 'Beginner' } },
                'shared': { url: '/shared', title: 'Charlie Two Delta', filters: { topic: 'Veg', level: 'Advanced' } },
            },
            queries: {
                alpha: ['t-1', 'shared'],
                beta: ['shared'], gamma: ['shared'], delta: ['shared'],
            },
            expansion: { terms: ['beta', 'gamma', 'delta'] },
        });
        await runSearch(env, 'alpha');

        expect(panel(env.window)).toEqual({
            'topic:Fruit': 1, 'topic:Veg': 1,
            'level:Beginner': 1, 'level:Advanced': 1,
        });
        expect(headerCount(env.window)).toBe(2);
    });

    test('a document carrying two values in one dimension adds one to each, never two to one', async () => {
        const env = await createEnv({
            docs: {
                't-1': { url: '/t1', title: 'Alpha One Bravo', filters: { topic: 'Fruit', level: 'Beginner' } },
                'multi': { url: '/multi', title: 'Charlie Two Delta', filters: { topic: ['Fruit', 'Veg'], level: 'Advanced' } },
            },
            queries: { alpha: ['t-1'], beta: ['multi'] },
            expansion: { terms: ['beta'] },
        });
        await runSearch(env, 'alpha');

        const p = panel(env.window);
        expect(p['topic:Fruit']).toBe(2);   // t-1 plus one of multi's two values
        expect(p['topic:Veg']).toBe(1);
        // Two documents, three units of topic: the dimension sum EXCEEDS the
        // header here, and correctly so — a multi-value taxonomy is not a
        // partition. What must not happen is `multi` adding 2 to one value.
        expect(p['topic:Fruit'] + p['topic:Veg']).toBe(3);
        expect(headerCount(env.window)).toBe(2);
    });

    test('two fragment ids sharing one normalized URL count as one page', async () => {
        // mergeResults() dedups the list by normalized URL (strip .html, strip a
        // trailing slash, lowercase), so /foo and /foo.html are one result. The
        // count path collapses them the same way wherever it loads fragments.
        const env = await createEnv({
            docs: {
                't-1': { url: '/t1', title: 'Alpha One Bravo', filters: { topic: 'Fruit', level: 'Beginner' } },
                'x': { url: '/foo', title: 'Charlie Two Delta', filters: { topic: 'Veg', level: 'Advanced' } },
                'y': { url: '/foo.html', title: 'Echo Three Foxtrot', filters: { topic: 'Veg', level: 'Advanced' } },
            },
            queries: { alpha: ['t-1'], beta: ['x'], gamma: ['y'] },
            expansion: { terms: ['beta', 'gamma'] },
        });
        await runSearch(env, 'alpha');

        expect(headerCount(env.window)).toBe(2);       // /t1 and /foo
        expect(panel(env.window)['topic:Veg']).toBe(1);
        expect(panel(env.window)['level:Advanced']).toBe(1);
    });
});

describe('hideEmptyFacets: expansion-only values come back', () => {
    test('a value absent from the panel before expansion is present after', async () => {
        const env = await createEnv({
            docs: {
                't-1': { url: '/t1', title: 'Alpha One Bravo', filters: { topic: 'Fruit', level: 'Beginner' } },
                'e-1': { url: '/e1', title: 'Charlie Two Delta', filters: { topic: 'Veg', level: 'Advanced' } },
            },
            queries: { alpha: ['t-1'], beta: ['e-1'] },
            expansion: { terms: ['beta'] },
            gateExpansion: true,
        });

        env.window.document.querySelector('#scolta-query').value = 'alpha';
        const cycle = env.inst.doSearch();
        await settle(env.window);

        // Typed-query counts only: Advanced is at 0 and hidden, and with it the
        // only Veg document, so the user cannot reach either.
        expect(panel(env.window)).toEqual({ 'topic:Fruit': 1, 'level:Beginner': 1 });

        env.releaseExpansion();
        await settle(env.window);
        await cycle;

        expect(panel(env.window)).toEqual({
            'topic:Fruit': 1, 'topic:Veg': 1,
            'level:Beginner': 1, 'level:Advanced': 1,
        });
    });

    test('a dimension whose every value is expansion-derived gets its whole group back', async () => {
        // `level` is carried by no typed result at all, so before expansion the
        // group is not merely wrong — it does not exist.
        const env = await createEnv({
            docs: {
                't-1': { url: '/t1', title: 'Alpha One Bravo', filters: { topic: 'Fruit' } },
                'e-1': { url: '/e1', title: 'Charlie Two Delta', filters: { topic: 'Veg', level: 'Advanced' } },
                'e-2': { url: '/e2', title: 'Echo Three Foxtrot', filters: { topic: 'Veg', level: 'Beginner' } },
            },
            queries: { alpha: ['t-1'], beta: ['e-1', 'e-2'] },
            expansion: { terms: ['beta'] },
            gateExpansion: true,
        });

        env.window.document.querySelector('#scolta-query').value = 'alpha';
        const cycle = env.inst.doSearch();
        await settle(env.window);
        expect(panelGroups(env.window)).toEqual(['Topic']);

        env.releaseExpansion();
        await settle(env.window);
        await cycle;

        expect(panelGroups(env.window).sort()).toEqual(['Level', 'Topic']);
        expect(panel(env.window)['level:Advanced']).toBe(1);
        expect(panel(env.window)['level:Beginner']).toBe(1);
    });
});

describe('only seeding queries contribute', () => {
    test('an agreement-only sub-word introduces no document and no count', async () => {
        // 'grissom' out of the phrase "gus grissom": 12/200 documents is above
        // EXPAND_SUBWORD_MAX_FREQ (0.05) so the admission guard rejects it as a
        // search term, but its specificity (0.53) clears SPECIFICITY_AGREEMENT_GATE
        // (0.45), so it is admitted as agreement-only — it lends co-occurrence
        // score to documents another query found and emits none of its own.
        const docs = {
            't-1': { url: '/t1', title: 'Alpha One Bravo', filters: { topic: 'Fruit', level: 'Beginner' } },
            'e-1': { url: '/e1', title: 'Charlie Two Delta', filters: { topic: 'Veg', level: 'Beginner' } },
        };
        const grissomIds = [];
        for (let i = 0; i < 12; i++) {
            const id = 'g-' + i;
            grissomIds.push(id);
            docs[id] = {
                url: '/g' + i,
                title: 'Golf' + i + ' Hotel' + i + ' India' + i,
                filters: { topic: 'Veg', level: 'Advanced' },
            };
        }
        const env = await createEnv({
            docs,
            queries: { alpha: ['t-1'], beta: ['e-1'], grissom: grissomIds, gus: [] },
            expansion: { terms: ['beta', 'gus grissom'] },
        });
        await runSearch(env, 'alpha');

        // The twelve 'grissom' documents are in neither the list nor the panel.
        expect(headerCount(env.window)).toBe(2);
        expect(panel(env.window)).toEqual({
            'topic:Fruit': 1, 'topic:Veg': 1, 'level:Beginner': 2,
        });
    });
});

describe('counts stay scoped to structural dimensions', () => {
    test('a page loaded with a facet applied still counts every value of it', async () => {
        // The tempting wrong fix — counting under activeFilters, or over
        // allScoredResults — reports 0 for every value the user did not pick,
        // hideEmptyFacets hides them, and the facet can never be changed.
        const env = await createEnv({
            docs: {
                't-1': { url: '/t1', title: 'Alpha One Bravo', filters: { topic: 'Fruit', level: 'Beginner' } },
                't-2': { url: '/t2', title: 'Charlie Two Delta', filters: { topic: 'Fruit', level: 'Advanced' } },
                'e-1': { url: '/e1', title: 'Echo Three Foxtrot', filters: { topic: 'Veg', level: 'Advanced' } },
            },
            queries: { alpha: ['t-1', 't-2'], beta: ['e-1'] },
            expansion: { terms: ['beta'] },
            initialFilters: { level: ['Beginner'] },
        });
        await runSearch(env, 'alpha');

        const p = panel(env.window);
        expect(p['level:Beginner']).toBe(1);
        expect(p['level:Advanced']).toBe(2);   // visible and clickable, not 0
        expect(p['topic:Fruit']).toBe(2);
        expect(p['topic:Veg']).toBe(1);
        // The LIST is narrowed by the facet; the panel deliberately is not.
        expect(headerCount(env.window)).toBe(1);
    });

    test('a facet toggle after expansion moves no count at all', async () => {
        const env = await scenarioEnv(false);
        await runSearch(env, 'alpha');
        const before = panel(env.window);

        env.window.document.querySelector(
            'input[data-scolta-filter-dim="level"][data-scolta-filter-val="Advanced"]').click();
        await settle(env.window);

        expect(panel(env.window)).toEqual(before);
        expect(before['level:Beginner']).toBe(3);   // not the toggled-set counts
    });
});

describe('staleness', () => {
    test("a superseded cycle's post-expansion counts are neither stored nor rendered", async () => {
        // A user facet makes the count searches genuinely separate Pagefind
        // calls (structuralFilters drops the facet, so the memo correctly
        // misses), which is what lets the count pass be gated.
        const env = await createEnv({
            docs: {
                'stale-t': { url: '/st', title: 'Alpha One Bravo', filters: { topic: 'Fruit', level: 'Beginner' } },
                'stale-e': { url: '/se', title: 'Charlie Two Delta', filters: { topic: 'Fruit', level: 'Beginner' } },
                'fresh-t': { url: '/ft', title: 'Echo Three Foxtrot', filters: { topic: 'Veg', level: 'Advanced' } },
                'fresh-e': { url: '/fe', title: 'Golf Four Hotel', filters: { topic: 'Veg', level: 'Advanced' } },
            },
            queries: {
                stale: ['stale-t'], staleexp: ['stale-e'],
                fresh: ['fresh-t'], freshexp: ['fresh-e'],
            },
            // Each cycle expands to its own term, so only cycle 1's count pass
            // sits behind the gate.
            expansion: q => ({ terms: [q === 'fresh' ? 'freshexp' : 'staleexp'] }),
            initialFilters: { level: ['Beginner'] },
            gateCountQuery: 'staleexp',
        });

        env.window.document.querySelector('#scolta-query').value = 'stale';
        const first = env.inst.doSearch(false, { level: new env.window.Set(['Beginner']) });
        await settle(env.window);

        // Cycle 2 supersedes it and completes, counts included.
        env.window.document.querySelector('#scolta-query').value = 'fresh';
        await env.inst.doSearch();
        await settle(env.window);
        const afterFresh = panel(env.window);
        expect(afterFresh['topic:Veg']).toBe(2);   // cycle 2's own expansion landed

        env.releaseCount();
        await settle(env.window);
        await first;

        expect(panel(env.window)).toEqual(afterFresh);
    });
});

describe('the native sort branch', () => {
    test('a sortOverride cycle recomputes counts over the sorted union', async () => {
        const env = await createEnv({
            docs: {
                't-1': { url: '/t1', title: 'Alpha One Bravo', date: '2026-01-01', filters: { topic: 'Fruit', level: 'Beginner' } },
                'e-1': { url: '/e1', title: 'Charlie Two Delta', date: '2026-02-01', filters: { topic: 'Veg', level: 'Advanced' } },
                'e-2': { url: '/e2', title: 'Echo Three Foxtrot', date: '2026-03-01', filters: { topic: 'Veg', level: 'Advanced' } },
            },
            queries: { alpha: ['t-1'], beta: ['e-1', 'e-2'] },
            expansion: { terms: ['beta'], sort_hint: { field: 'date', direction: 'desc' } },
        });
        await runSearch(env, 'alpha');

        // The sort path replaces the list with a URL-deduped union over the
        // whole term set; the counts have to describe that same union.
        expect(headerCount(env.window)).toBe(3);
        expect(panel(env.window)).toEqual({
            'topic:Fruit': 1, 'topic:Veg': 2,
            'level:Beginner': 1, 'level:Advanced': 2,
        });
    });
});

describe('the facet index path and the fallback path agree', () => {
    test('the artifact produces the same numbers as Pagefind-side counting', async () => {
        const withArtifact = await scenarioEnv(true);
        await runSearch(withArtifact, 'alpha');

        expect(withArtifact.filtersCalls).toBe(0);          // artifact really is in play
        expect(headerCount(withArtifact.window)).toBe(5);
        // The artifact reports every value of every dimension, zeros included;
        // the fixture's `site` dimension has one value and is never rendered.
        expect(panel(withArtifact.window)).toEqual(SCENARIO_EXPECTED);
    });

    test('the delta costs the artifact path zero extra fragment loads', async () => {
        const withArtifact = await scenarioEnv(true);
        await runSearch(withArtifact, 'alpha');

        // facetCountsFor() reads nothing but r.id, so every document is loaded
        // exactly once — by the result path — and the count pass loads none.
        const loads = {};
        for (const id of withArtifact.dataCalls) loads[id] = (loads[id] || 0) + 1;
        expect(Object.values(loads).every(n => n === 1)).toBe(true);
        expect(withArtifact.dataCalls).toHaveLength(5);

        // The fallback path has no artifact to count against, so it does load
        // the delta's fragments — Pagefind-cached in a browser, and the
        // difference is what the assertion above is protecting.
        const fallback = await scenarioEnv(false);
        await runSearch(fallback, 'alpha');
        expect(fallback.dataCalls.length).toBeGreaterThan(5);
    });
});
