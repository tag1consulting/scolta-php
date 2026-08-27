/**
 * The per-visitor expansion switch in the results header.
 *
 * A visitor can turn query expansion off for themselves and back on, without a
 * session and without anything server-side reading the choice: the gate is
 * applied once in getInstanceConfig(), which every existing expansion gate
 * already reads, and the answer is held in localStorage.
 *
 * What is pinned here, in rough order of what would hurt most if it broke:
 *
 *  - The switch narrows and never widens. A deployment that withholds
 *    expansion — a site that turned it off, or a platform access rule that
 *    refused this account, both of which arrive as one false AI_EXPAND_QUERY —
 *    cannot be overridden from the browser, and is offered no switch at all.
 *  - The control does not delete itself on first use. It renders from the
 *    ALLOWED value rather than the effective one, so "off" still shows a way
 *    back. Reading the effective value here is the obvious mistake and the
 *    reason the two are separate keys.
 *  - Re-enabling actually re-expands. The re-run deliberately does NOT use
 *    doSearch(true): preserveFilters reuses the stored expansion terms, so a
 *    preserving re-run would never issue the request the visitor just asked
 *    for. That is invisible in a disable-only test, which is why the
 *    round-trip is driven in both directions.
 *  - Active facet filters survive the round-trip, since the non-preserving
 *    re-run has to carry them across itself.
 *  - An empty result set still offers the switch, which is when someone who
 *    turned expansion off is most likely to want it back.
 *  - Storage that throws (private mode, disabled storage) degrades to
 *    expansion-on and a switch that still works for the life of the page.
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

const STORAGE_KEY = 'scolta:expansion-disabled';

// The same committed artifact browse-no-query.test.js and facet-index.test.js
// drive: 200 pages, topic = Fruit(0-2) + Veg(3-199).
const FIXTURE_GZ = fs.readFileSync(path.resolve(__dirname, 'fixtures/facet-index.fixture'));

const PAGE_IDS = [];
for (let i = 0; i < 200; i++) {
    PAGE_IDS.push('en_' + crypto.createHash('sha256').update('page-' + i).digest('hex').slice(0, 10));
}

/** Distinct multi-word titles, or deduplicateByTitle() collapses the list. */
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
 * Serve a Pagefind fragment as published — gzipped JSON behind the
 * "pagefind_dcd" sentinel — for the artifact browse path, which fetches
 * fragments by id instead of going through a result's data().
 */
function fragmentResponse(url) {
    const m = String(url).match(/fragment\/(en_[0-9a-f]+)\.pf_fragment$/);
    if (!m) return null;
    const p = PAGE_IDS.indexOf(m[1]);
    if (p === -1) return Promise.resolve({ ok: false, status: 404 });
    const gz = zlib.gzipSync(Buffer.from('pagefind_dcd' + JSON.stringify({
        url: '/p' + p,
        content: 'Entry ' + p + ' content about things.',
        word_count: 20,
        meta: { title: 'Alpha' + p + ' Bravo' + p + ' Charlie' + p, url: '/p' + p },
        filters: {},
    })));
    return Promise.resolve({
        ok: true,
        status: 200,
        arrayBuffer: () => Promise.resolve(
            gz.buffer.slice(gz.byteOffset, gz.byteOffset + gz.byteLength)),
    });
}

/**
 * A Pagefind mock whose matches depend on the term, so an expansion term
 * ("beta") pulls in pages the primary term ("alpha") does not.
 *
 * `barren` makes every search return nothing, for the empty-state case.
 */
function createMockPagefind(barren) {
    const calls = { queries: [] };
    const mock = {
        init: () => Promise.resolve(),
        preload: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (query) => {
            calls.queries.push(query);
            let matched;
            if (barren) matched = [];
            else if (query === null || query === undefined) matched = ALL_PAGES;
            else if (String(query).includes('beta')) matched = ALL_PAGES.slice(0, 40);
            else matched = ALL_PAGES.slice(0, 20);
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
 * @param {object} opts
 *   - scoring:  merged into window.scolta.scoring
 *   - sayt:     merged top-level into window.scolta
 *   - seedOptOut: write the opt-out key before the bundle is evaluated
 *   - breakStorage: make every localStorage access throw
 *   - barren:   Pagefind matches nothing
 */
async function boot(opts = {}) {
    const { mock, calls } = createMockPagefind(opts.barren);
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: 'http://localhost/search', runScripts: 'outside-only' },
    );
    const { window } = dom;
    const expandCalls = [];

    window.console = Object.assign({}, console, {
        warn: () => {}, log: () => {}, error: () => {}, debug: () => {},
    });

    if (opts.seedOptOut) window.localStorage.setItem(STORAGE_KEY, '1');
    if (opts.breakStorage) {
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get() { throw new Error('storage denied'); },
        });
    }

    window.fetch = (url, init) => {
        const u = String(url);
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
        if (u === '/e') {
            expandCalls.push(init && init.body ? JSON.parse(init.body) : {});
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ terms: ['beta'] }),
                text: () => Promise.resolve('{}'),
            });
        }
        const frag = fragmentResponse(u);
        if (frag) return frag;
        return Promise.resolve({ ok: false, status: 404, text: () => Promise.resolve('') });
    };

    // JSDOM ships neither DecompressionStream nor a streaming Response; Node's
    // zlib stands in over the identical bytes.
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
    window.mockPagefind = mock;
    window.scolta = Object.assign({
        pagefindPath: '/pagefind/pagefind.js',
        scoring: Object.assign({
            MAX_PAGEFIND_RESULTS: 75,
            RESULTS_PER_PAGE: 12,
            AI_EXPAND_QUERY: true,
            AI_SUMMARIZE: false,
        }, opts.scoring),
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
    }, opts.sayt || {});

    window.eval(patchedSource);
    await new Promise(r => setTimeout(r, 0));

    window.Scolta.init('#scolta-search');
    for (let i = 0; i < 80; i++) {
        await new Promise(r => setTimeout(r, 5));
        if (window.document.querySelector('#scolta-query')) break;
    }
    await new Promise(r => setTimeout(r, 20));
    calls.queries.length = 0;
    expandCalls.length = 0;
    return { window, calls, expandCalls };
}

async function search(env, text) {
    const input = env.window.document.querySelector('#scolta-query');
    input.value = text;
    input.dispatchEvent(new env.window.Event('input', { bubbles: true }));
    await env.window.Scolta.doSearch(false);
    await new Promise(r => setTimeout(r, 80));
}

const toggle = (win) => win.document.querySelector('[data-scolta-expansion-toggle]');

async function clickToggle(env) {
    const el = toggle(env.window);
    expect(el).not.toBeNull();
    el.click();
    await new Promise(r => setTimeout(r, 120));
}

const headerText = (win) =>
    win.document.querySelector('#scolta-results-header').textContent;

/** The header template carries newlines and indentation between its spans. */
const normalize = (s) => s.replace(/\s+/g, ' ').trim();

const cards = (win) =>
    win.document.querySelectorAll('#scolta-results .scolta-result-card');

describe('the results-header expansion switch', () => {

    test('offers "disable" by default, and expansion runs', async () => {
        const env = await boot();
        await search(env, 'alpha');

        expect(env.expandCalls.length).toBe(1);
        expect(toggle(env.window).textContent).toBe('disable');
        // The switch sits inside the count sentence, not off to the side:
        // `N results for "alpha" (with expanded terms - disable)`.
        expect(normalize(headerText(env.window)))
            .toContain('for "alpha" (with expanded terms - disable)');
    });

    test('clicking it stops expansion and offers the way back', async () => {
        const env = await boot();
        await search(env, 'alpha');
        env.expandCalls.length = 0;

        await clickToggle(env);

        expect(env.window.localStorage.getItem(STORAGE_KEY)).toBe('1');
        expect(env.expandCalls.length).toBe(0);
        expect(toggle(env.window).textContent).toBe('expand terms');
        // `N results for "alpha" - expand terms`, no parenthetical.
        expect(normalize(headerText(env.window)))
            .toContain('for "alpha" - expand terms');
        expect(headerText(env.window)).not.toContain('with expanded terms');
        // The switch is the point: results are still rendered, just unexpanded.
        expect(cards(env.window).length).toBeGreaterThan(0);
    });

    test('a later search stays unexpanded while the opt-out stands', async () => {
        const env = await boot();
        await search(env, 'alpha');
        await clickToggle(env);
        env.expandCalls.length = 0;

        await search(env, 'gamma');

        expect(env.expandCalls.length).toBe(0);
        expect(toggle(env.window).textContent).toBe('expand terms');
    });

    test('an opt-out already in storage is honoured on load', async () => {
        const env = await boot({ seedOptOut: true });
        await search(env, 'alpha');

        expect(env.expandCalls.length).toBe(0);
        expect(toggle(env.window).textContent).toBe('expand terms');
    });

    /**
     * The preserveFilters trap. doSearch(true) would resolve the expansion to
     * the stored terms instead of calling expandQuery(), so re-enabling would
     * silently never expand again. Only a round-trip catches it.
     */
    test('re-enabling issues a fresh expand request', async () => {
        const env = await boot();
        await search(env, 'alpha');
        await clickToggle(env);
        env.expandCalls.length = 0;

        await clickToggle(env);

        expect(env.window.localStorage.getItem(STORAGE_KEY)).toBeNull();
        expect(env.expandCalls.length).toBe(1);
        expect(env.expandCalls[0].query).toBe('alpha');
        expect(toggle(env.window).textContent).toBe('disable');
        expect(headerText(env.window)).toContain('with expanded terms');
    });

    test('an active facet filter survives the round-trip', async () => {
        const env = await boot();
        await search(env, 'alpha');

        const box = env.window.document.querySelector(
            'input[data-scolta-filter-dim="topic"][data-scolta-filter-val="Fruit"]');
        expect(box).not.toBeNull();
        box.click();
        await new Promise(r => setTimeout(r, 100));
        expect(headerText(env.window)).toContain('Fruit');

        await clickToggle(env);
        expect(headerText(env.window)).toContain('Fruit');

        await clickToggle(env);
        expect(headerText(env.window)).toContain('Fruit');

        const stillChecked = env.window.document.querySelector(
            'input[data-scolta-filter-dim="topic"][data-scolta-filter-val="Fruit"]');
        expect(stillChecked.checked).toBe(true);
    });

    /**
     * A browse has no query to expand, so the switch would govern nothing:
     * expansion is already skipped on the browse path (expandPromise resolves
     * null), and a control over a no-op only misleads.
     */
    test('a browse offers no switch, and the next search brings it back', async () => {
        const env = await boot();
        await search(env, '');

        expect(cards(env.window).length).toBeGreaterThan(0);
        expect(toggle(env.window)).toBeNull();
        expect(headerText(env.window)).not.toContain('expanded terms');

        await search(env, 'alpha');
        expect(toggle(env.window)).not.toBeNull();
    });

    test('a barren browse offers no switch either', async () => {
        // The artifact serves browses, so `barren` (a Pagefind mock knob)
        // can't empty one; a selection no posting list carries can.
        const env = await boot();
        env.window.Scolta.doSearch(false, { topic: new Set(['Nonexistent']) });
        await new Promise(r => setTimeout(r, 80));

        expect(cards(env.window).length).toBe(0);
        expect(toggle(env.window)).toBeNull();
    });

    test('an empty result set still offers the switch', async () => {
        const env = await boot({ barren: true });
        await search(env, 'alpha');

        expect(cards(env.window).length).toBe(0);
        expect(env.window.document.querySelector('#scolta-no-results').style.display)
            .toBe('block');
        expect(toggle(env.window)).not.toBeNull();
        // No count sentence to sit in, so the label spells the noun out rather
        // than leaning on a "(with expanded terms - …)" that is not there.
        expect(toggle(env.window).textContent).toBe('disable expanded terms');
    });
});

describe('the switch narrows and never widens', () => {

    test('a deployment with expansion off renders no switch at all', async () => {
        const env = await boot({ scoring: { AI_EXPAND_QUERY: false } });
        await search(env, 'alpha');

        expect(toggle(env.window)).toBeNull();
        expect(env.expandCalls.length).toBe(0);
    });

    /**
     * The one direction that must be impossible: a browser-held value handing
     * a visitor a feature the deployment withheld. AI_EXPAND_QUERY false is
     * how BOTH a site-level switch-off and a per-account access refusal reach
     * the browser, so this covers both.
     */
    test('setExpansionEnabled(true) cannot resurrect a withheld feature', async () => {
        const env = await boot({ scoring: { AI_EXPAND_QUERY: false } });
        env.window.Scolta.setExpansionEnabled(true);
        await search(env, 'alpha');

        expect(env.expandCalls.length).toBe(0);
        expect(env.window.Scolta.isExpansionEnabled()).toBe(false);
        expect(toggle(env.window)).toBeNull();
    });

    test('expansionToggle false hides the switch but leaves expansion on', async () => {
        const env = await boot({ scoring: { EXPANSION_TOGGLE: false } });
        await search(env, 'alpha');

        expect(toggle(env.window)).toBeNull();
        expect(env.expandCalls.length).toBe(1);
        expect(headerText(env.window)).toContain('with expanded terms');
    });
});

describe('storage that refuses to work', () => {

    test('expansion stays on and the switch still flips for this page', async () => {
        const env = await boot({ breakStorage: true });
        await search(env, 'alpha');

        expect(env.expandCalls.length).toBe(1);
        expect(toggle(env.window).textContent).toBe('disable');

        env.expandCalls.length = 0;
        await clickToggle(env);

        // Nothing persisted, but the visitor's choice still took effect.
        expect(env.expandCalls.length).toBe(0);
        expect(toggle(env.window).textContent).toBe('expand terms');
    });
});

describe('the public API', () => {

    test('setExpansionEnabled(false) suppresses expansion without the header control', async () => {
        const env = await boot({ scoring: { EXPANSION_TOGGLE: false } });
        env.window.Scolta.setExpansionEnabled(false);
        await search(env, 'alpha');

        expect(env.expandCalls.length).toBe(0);
        expect(env.window.Scolta.isExpansionEnabled()).toBe(false);
        expect(env.window.localStorage.getItem(STORAGE_KEY)).toBe('1');
    });
});
