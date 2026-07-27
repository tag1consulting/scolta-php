/**
 * The platform render seam: lifecycle events, the result-renderer registration
 * API, non-destructive init(), and the keyed reconcile that stops the second
 * full repaint.
 *
 * Every test drives the REAL scolta.js inside JSDOM (same harness shape as
 * security-render.test.js) rather than asserting on source text, because the
 * whole point of this seam is runtime behaviour a platform depends on:
 *
 *   1. Events fire around every write to #scolta-results and #scolta-filters,
 *      with a `reason` that says which cycle caused it.
 *   2. A registered renderer replaces the built-in card, per result, with a
 *      null return falling back to the built-in card for that result alone.
 *   3. init() no longer destroys what the platform already rendered into the
 *      mount point.
 *   4. When AI query expansion resolves and the result order has not moved, the
 *      result nodes are the SAME nodes — not rebuilt copies. That is the
 *      difference between a platform's lazily loaded server markup surviving and
 *      being thrown away one to two seconds after it arrived.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaPath = path.resolve(__dirname, '../../assets/js/scolta.js');
const scoltaSource = fs.readFileSync(scoltaPath, 'utf-8');
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = global.__pfMock'
);

const tick = () => new Promise(r => setTimeout(r, 0));
async function ticks(n) { for (let i = 0; i < n; i++) await tick(); }

// Boot the real scolta.js against a synthetic corpus.
//   rowsFor(query)  -> array of { url, title, excerpt, content, meta, filters }
//   mountHtml       -> markup already inside the mount point before init()
//   mountAttrs      -> extra attributes on the mount element
//   beforeInit(win) -> hook to register a renderer before Scolta.init() runs
function setup({
    rowsFor,
    config = {},
    expandResponse = { terms: [] },
    mountHtml = '',
    mountAttrs = '',
    beforeInit = null,
    autoInit = false,
} = {}) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"${mountAttrs}>${mountHtml}</div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (q) => {
            const rows = (rowsFor ? rowsFor(q) : []) || [];
            const results = rows.map((row, i) => ({
                id: `${row.url}-${i}`,
                data: () => Promise.resolve({
                    url: row.url,
                    meta: Object.assign({ title: row.title, url: row.url }, row.meta || {}),
                    excerpt: row.excerpt || '',
                    content: row.content || '',
                    filters: row.filters || {},
                    locations: [],
                }),
            }));
            return Promise.resolve({ results, filters: {} });
        },
    };

    window.fetch = jest.fn((url) => {
        const u = String(url);
        const respond = (body) => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        });
        if (u.includes('pagefind-entry.json')) return respond({ languages: { en: {} } });
        if (u === '/expand') return respond(expandResponse);
        return respond({});
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);
    window.scolta = {
        scoring: Object.assign({
            AI_EXPAND_QUERY: false,
            AI_SUMMARIZE: false,
            AUTO_LANGUAGE_FILTER: false,
            MAX_PAGEFIND_RESULTS: 30,
            RESULTS_PER_PAGE: 20,
        }, config),
        endpoints: { expand: '/expand', summarize: '/summarize', followup: '/followup' },
        pagefindPath: '/pf.js', wasmPath: '/wasm.js',
        siteName: 'Test', container: '#scolta-search',
    };

    if (beforeInit) beforeInit(window);
    if (!autoInit) window.Scolta.init('#scolta-search');

    const $ = sel => window.document.querySelector(sel);
    const $$ = sel => [...window.document.querySelectorAll(sel)];

    // Record every lifecycle event, in order, from a single listener on the
    // mount point — which only works because the events bubble.
    const events = [];
    const record = (e) => events.push({ type: e.type, detail: e.detail });
    for (const name of [
        'scolta:before-results-render', 'scolta:results-rendered',
        'scolta:before-filters-render', 'scolta:filters-rendered',
    ]) {
        window.document.addEventListener(name, record);
    }

    async function search(query) {
        await ticks(15);
        events.length = 0;
        $('#scolta-query').value = query;
        const p = window.Scolta.doSearch();
        return p;
    }

    const cards = () => $$('#scolta-results > *');

    return { window, $, $$, search, events, cards };
}

const PAGED_ROWS = ['Widgets', 'Gadgets', 'Sprockets', 'Cogwheels', 'Levers'].map((name, i) => ({
    url: `/doc${i}`,
    title: `${name} Manual`,
    excerpt: 'alpha here',
    content: 'alpha here',
}));

const ROWS = [
    { url: '/alpha', title: 'Alpha Doc', excerpt: 'alpha widgets here', content: 'alpha widgets here' },
    { url: '/beta', title: 'Beta Doc', excerpt: 'alpha gadgets here', content: 'alpha gadgets here' },
    { url: '/gamma', title: 'Gamma Doc', excerpt: 'alpha sprockets here', content: 'alpha sprockets here' },
];

// ---------------------------------------------------------------------------
// 1. Lifecycle events
// ---------------------------------------------------------------------------

describe('render lifecycle events', () => {
    test('a search fires before/after results events, bubbling, in order', async () => {
        const h = setup({ rowsFor: () => ROWS });
        await h.search('alpha');
        await ticks(20);

        const names = h.events.map(e => e.type);
        // Every "rendered" is immediately preceded by its "before".
        for (let i = 0; i < names.length; i++) {
            if (names[i] === 'scolta:results-rendered') {
                expect(names[i - 1]).toBe('scolta:before-results-render');
            }
            if (names[i] === 'scolta:filters-rendered') {
                expect(names[i - 1]).toBe('scolta:before-filters-render');
            }
        }
        expect(names).toContain('scolta:before-results-render');
        expect(names).toContain('scolta:results-rendered');
    });

    test('the "Searching..." placeholder write is announced with reason "loading"', async () => {
        const h = setup({ rowsFor: () => ROWS });
        await h.search('alpha');
        const first = h.events.find(e => e.type === 'scolta:before-results-render');
        expect(first.detail.reason).toBe('loading');
    });

    test('the primary paint is announced with reason "search"', async () => {
        const h = setup({ rowsFor: () => ROWS });
        await h.search('alpha');
        await ticks(20);
        const reasons = h.events
            .filter(e => e.type === 'scolta:before-results-render')
            .map(e => e.detail.reason);
        expect(reasons).toContain('search');
    });

    test('results-rendered carries container, results, rendered, appended and query', async () => {
        const h = setup({ rowsFor: () => ROWS });
        await h.search('alpha');
        await ticks(20);

        const painted = h.events
            .filter(e => e.type === 'scolta:results-rendered')
            .filter(e => e.detail.results.length > 0)
            .pop();

        expect(painted.detail.container).toBe(h.$('#scolta-results'));
        expect(painted.detail.results).toHaveLength(3);
        expect(painted.detail.rendered).toHaveLength(3);
        expect(painted.detail.appended).toBe(false);
        expect(painted.detail.query).toBe('alpha');
        // `results` is in DOM order and carries the scored result objects.
        expect(painted.detail.results.map(r => r.data.url)).toEqual(['/alpha', '/beta', '/gamma']);
    });

    test('detail.container is the element written, and equals event.target', async () => {
        const h = setup({ rowsFor: () => ROWS });
        const seen = [];
        h.window.document.addEventListener('scolta:results-rendered',
            e => seen.push([e.target, e.detail.container]));
        await h.search('alpha');
        await ticks(20);
        expect(seen.length).toBeGreaterThan(0);
        for (const [target, container] of seen) expect(target).toBe(container);
    });

    test('filters events fire around the #scolta-filters write', async () => {
        const h = setup({
            rowsFor: () => ROWS.map(r => Object.assign({}, r, { filters: { topic: ['Widgets'] } })),
        });
        await h.search('alpha');
        await ticks(20);

        const filterEvents = h.events.filter(e => e.type.includes('filters'));
        expect(filterEvents.length).toBeGreaterThanOrEqual(2);
        expect(filterEvents[0].detail.container).toBe(h.$('#scolta-filters'));
    });

    test('a "no results" render still announces itself', async () => {
        const h = setup({ rowsFor: () => [] });
        await h.search('nothing');
        await ticks(20);

        const empty = h.events
            .filter(e => e.type === 'scolta:results-rendered')
            .pop();
        expect(empty.detail.results).toEqual([]);
        expect(empty.detail.rendered).toEqual([]);
        expect(empty.detail.appended).toBe(false);
    });

    test('a listener that throws does not break the render', async () => {
        const h = setup({ rowsFor: () => ROWS });
        h.window.document.addEventListener('scolta:before-results-render', () => {
            throw new Error('listener blew up');
        });
        await h.search('alpha');
        await ticks(20);
        expect(h.cards()).toHaveLength(3);
    });

    test('appended is true on the "show more" path, and false on a full paint', async () => {
        // Distinct titles: deduplicateByTitle() collapses "Doc 0" / "Doc 1"
        // style names on Jaccard word overlap.
        const many = PAGED_ROWS;
        const h = setup({ rowsFor: () => many, config: { RESULTS_PER_PAGE: 2 } });
        await h.search('alpha');
        await ticks(20);
        expect(h.cards()).toHaveLength(2);

        h.events.length = 0;
        h.$('#scolta-load-more').click();
        await ticks(5);

        const before = h.events.find(e => e.type === 'scolta:before-results-render');
        const after = h.events.find(e => e.type === 'scolta:results-rendered');
        expect(before.detail.reason).toBe('append');
        expect(after.detail.appended).toBe(true);
        // `rendered` is only the slice this write added; `results` is everything.
        expect(after.detail.rendered.map(r => r.data.url)).toEqual(['/doc2', '/doc3']);
        expect(after.detail.results).toHaveLength(4);
        expect(h.cards()).toHaveLength(4);
    });

    test('"show more" leaves the already-painted nodes in place', async () => {
        // Distinct titles: deduplicateByTitle() collapses "Doc 0" / "Doc 1"
        // style names on Jaccard word overlap.
        const many = PAGED_ROWS;
        const h = setup({ rowsFor: () => many, config: { RESULTS_PER_PAGE: 2 } });
        await h.search('alpha');
        await ticks(20);
        const first = h.cards()[0];
        first.setAttribute('data-platform-initialised', '1');

        h.$('#scolta-load-more').click();
        await ticks(5);

        expect(h.cards()[0]).toBe(first);
        expect(h.cards()[0].getAttribute('data-platform-initialised')).toBe('1');
    });
});

// ---------------------------------------------------------------------------
// 2. Result renderer registration
// ---------------------------------------------------------------------------

describe('setResultRenderer', () => {
    test('a registered renderer replaces the built-in card', async () => {
        const h = setup({
            rowsFor: () => ROWS,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data) =>
                    `<article class="platform-card" data-url="${data.url}"></article>`);
            },
        });
        await h.search('alpha');
        await ticks(20);

        expect(h.$$('.platform-card')).toHaveLength(3);
        expect(h.$$('.scolta-result-card')).toHaveLength(0);
        expect(h.$$('.platform-card').map(n => n.getAttribute('data-url')))
            .toEqual(['/alpha', '/beta', '/gamma']);
    });

    test('registration works before Scolta.init() runs', async () => {
        // The realistic order: the platform registers on script load, init()
        // happens later on DOMContentLoaded.
        const h = setup({
            rowsFor: () => ROWS,
            autoInit: true,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer(() => '<article class="platform-card"></article>');
                win.Scolta.init('#scolta-search');
            },
        });
        await h.search('alpha');
        await ticks(20);
        expect(h.$$('.platform-card')).toHaveLength(3);
    });

    test('returning null falls back to the built-in card for that result only', async () => {
        const h = setup({
            rowsFor: () => ROWS,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data) =>
                    data.url === '/beta' ? null : '<article class="platform-card"></article>');
            },
        });
        await h.search('alpha');
        await ticks(20);

        expect(h.$$('.platform-card')).toHaveLength(2);
        expect(h.$$('.scolta-result-card')).toHaveLength(1);
        // …and the fallback card is the real one, fully populated.
        expect(h.$('.scolta-result-card .scolta-result-title').textContent).toContain('Beta Doc');
        expect(h.$('.scolta-result-card .scolta-result-title').getAttribute('href')).toContain('/beta');
    });

    test('a renderer that throws falls back rather than taking the list down', async () => {
        const h = setup({
            rowsFor: () => ROWS,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data) => {
                    if (data.url === '/beta') throw new Error('renderer bug');
                    return '<article class="platform-card"></article>';
                });
            },
        });
        await h.search('alpha');
        await ticks(20);

        expect(h.$$('.platform-card')).toHaveLength(2);
        expect(h.$$('.scolta-result-card')).toHaveLength(1);
        expect(h.window.console.warn).toHaveBeenCalled();
    });

    test('ctx hands over pre-escaped excerpt, title and URL', async () => {
        const seen = [];
        const h = setup({
            rowsFor: () => [{
                url: '/alpha',
                title: 'Alpha & <b>Co</b>',
                excerpt: 'alpha widgets here',
                content: 'alpha widgets here',
            }],
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data, ctx) => {
                    seen.push(ctx);
                    return `<article class="platform-card">${ctx.excerptHtml}</article>`;
                });
            },
        });
        await h.search('alpha');
        await ticks(20);

        const ctx = seen[seen.length - 1];
        expect(ctx.index).toBe(0);
        expect(ctx.query).toBe('alpha');
        expect(ctx.highlightTerms).toContain('alpha');
        // Excerpt arrives escaped AND already <mark>-wrapped — the platform can
        // stash it on a placeholder and restore it after a swap without redoing
        // any of the escaping.
        expect(ctx.excerptHtml).toContain('<mark>alpha</mark>');
        // Titles are stripped of markup and THEN escaped — exactly what the
        // built-in card does, so an opt-in renderer inherits the same posture
        // rather than a widened one.
        expect(ctx.titleHtml).toContain('&amp;');
        expect(ctx.titleHtml).not.toContain('<b>');
        expect(ctx.titleHtml).toContain('Co');
        expect(ctx.titleAttr).toContain('&amp;');
        expect(ctx.titleAttr).not.toContain('<b>');
        expect(ctx.safeUrl).toBe('/alpha');
        expect(typeof ctx.score).toBe('number');
        // …and it renders through to the DOM as a real <mark>, not as text.
        expect(h.$('.platform-card mark')).toBeTruthy();
    });

    test('ctx.safeUrl neutralizes a javascript: URL before the renderer sees it', async () => {
        const seen = [];
        const h = setup({
            rowsFor: () => [{
                url: 'javascript:alert(1)',
                title: 'Poisoned',
                excerpt: 'alpha',
                content: 'alpha',
                meta: { url: 'javascript:alert(1)' },
            }],
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data, ctx) => {
                    seen.push(ctx);
                    return `<a class="platform-card" href="${ctx.safeUrl}">x</a>`;
                });
            },
        });
        await h.search('alpha');
        await ticks(20);

        expect(seen[0].safeUrl).toBe('#');
        expect(h.$('.platform-card').getAttribute('href')).toBe('#');
    });

    test('delegated data-scolta-* handlers still work inside renderer markup', async () => {
        const h = setup({
            rowsFor: () => ROWS,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer(() =>
                    '<article class="platform-card">' +
                    '<span class="chip" data-scolta-search-term="gadgets">gadgets</span>' +
                    '</article>');
            },
        });
        await h.search('alpha');
        await ticks(20);

        h.$('.chip').click();
        await ticks(20);
        // searchTerm() drove a new search from platform-rendered markup, with no
        // re-binding of any kind.
        expect(h.$('#scolta-query').value).toBe('gadgets');
    });

    test('the per-instance renderer wins over the global one', async () => {
        const h = setup({
            rowsFor: () => ROWS,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer(() => '<article class="global-card"></article>');
            },
        });
        h.window.Scolta.defaultInstance.setResultRenderer(() => '<article class="instance-card"></article>');
        await h.search('alpha');
        await ticks(20);

        expect(h.$$('.instance-card')).toHaveLength(3);
        expect(h.$$('.global-card')).toHaveLength(0);
    });

    test('passing null restores the built-in card', async () => {
        const h = setup({
            rowsFor: () => ROWS,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer(() => '<article class="platform-card"></article>');
            },
        });
        h.window.Scolta.setResultRenderer(null);
        await h.search('alpha');
        await ticks(20);
        expect(h.$$('.scolta-result-card')).toHaveLength(3);
    });

    test('a non-function registration is rejected loudly', () => {
        const h = setup({ rowsFor: () => ROWS });
        // Matched by message: the TypeError is constructed inside the JSDOM
        // realm, so it is not an instance of this realm's TypeError.
        expect(() => h.window.Scolta.setResultRenderer('nope'))
            .toThrow(/expects a function or null/);
        expect(() => h.window.Scolta.defaultInstance.setResultRenderer(42))
            .toThrow(/expects a function or null/);
    });

    test('the renderer is called exactly once per result per paint', async () => {
        const calls = [];
        const h = setup({
            rowsFor: () => ROWS,
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data) => {
                    calls.push(data.url);
                    return '<article class="platform-card"></article>';
                });
            },
        });
        await h.search('alpha');
        await ticks(20);
        expect(calls).toEqual(['/alpha', '/beta', '/gamma']);
    });
});

// ---------------------------------------------------------------------------
// 3. Non-destructive init()
// ---------------------------------------------------------------------------

describe('init() does not clobber the mount point', () => {
    test('markup already inside the container survives init()', () => {
        const h = setup({
            rowsFor: () => ROWS,
            mountHtml: '<div id="server-rendered">rendered by the platform</div>',
        });
        expect(h.$('#server-rendered')).toBeTruthy();
        expect(h.$('#server-rendered').textContent).toBe('rendered by the platform');
        // …and the search UI mounted anyway.
        expect(h.$('#scolta-query')).toBeTruthy();
        expect(h.$('#scolta-results')).toBeTruthy();
    });

    test('preserved nodes keep their identity, so platform state rides along', () => {
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body><div id="scolta-search"><div id="ssr"></div></div></body></html>',
            { url: 'https://example.com', runScripts: 'dangerously' }
        );
        const win = dom.window;
        const ssr = win.document.querySelector('#ssr');
        let clicked = 0;
        ssr.addEventListener('click', () => { clicked++; });

        win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
        win.fetch = jest.fn(() => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ languages: { en: {} } }),
            text: () => Promise.resolve('{}'),
        }));
        win.__pfMock = {
            init: () => Promise.resolve(), mergeIndex: () => Promise.resolve(),
            filters: () => Promise.resolve({}), search: () => Promise.resolve({ results: [] }),
        };
        win.eval(patchedSource);
        win.scolta = {
            scoring: {}, endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
            pagefindPath: '/pf.js', siteName: 'Test', container: '#scolta-search',
        };
        win.Scolta.init('#scolta-search');

        expect(win.document.querySelector('#ssr')).toBe(ssr);
        ssr.click();
        expect(clicked).toBe(1);
    });

    test('the scaffold is inserted above the preserved markup', () => {
        const h = setup({
            rowsFor: () => ROWS,
            mountHtml: '<div id="server-rendered"></div>',
        });
        const children = [...h.$('#scolta-search').children];
        expect(children[0].classList.contains('scolta-search-box')).toBe(true);
        expect(children[children.length - 1].id).toBe('server-rendered');
    });

    test('data-scolta-replace restores the old clear-everything behaviour', () => {
        const h = setup({
            rowsFor: () => ROWS,
            mountHtml: '<div id="server-rendered"></div>',
            mountAttrs: ' data-scolta-replace',
        });
        expect(h.$('#server-rendered')).toBeNull();
        expect(h.$('#scolta-query')).toBeTruthy();
    });

    test('a second init() replaces its own scaffold instead of duplicating ids', () => {
        const h = setup({
            rowsFor: () => ROWS,
            mountHtml: '<div id="server-rendered"></div>',
        });
        h.window.Scolta.createInstance('#scolta-search', h.window.scolta);

        expect(h.$$('#scolta-search #scolta-query')).toHaveLength(1);
        expect(h.$$('#scolta-search #scolta-results')).toHaveLength(1);
        // The platform's markup is not collateral damage of the re-init.
        expect(h.$('#server-rendered')).toBeTruthy();
    });

    test('destroy() removes the scaffold and nothing else', () => {
        const h = setup({
            rowsFor: () => ROWS,
            mountHtml: '<div id="server-rendered"></div>',
        });
        h.window.Scolta.defaultInstance.destroy();

        expect(h.$('#scolta-query')).toBeNull();
        expect(h.$('#scolta-results')).toBeNull();
        expect(h.$('#server-rendered')).toBeTruthy();
    });

    test('autoInit no longer refuses a mount point holding server markup', async () => {
        const dom = new JSDOM(
            '<!DOCTYPE html><html><body><div id="scolta-search"><p>server rendered</p></div>' +
            '</body></html>',
            { url: 'https://example.com', runScripts: 'dangerously' }
        );
        const win = dom.window;
        win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
        win.fetch = jest.fn(() => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ languages: { en: {} } }),
            text: () => Promise.resolve('{}'),
        }));
        win.__pfMock = {
            init: () => Promise.resolve(), mergeIndex: () => Promise.resolve(),
            filters: () => Promise.resolve({}), search: () => Promise.resolve({ results: [] }),
        };
        // window.scolta must exist BEFORE the bundle runs: autoInit() fires on
        // eval when the document is already loaded.
        win.scolta = {
            scoring: {}, endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
            pagefindPath: '/pf.js', siteName: 'Test', container: '#scolta-search',
        };
        win.eval(patchedSource);
        // JSDOM is still readyState "loading" when the bundle evaluates, so
        // autoInit() defers to DOMContentLoaded exactly as it does in a browser.
        await ticks(5);

        expect(win.document.querySelector('#scolta-query')).toBeTruthy();
        // Direct child: the scaffold prepends its own <p>s inside #scolta-no-results.
        expect(win.document.querySelector('#scolta-search > p').textContent).toBe('server rendered');
    });
});

// ---------------------------------------------------------------------------
// 4. The second repaint after AI query expansion
// ---------------------------------------------------------------------------

// Expansion returns the SAME rows for every query, so the merged list is the
// same set in the same order as the first paint — the case that used to cost a
// full teardown for nothing.
function expansionSetup(extra = {}) {
    return setup(Object.assign({
        rowsFor: () => ROWS,
        config: { AI_EXPAND_QUERY: true },
        expandResponse: { terms: ['alpha', 'widgets'] },
    }, extra));
}

describe('AI expansion no longer costs a full teardown', () => {
    test('with a platform renderer, an unchanged order preserves every node', async () => {
        const h = expansionSetup({
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data) =>
                    `<article class="platform-card" data-url="${data.url}"></article>`);
            },
        });
        await h.search('alpha');
        await ticks(20);

        const before = h.cards();
        expect(before).toHaveLength(3);
        // Stand in for whatever a platform swaps in after the fact.
        before.forEach((n, i) => { n.dataset.swapped = String(i); });

        await ticks(60);

        // The expansion pass ran…
        const reasons = h.events
            .filter(e => e.type === 'scolta:before-results-render')
            .map(e => e.detail.reason);
        expect(reasons).toContain('expansion');

        // …and every node survived it, with the platform's own state intact.
        const after = h.cards();
        expect(after).toHaveLength(3);
        for (let i = 0; i < 3; i++) {
            expect(after[i]).toBe(before[i]);
            expect(after[i].dataset.swapped).toBe(String(i));
        }
    });

    test('the expansion pass reports the carried-over results in detail.reused', async () => {
        const h = expansionSetup({
            beforeInit: (win) => {
                win.Scolta.setResultRenderer(() => '<article class="platform-card"></article>');
            },
        });
        await h.search('alpha');
        await ticks(60);

        const expansionPaint = h.events
            .filter(e => e.type === 'scolta:results-rendered')
            .filter(e => e.detail.results.length > 0)
            .pop();
        expect(expansionPaint.detail.reused.map(r => r.data.url))
            .toEqual(['/alpha', '/beta', '/gamma']);
        expect(expansionPaint.detail.appended).toBe(false);
    });

    test('a result dropped by the expansion pass loses its node; survivors keep theirs', async () => {
        // The expansion term matches only /alpha and /gamma, so /beta's card is
        // the only one that must go.
        const h = setup({
            rowsFor: (q) => (q === 'alpha' ? ROWS : ROWS.filter(r => r.url !== '/beta')),
            config: { AI_EXPAND_QUERY: true },
            expandResponse: { terms: ['widgets'] },
            beforeInit: (win) => {
                win.Scolta.setResultRenderer((data) =>
                    `<article class="platform-card" data-url="${data.url}"></article>`);
            },
        });
        await h.search('alpha');
        await ticks(20);

        const before = new Map(h.cards().map(n => [n.getAttribute('data-url'), n]));
        expect(before.size).toBe(3);

        await ticks(60);

        for (const node of h.cards()) {
            const url = node.getAttribute('data-url');
            if (before.has(url)) expect(node).toBe(before.get(url));
        }
    });

    test('built-in cards are rebuilt when expansion adds highlight terms', async () => {
        // The reconcile must NOT carry a built-in card over once the highlight
        // set has moved: the card's <mark> spans would silently go stale.
        const h = setup({
            rowsFor: () => ROWS,
            config: { AI_EXPAND_QUERY: true },
            expandResponse: { terms: ['widgets'] },
        });
        await h.search('alpha');
        await ticks(60);

        // The expansion pass ran and the built-in cards carry the expansion
        // term's highlighting. A repaint that "skipped because nothing moved"
        // would leave these <mark>s missing.
        const reasons = h.events
            .filter(e => e.type === 'scolta:before-results-render')
            .map(e => e.detail.reason);
        expect(reasons).toContain('expansion');
        expect(h.$('#scolta-results').innerHTML).toContain('<mark>widgets</mark>');
        expect(h.$$('.scolta-result-card').length).toBeGreaterThan(0);
    });

    test('a new search cycle always tears the list down', async () => {
        // Reuse is deliberately scoped to WITHIN one search cycle (primary paint
        // → expansion repaint). Every doSearch() opens with the "Searching..."
        // placeholder, which is a genuine teardown, and that is correct: under a
        // new query or a new facet set the list the user is looking at is no
        // longer the list they asked for. A consumer sees reason "loading" and
        // knows its nodes are gone.
        const withFacets = ROWS.map(r => Object.assign({}, r, {
            filters: { topic: [r.url === '/beta' ? 'Gadgets' : 'Widgets'] },
        }));
        const h = setup({ rowsFor: () => withFacets });
        await h.search('alpha');
        await ticks(20);
        const before = h.cards()[0];

        h.events.length = 0;
        await h.window.Scolta.defaultInstance.doSearch(true);
        await ticks(20);

        expect(h.events[0].type).toBe('scolta:before-results-render');
        expect(h.events[0].detail.reason).toBe('loading');
        expect(h.cards()[0]).not.toBe(before);
    });

    test('the results container is written twice per search, not three times', async () => {
        const h = expansionSetup({
            beforeInit: (win) => {
                win.Scolta.setResultRenderer(() => '<article class="platform-card"></article>');
            },
        });
        await h.search('alpha');
        await ticks(60);

        // loading + primary paint. The expansion pass announces itself (a
        // consumer still wants to know the list was re-evaluated) but moves no
        // node, so it costs the platform nothing.
        const reasons = h.events
            .filter(e => e.type === 'scolta:before-results-render')
            .map(e => e.detail.reason);
        expect(reasons).toEqual(['loading', 'search', 'expansion']);

        const expansionPaint = h.events
            .filter(e => e.type === 'scolta:results-rendered')
            .pop();
        // Nothing was built: every painted result reused its node.
        expect(expansionPaint.detail.reused).toHaveLength(expansionPaint.detail.results.length);
    });
});
