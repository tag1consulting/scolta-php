/**
 * Search as you type (SAYT).
 *
 * Drives the REAL assets/js/scolta.js in JSDOM, same harness shape as
 * preload.test.js and security-render.test.js. What is pinned here:
 *
 *   - the suggest cycle: debounce collapse, the grapheme min-chars floor, the
 *     fragment-load cap, and that NO suggest search ever carries a filters
 *     option (a loaded Pagefind filter chunk taxes every later search for the
 *     life of the page and cannot be unloaded);
 *   - staleness at every await point — a superseded cycle performs zero DOM
 *     writes and loads zero fragments;
 *   - the keyboard and pointer contract, including both Enter paths;
 *   - saytSuggestionAction in both modes, the recent-suggestion always-search
 *     rule, and the unknown-value clamp;
 *   - recent searches on and off, escaping of stored values, and the
 *     storage-throws path;
 *   - the AI enrichment budget: calls stop at the cap, degrade silently, and
 *     resume when the sliding window rolls;
 *   - the off switch: saytEnabled false restores the pre-1.1.0 input handler
 *     exactly, with no dropdown node and no combobox roles.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = window.__pfMock'
);

const RECENT_KEY = 'scolta:recent-searches';

const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));
async function ticks(n) { for (let i = 0; i < n; i++) await tick(0); }

function deferred() {
    let resolve;
    const promise = new Promise(res => { resolve = res; });
    return { promise, resolve };
}

/**
 * Boot the real bundle.
 *
 *   rowsFor(query)  -> [{ url, title, excerpt, content }]
 *   searchImpl      -> full override of pagefind.search, for gated promises
 *   sayt            -> top-level sayt* instance config
 *   scoring         -> scoring overrides (AI is off unless a test turns it on)
 *   recent          -> array seeded into localStorage before any typing
 *   breakStorage    -> make every localStorage access throw
 */
function setup({
    rowsFor = () => [],
    searchImpl = null,
    sayt = {},
    scoring = {},
    expandResponse = { terms: [] },
    recent = null,
    breakStorage = false,
} = {}) {
    // A bare VirtualConsole swallows JSDOM's "Not implemented: navigation"
    // jsdomError, which is exactly what following a suggestion link produces.
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        {
            url: 'https://example.com/',
            runScripts: 'dangerously',
            virtualConsole: new VirtualConsole(),
        }
    );
    const window = dom.window;

    const searchCalls = [];   // every pagefind.search(query, opts) call
    const loaded = [];        // every fragment .data() actually fetched
    const rendered = [];      // every scolta:suggestions-rendered detail

    function resultsFor(q) {
        const rows = rowsFor(q) || [];
        return {
            results: rows.map((row, i) => ({
                id: `${q}-${i}`,
                data: () => {
                    loaded.push(row.url);
                    return Promise.resolve({
                        url: row.url,
                        meta: Object.assign({ title: row.title }, row.meta || {}),
                        excerpt: row.excerpt || '',
                        content: row.content || '',
                        locations: [],
                    });
                },
            })),
        };
    }

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        preload: jest.fn(() => Promise.resolve()),
        search: (q, opts) => {
            searchCalls.push({ query: q, opts: opts });
            if (searchImpl) {
                const out = searchImpl(q, opts, resultsFor);
                if (out) return out;
            }
            return Promise.resolve(resultsFor(q));
        },
    };

    const expandCalls = [];
    window.fetch = jest.fn((url) => {
        const u = String(url);
        const respond = (body) => Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        });
        if (u.includes('pagefind-entry.json')) return respond({ languages: { en: {} } });
        if (u === '/expand') {
            expandCalls.push(u);
            return respond(typeof expandResponse === 'function'
                ? expandResponse(expandCalls.length)
                : expandResponse);
        }
        return respond({});
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    if (recent) {
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent));
    }
    if (breakStorage) {
        // Safari private browsing and some enterprise policies throw on access,
        // not just on write. Nothing in the search box may care.
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get() { throw new Error('storage denied'); },
        });
    }

    window.eval(patchedSource);
    window.scolta = Object.assign({
        scoring: Object.assign({
            AI_EXPAND_QUERY: false,
            AI_SUMMARIZE: false,
            AUTO_LANGUAGE_FILTER: false,
            MAX_PAGEFIND_RESULTS: 30,
        }, scoring),
        endpoints: { expand: '/expand', summarize: '/summarize', followup: '/followup' },
        pagefindPath: '/pf.js',
        wasmPath: '/wasm.js',
        siteName: 'Test',
        container: '#scolta-search',
    }, sayt);

    window.Scolta.init('#scolta-search');

    const $ = sel => window.document.querySelector(sel);
    const $$ = sel => [...window.document.querySelectorAll(sel)];

    window.document.addEventListener('scolta:suggestions-rendered', (e) => {
        rendered.push({ query: e.detail.query, count: e.detail.suggestions.length });
    });

    function type(value) {
        const input = $('#scolta-query');
        input.value = value;
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
    }

    function key(name) {
        const input = $('#scolta-query');
        const e = new window.KeyboardEvent('keydown', {
            key: name, bubbles: true, cancelable: true,
        });
        input.dispatchEvent(e);
        return e;
    }

    const options = () => $$('#scolta-sayt [role="option"]');
    const isOpen = () => $('#scolta-sayt') && $('#scolta-sayt').style.display === 'block';
    // Every search that is not the initPagefind() warm-up.
    const realSearches = () => searchCalls.filter(c => c.query !== '');

    return {
        window, $, $$, type, key, options, isOpen, realSearches,
        searchCalls, loaded, rendered, expandCalls,
    };
}

/** Let initPagefind() finish assigning the module and warming the index. */
async function boot(h) {
    await ticks(15);
    return h;
}

const DOCS = {
    ka: [
        { url: '/kamado-basics', title: 'Kamado Basics', excerpt: 'ceramic grill' },
        { url: '/kamado-pizza', title: 'Kamado Pizza', excerpt: 'stone and fire' },
    ],
};

// ---------------------------------------------------------------------------

describe('suggest cycle: debounce, floors and caps', () => {
    test('rapid typing collapses to a single suggest search for the final term', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 40 },
            rowsFor: q => (q === 'kamado' ? DOCS.ka : []),
        }));

        for (const value of ['k', 'ka', 'kam', 'kama', 'kamad', 'kamado']) {
            h.type(value);
            await tick(5);
        }
        await tick(120);

        expect(h.realSearches().map(c => c.query)).toEqual(['kamado']);
    });

    test('below the min-chars floor nothing is searched', async () => {
        const h = await boot(setup({ sayt: { saytDebounceMs: 10, saytMinChars: 3 } }));

        h.type('ka');
        await tick(60);

        expect(h.realSearches()).toHaveLength(0);
        expect(h.isOpen()).toBe(false);
    });

    test('the floor counts graphemes, not UTF-16 code units', async () => {
        // "\u{1F1EE}\u{1F1F9}" is ONE character to the person typing it, two
        // code points, and four UTF-16 units. At a floor of 2 it must not
        // search; adding one real character must.
        const flag = '\u{1F1EE}\u{1F1F9}';
        expect(flag.length).toBe(4);

        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytMinChars: 2 },
            rowsFor: () => DOCS.ka,
        }));

        h.type(flag);
        await tick(60);
        expect(h.realSearches()).toHaveLength(0);

        h.type(flag + 'a');
        await tick(60);
        expect(h.realSearches().map(c => c.query)).toEqual([flag + 'a']);
    });

    test('the grapheme counter keeps a spread fallback for engines without Intl.Segmenter', () => {
        const fn = scoltaSource.match(/function saytGraphemeLength\([\s\S]*?\n  \}/);
        expect(fn).toBeTruthy();
        expect(fn[0]).toContain('Intl.Segmenter');
        expect(fn[0]).toContain('[...s].length');
    });

    test('fragment loads are capped at saytMaxSuggestions per pass', async () => {
        const many = [];
        for (let i = 0; i < 20; i++) {
            many.push({ url: `/doc-${i}`, title: `Grilling Guide Number ${i}`, excerpt: 'x' });
        }
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytMaxSuggestions: 4 },
            rowsFor: () => many,
        }));

        h.type('grill');
        await tick(80);

        expect(h.loaded).toHaveLength(4);
        expect(h.options().length).toBeLessThanOrEqual(4);
    });

    test('a multi-word prefix that ANDs to nothing falls back to per-term OR', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: q => (q === 'chocolate'
                ? [{ url: '/brownies', title: 'Chocolate Brownies', excerpt: 'cocoa' }]
                : []),
        }));

        h.type('chocolate br');
        await tick(80);

        const queries = h.realSearches().map(c => c.query);
        expect(queries).toContain('chocolate br');   // the AND attempt
        expect(queries).toContain('chocolate');      // the OR fallback
        expect(queries).toContain('br');
        expect(h.options()).toHaveLength(1);
        expect(h.options()[0].textContent).toContain('Chocolate Brownies');
    });

    test('no suggest search ever carries a filters option', async () => {
        // Naming a dimension makes Pagefind fetch that dimension's filter chunk,
        // and a loaded chunk taxes every later search with a per-matched-result
        // scan for the life of the page. A keystroke-rate path must never be
        // what triggers that.
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));

        h.type('kamado');
        await tick(80);
        h.type('kamado joe');
        await tick(80);

        expect(h.realSearches().length).toBeGreaterThan(0);
        for (const call of h.searchCalls) {
            expect(call.opts === undefined || call.opts.filters === undefined).toBe(true);
        }
    });
});

// ---------------------------------------------------------------------------

describe('staleness: a superseded cycle writes nothing', () => {
    function gatedSetup(gate, gatedQuery, extra = {}) {
        return setup(Object.assign({
            sayt: { saytDebounceMs: 10 },
            searchImpl: (q, _opts, resultsFor) => {
                if (q === gatedQuery) return gate.promise.then(() => resultsFor(q));
                return null;
            },
            rowsFor: () => DOCS.ka,
        }, extra));
    }

    test('a search that resolves after newer input is discarded', async () => {
        const gate = deferred();
        const h = await boot(gatedSetup(gate, 'kam'));

        h.type('kam');
        await tick(40);            // cycle started, parked on the gated search
        h.type('kamado');          // supersede it
        await tick(40);

        gate.resolve();
        await ticks(20);

        expect(h.rendered.map(r => r.query)).not.toContain('kam');
        expect(h.rendered.map(r => r.query)).toContain('kamado');
    });

    test('a search that resolves after the input was cleared is discarded', async () => {
        const gate = deferred();
        const h = await boot(gatedSetup(gate, 'kam'));

        h.type('kam');
        await tick(40);
        h.$('#scolta-search-clear').click();
        await tick(10);

        gate.resolve();
        await ticks(20);

        expect(h.rendered.map(r => r.query)).not.toContain('kam');
        expect(h.isOpen()).toBe(false);
    });

    test('a search that resolves after a full search started is discarded', async () => {
        const gate = deferred();
        const h = await boot(gatedSetup(gate, 'kam'));

        h.type('kam');
        await tick(40);
        h.$('#scolta-query').value = 'kamado';
        h.window.Scolta.doSearch();
        await ticks(10);

        gate.resolve();
        await ticks(20);

        expect(h.rendered.map(r => r.query)).not.toContain('kam');
        expect(h.isOpen()).toBe(false);
    });

    test('a fragment load that resolves after newer input loads nothing further and paints nothing', async () => {
        // The second await point in the cycle: rows are in hand, .data() is in
        // flight. A stale cycle must not reach the scoring or render path.
        const gate = deferred();
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: q => (q === 'kam'
                ? [{ url: '/gated', title: 'Gated Doc', excerpt: 'x' }]
                : DOCS.ka),
            searchImpl: (q, _opts, resultsFor) => {
                if (q !== 'kam') return null;
                const real = resultsFor(q);
                return Promise.resolve({
                    results: real.results.map(r => ({
                        id: r.id,
                        data: () => gate.promise.then(() => r.data()),
                    })),
                });
            },
        }));

        h.type('kam');
        await tick(40);
        h.type('kamado');
        await tick(60);

        const before = h.rendered.length;
        gate.resolve();
        await ticks(20);

        expect(h.rendered.length).toBe(before);
        expect(h.rendered.map(r => r.query)).not.toContain('kam');
    });
});

// ---------------------------------------------------------------------------

describe('keyboard contract', () => {
    async function openDropdown(extra = {}) {
        const h = await boot(setup(Object.assign({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }, extra)));
        h.type('kamado');
        await tick(80);
        expect(h.options()).toHaveLength(2);
        return h;
    }

    test('ArrowDown and ArrowUp move the active option and wrap at both ends', async () => {
        const h = await openDropdown();
        const input = h.$('#scolta-query');
        input.focus();

        expect(input.hasAttribute('aria-activedescendant')).toBe(false);

        h.key('ArrowDown');
        expect(h.options()[0].getAttribute('aria-selected')).toBe('true');
        expect(input.getAttribute('aria-activedescendant')).toBe(h.options()[0].id);

        h.key('ArrowDown');
        expect(h.options()[1].getAttribute('aria-selected')).toBe('true');
        expect(h.options()[0].getAttribute('aria-selected')).toBe('false');

        h.key('ArrowDown');   // wraps forward
        expect(h.options()[0].getAttribute('aria-selected')).toBe('true');

        h.key('ArrowUp');     // wraps backward
        expect(h.options()[1].getAttribute('aria-selected')).toBe('true');

        // DOM focus never leaves the input: the combobox pattern tracks the
        // active option through aria-activedescendant, so typing keeps working
        // while a screen reader announces the highlighted row.
        expect(h.window.document.activeElement).toBe(input);
        expect(h.$('#scolta-sayt').contains(h.window.document.activeElement)).toBe(false);
    });

    test('ArrowDown from nothing selects the first option, ArrowUp the last', async () => {
        const h = await openDropdown();
        h.key('ArrowUp');
        expect(h.options()[1].getAttribute('aria-selected')).toBe('true');
    });

    test('Enter with no active option runs the full search, exactly as before', async () => {
        const h = await openDropdown();

        const e = h.key('Enter');
        expect(e.defaultPrevented).toBe(false);
        await ticks(20);

        // The results region painted, which only doSearch() does.
        expect(h.$('#scolta-results-header').textContent).toContain('results for');
        expect(h.isOpen()).toBe(false);
    });

    test('Enter with an active option acts on it and does not run the typed query', async () => {
        const h = await openDropdown({ sayt: { saytDebounceMs: 10, saytSuggestionAction: 'search' } });

        h.key('ArrowDown');
        const e = h.key('Enter');
        expect(e.defaultPrevented).toBe(true);
        await ticks(20);

        expect(h.$('#scolta-query').value).toBe('Kamado Basics');
        expect(h.isOpen()).toBe(false);
    });

    test('Escape closes the dropdown without clearing the input; a second Escape is not consumed', async () => {
        const h = await openDropdown();

        const first = h.key('Escape');
        expect(first.defaultPrevented).toBe(true);
        expect(h.isOpen()).toBe(false);
        expect(h.$('#scolta-query').value).toBe('kamado');
        expect(h.$('#scolta-query').getAttribute('aria-expanded')).toBe('false');

        const second = h.key('Escape');
        expect(second.defaultPrevented).toBe(false);
    });

    test('arrow keys are not consumed while the dropdown is closed', async () => {
        const h = await openDropdown();
        h.key('Escape');

        const e = h.key('ArrowDown');
        expect(e.defaultPrevented).toBe(false);
    });
});

// ---------------------------------------------------------------------------

describe('acting on a suggestion', () => {
    test('navigate mode renders a title suggestion as a link to the sanitized URL', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytSuggestionAction: 'navigate' },
            rowsFor: () => DOCS.ka,
        }));
        h.type('kamado');
        await tick(80);

        const first = h.options()[0];
        expect(first.tagName).toBe('A');
        expect(first.getAttribute('href')).toBe('/kamado-basics');

        first.click();
        await ticks(10);

        // Navigation, not a search: the results region was never painted.
        expect(h.$('#scolta-results-header').textContent).toBe('');
        expect(h.isOpen()).toBe(false);
    });

    test('navigate mode never emits a javascript: href', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => [{
                url: 'javascript:alert(1)',
                title: 'Poisoned Doc',
                meta: { url: 'javascript:alert(1)' },
            }],
        }));
        h.type('poison');
        await tick(80);

        const first = h.options()[0];
        expect(first.tagName).toBe('DIV');          // never rendered as a link
        expect(first.hasAttribute('href')).toBe(false);
    });

    test('search mode puts the title in the box and runs the full search', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytSuggestionAction: 'search' },
            rowsFor: () => DOCS.ka,
        }));
        h.type('kamado');
        await tick(80);

        expect(h.options()[0].tagName).toBe('DIV');
        h.options()[0].click();
        await ticks(20);

        expect(h.$('#scolta-query').value).toBe('Kamado Basics');
        expect(h.$('#scolta-results-header').textContent).toContain('results for');
    });

    test('a recent suggestion always runs the search, even in navigate mode', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytSuggestionAction: 'navigate' },
            recent: ['kamado joe pizza'],
            rowsFor: () => DOCS.ka,
        }));
        h.type('kamado');
        await tick(80);

        const recentOption = h.options()[0];
        expect(recentOption.className).toContain('scolta-sayt-option-recent');
        expect(recentOption.tagName).toBe('DIV');   // navigating a stored query is meaningless

        recentOption.click();
        await ticks(20);

        expect(h.$('#scolta-query').value).toBe('kamado joe pizza');
        expect(h.$('#scolta-results-header').textContent).toContain('results for');
    });

    test('an unknown saytSuggestionAction clamps to navigate and warns once', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytSuggestionAction: 'teleport' },
            rowsFor: () => DOCS.ka,
        }));
        h.type('kamado');
        await tick(80);

        expect(h.options()[0].tagName).toBe('A');   // clamped to navigate

        const warnings = h.window.console.warn.mock.calls
            .filter(args => String(args[0]).includes('sayt_suggestion_action'));
        expect(warnings).toHaveLength(1);
    });
});

// ---------------------------------------------------------------------------

describe('recent searches', () => {
    test('a committed search is stored and offered back on a matching prefix', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));

        h.$('#scolta-query').value = 'kamado pizza stone';
        await h.window.Scolta.doSearch();
        await ticks(20);

        expect(JSON.parse(h.window.localStorage.getItem(RECENT_KEY)))
            .toEqual(['kamado pizza stone']);

        h.type('kamado');
        await tick(80);
        expect(h.options()[0].textContent).toContain('kamado pizza stone');
    });

    test('recents are capped at saytMaxRecent and come before title suggestions', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytMaxRecent: 2, saytMaxSuggestions: 6 },
            recent: ['kamado a', 'kamado b', 'kamado c', 'kamado d'],
            rowsFor: () => DOCS.ka,
        }));

        h.type('kamado');
        await tick(80);

        const opts = h.options();
        expect(opts[0].className).toContain('scolta-sayt-option-recent');
        expect(opts[1].className).toContain('scolta-sayt-option-recent');
        expect(opts[2].className).not.toContain('scolta-sayt-option-recent');
        expect(opts.filter(o => o.className.includes('recent'))).toHaveLength(2);
    });

    test('saytRecentSearches false reads nothing and writes nothing', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10, saytRecentSearches: false },
            recent: ['kamado stored earlier'],
            rowsFor: () => DOCS.ka,
        }));

        h.$('#scolta-query').value = 'kamado ribs';
        await h.window.Scolta.doSearch();
        await ticks(20);

        // The seeded value is untouched — nothing was written over it.
        expect(JSON.parse(h.window.localStorage.getItem(RECENT_KEY)))
            .toEqual(['kamado stored earlier']);

        h.type('kamado');
        await tick(80);
        expect(h.options().every(o => !o.className.includes('recent'))).toBe(true);
    });

    test('a stored value is escaped on render like any other untrusted text', async () => {
        const hostile = '<img src=x onerror="window.__pwned=true">kamado';
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            recent: [hostile],
            rowsFor: () => DOCS.ka,
        }));

        h.type('kamado');
        await tick(80);

        expect(h.window.__pwned).toBeUndefined();
        expect(h.$('#scolta-sayt').querySelector('img')).toBeNull();
        expect(h.options()[0].textContent).toContain('<img src=x');
    });

    test('storage that throws on every access never breaks the search box', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            breakStorage: true,
            rowsFor: () => DOCS.ka,
        }));

        h.$('#scolta-query').value = 'kamado';
        await h.window.Scolta.doSearch();
        await ticks(20);
        expect(h.$('#scolta-results-header').textContent).toContain('results for');

        h.type('kamado');
        await tick(80);
        expect(h.options()).toHaveLength(2);
        expect(h.window.console.error).not.toHaveBeenCalled();
    });

    test('a corrupt stored value is ignored rather than thrown on', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));
        h.window.localStorage.setItem(RECENT_KEY, '{not json');

        h.type('kamado');
        await tick(80);
        expect(h.options()).toHaveLength(2);
    });
});

// ---------------------------------------------------------------------------

describe('interaction with the full pipeline', () => {
    test('doSearch closes the dropdown and suppresses suggest work until the primary paint', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));

        h.type('kamado');
        await tick(80);
        expect(h.isOpen()).toBe(true);

        h.$('#scolta-query').value = 'kamado';
        await h.window.Scolta.doSearch();
        await ticks(20);

        expect(h.isOpen()).toBe(false);
        expect(h.$('#scolta-query').getAttribute('aria-expanded')).toBe('false');
    });

    test('the suggest path never writes results, history or the facet panel', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));
        const startUrl = h.window.location.href;

        h.type('kamado');
        await tick(80);

        expect(h.options()).toHaveLength(2);
        expect(h.$('#scolta-results').innerHTML).toBe('');
        expect(h.$('#scolta-results-header').innerHTML).toBe('');
        expect(h.$('#scolta-filters').innerHTML).toBe('');
        expect(h.$('#scolta-layout').style.display).toBe('none');
        expect(h.window.location.href).toBe(startUrl);
        // No summarize request was ever made from the suggest path.
        expect(h.window.fetch.mock.calls.map(c => String(c[0])))
            .not.toContain('/summarize');
    });

    test('clearSearch cancels pending suggest work', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 60 },
            rowsFor: () => DOCS.ka,
        }));

        h.type('kamado');
        h.$('#scolta-search-clear').click();
        await tick(150);

        expect(h.realSearches()).toHaveLength(0);
        expect(h.isOpen()).toBe(false);
    });

    test('a search that rejects does not wedge suggestions off for the rest of the page', async () => {
        // doSearch() suppresses the suggest path from its start until the
        // primary paint. Without a guard covering the whole window, a rejected
        // Pagefind search leaves it suppressed and every later keystroke is
        // silently ignored for the life of the page.
        let failNext = true;
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
            searchImpl: (q) => {
                if (q !== '' && failNext) {
                    failNext = false;
                    return Promise.reject(new Error('index unavailable'));
                }
                return null;
            },
        }));

        h.$('#scolta-query').value = 'kamado';
        await h.window.Scolta.doSearch().catch(() => {});
        await ticks(20);

        // The failure is not swallowed, and suggestions still work afterwards.
        h.type('kamado');
        await tick(80);
        expect(h.options()).toHaveLength(2);
        expect(h.isOpen()).toBe(true);
    });

    test('a synchronous throw before the first await does not wedge suggestions off either', async () => {
        // The suppression window opens before any await, so guarding only the
        // awaited region leaves the whole synchronous setup — the URL sync, the
        // scaffold writes, the lifecycle emits — able to wedge it.
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));

        // Break a scaffold write that runs between opening the window and the
        // first await. This is the shape of any DOM-side failure in that region.
        const results = h.$('#scolta-results');
        Object.defineProperty(results, 'innerHTML', {
            configurable: true,
            set() { throw new Error('paint blocked'); },
            get() { return ''; },
        });

        h.$('#scolta-query').value = 'kamado';
        await expect(h.window.Scolta.doSearch()).rejects.toThrow('paint blocked');
        await ticks(10);

        // Un-break it, then prove suggestions were not left suppressed.
        delete results.innerHTML;
        h.type('kamado');
        await tick(80);
        expect(h.options()).toHaveLength(2);
        expect(h.isOpen()).toBe(true);
    });

    test('an overtaken search does not unsuppress the suggest path mid-paint', async () => {
        // Two doSearch() cycles overlap whenever the user commits again while
        // one is in flight. A boolean would let the FIRST cycle's exit reopen
        // the suggest path while the second is still pre-paint; the window is
        // owned by a version so only its owner releases it.
        // Gate ONLY the two committed queries, one reusable gate each, so the
        // facet-count pass shares its cycle's gate and the suggest searches
        // used to check recovery are not gated at all.
        const gates = new Map();
        const gateFor = (q) => {
            if (!gates.has(q)) gates.set(q, deferred());
            return gates.get(q);
        };
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
            searchImpl: (q, _opts, resultsFor) => (
                (q === 'kamado' || q === 'kamado joe')
                    ? gateFor(q).promise.then(() => resultsFor(q))
                    : null
            ),
        }));

        // Neither cycle is awaited: the overtaken one's facet-count pass runs
        // against a memo the newer cycle already reset, so it parks on a gate
        // that is never opened. Drive both with ticks instead.
        h.$('#scolta-query').value = 'kamado';
        h.window.Scolta.doSearch();
        await ticks(5);
        h.$('#scolta-query').value = 'kamado joe';
        h.window.Scolta.doSearch();
        await ticks(5);

        // Let the OVERTAKEN cycle paint and exit while the newer one is still
        // pre-paint. Its exit must not release a window it no longer owns.
        gateFor('kamado').resolve();
        await ticks(15);

        h.type('kamado jo');
        await tick(80);
        expect(h.realSearches().every(c => c.query !== 'kamado jo')).toBe(true);
        expect(h.isOpen()).toBe(false);

        // Once the owner paints, suggestions come back.
        gateFor('kamado joe').resolve();
        await ticks(15);
        h.type('kamado joes');
        await tick(80);
        expect(h.options()).toHaveLength(2);
    });

    test('a suggest search that fails takes the dropdown down rather than leaving a stale one', async () => {
        let failNext = false;
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
            searchImpl: (q) => (q !== '' && failNext
                ? Promise.reject(new Error('index unavailable'))
                : null),
        }));

        h.type('kamado');
        await tick(80);
        expect(h.isOpen()).toBe(true);

        // A genuine failure is not the same as "no matches"; showing the
        // previous prefix's suggestions would claim it is.
        failNext = true;
        h.type('kamado joe');
        await tick(80);
        expect(h.isOpen()).toBe(false);
        expect(h.options()).toHaveLength(0);
    });

    test('one failing fragment costs one suggestion, not the whole dropdown', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
            searchImpl: (q, _opts, resultsFor) => {
                if (q === '') return null;
                const real = resultsFor(q);
                return Promise.resolve({
                    results: real.results.map((r, i) => ({
                        id: r.id,
                        data: () => (i === 0
                            ? Promise.reject(new Error('fragment 404'))
                            : r.data()),
                    })),
                });
            },
        }));

        h.type('kamado');
        await tick(80);

        expect(h.options()).toHaveLength(1);
        expect(h.options()[0].textContent).toContain('Kamado Pizza');
    });

    test('recent searches survive a total fragment-load failure', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            recent: ['kamado joe pizza'],
            rowsFor: () => DOCS.ka,
            searchImpl: (q, _opts, resultsFor) => {
                if (q === '') return null;
                const real = resultsFor(q);
                return Promise.resolve({
                    results: real.results.map(r => ({
                        id: r.id,
                        data: () => Promise.reject(new Error('fragment 404')),
                    })),
                });
            },
        }));

        h.type('kamado');
        await tick(80);

        // Recent searches do not depend on the index.
        expect(h.options()).toHaveLength(1);
        expect(h.options()[0].className).toContain('scolta-sayt-option-recent');
    });

    test('the two suggestion render events fire on the dropdown and bubble', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));

        const seen = [];
        h.window.document.addEventListener('scolta:before-suggestions-render', (e) => {
            seen.push(['before', e.target.id, e.detail.query, e.cancelable]);
        });
        h.window.document.addEventListener('scolta:suggestions-rendered', (e) => {
            seen.push(['after', e.target.id, e.detail.query, e.detail.suggestions.length]);
        });

        h.type('kamado');
        await tick(80);

        expect(seen).toEqual([
            ['before', 'scolta-sayt', 'kamado', false],
            ['after', 'scolta-sayt', 'kamado', 2],
        ]);
    });

    test('a listener that throws does not take the render down', async () => {
        const h = await boot(setup({
            sayt: { saytDebounceMs: 10 },
            rowsFor: () => DOCS.ka,
        }));
        h.window.document.addEventListener('scolta:before-suggestions-render', () => {
            throw new Error('bad listener');
        });

        h.type('kamado');
        await tick(80);

        expect(h.options()).toHaveLength(2);
    });
});

// ---------------------------------------------------------------------------

describe('AI enrichment', () => {
    const AI = {
        sayt: { saytDebounceMs: 10, saytExpansionDelayMs: 20, saytExpandPerMinute: 2 },
        scoring: { AI_EXPAND_QUERY: true, AI_SUMMARIZE: false },
    };

    function enrichSetup(extra = {}) {
        return setup(Object.assign({}, AI, {
            expandResponse: { terms: ['ceramic smoker'] },
            rowsFor: q => {
                if (q === 'ceramic smoker') {
                    return [{ url: '/smoker', title: 'Ceramic Smoker Guide', excerpt: 'low and slow' }];
                }
                return DOCS.ka;
            },
        }, extra));
    }

    test('expansion terms merge into the open dropdown after the idle delay', async () => {
        const h = await boot(enrichSetup({
            sayt: Object.assign({}, AI.sayt, { saytExpansionDelayMs: 150 }),
        }));

        h.type('kamado');
        await tick(60);
        // Keyword suggestions are up; the AI call has not fired yet.
        expect(h.options()).toHaveLength(2);
        expect(h.expandCalls).toHaveLength(0);

        await tick(250);
        expect(h.expandCalls).toHaveLength(1);
        expect(h.options().length).toBeGreaterThan(2);
        expect(h.$('#scolta-sayt').textContent).toContain('Ceramic Smoker Guide');
    });

    test('the active selection survives the list growing around it', async () => {
        const h = await boot(enrichSetup({
            sayt: Object.assign({}, AI.sayt, { saytExpansionDelayMs: 150 }),
        }));

        h.type('kamado');
        await tick(60);
        h.key('ArrowDown');
        h.key('ArrowDown');
        const active = h.options()[1].textContent;

        await tick(250);
        expect(h.options().length).toBeGreaterThan(2);
        const stillActive = h.options().find(o => o.getAttribute('aria-selected') === 'true');
        expect(stillActive).toBeTruthy();
        expect(stillActive.textContent).toBe(active);
    });

    test('the sliding-window budget stops the calls and degrades silently', async () => {
        const h = await boot(enrichSetup());

        for (const q of ['kamado a', 'kamado b', 'kamado c', 'kamado d']) {
            h.type(q);
            await tick(120);
        }

        // saytExpandPerMinute is 2, so only two calls left the browser.
        expect(h.expandCalls).toHaveLength(2);
        // Degraded silently: keyword suggestions are still on screen and no
        // user-facing error was rendered.
        expect(h.options().length).toBeGreaterThan(0);
        expect(h.window.console.error).not.toHaveBeenCalled();
        // Hitting the cap is a debugLog line, never a user-visible warning.
        const saytWarnings = h.window.console.warn.mock.calls
            .filter(args => String(args[0]).includes('sayt'));
        expect(saytWarnings).toHaveLength(0);
    });

    test('the budget recovers when the window rolls', async () => {
        const h = await boot(enrichSetup());

        for (const q of ['kamado a', 'kamado b', 'kamado c']) {
            h.type(q);
            await tick(120);
        }
        expect(h.expandCalls).toHaveLength(2);

        // Roll the sliding window past its 60s horizon.
        const realNow = h.window.Date.now;
        h.window.Date.now = () => realNow.call(h.window.Date) + 61000;

        h.type('kamado d');
        await tick(120);
        expect(h.expandCalls).toHaveLength(3);

        h.window.Date.now = realNow;
    });

    test('no expansion call is made while the user is still typing', async () => {
        const h = await boot(enrichSetup({
            sayt: Object.assign({}, AI.sayt, { saytExpansionDelayMs: 200 }),
        }));

        for (const q of ['kam', 'kama', 'kamad', 'kamado']) {
            h.type(q);
            await tick(40);
        }
        expect(h.expandCalls).toHaveLength(0);

        await tick(300);
        expect(h.expandCalls).toHaveLength(1);
    });

    test('an expansion that resolves after newer input paints nothing', async () => {
        const gate = deferred();
        const h = await boot(setup(Object.assign({}, AI, {
            // Only the FIRST cycle expands, so the term reaching the dropdown
            // could only have come from the superseded cycle.
            expandResponse: n => (n === 1 ? { terms: ['ceramic smoker'] } : { terms: [] }),
            rowsFor: q => (q === 'ceramic smoker'
                ? [{ url: '/smoker', title: 'Ceramic Smoker Guide', excerpt: 'x' }]
                : DOCS.ka),
            searchImpl: (q, _opts, resultsFor) => (q === 'ceramic smoker'
                ? gate.promise.then(() => resultsFor(q))
                : null),
        })));

        h.type('kamado');
        await tick(80);            // expansion search is now parked on the gate
        h.type('kamado joe');      // supersede the whole cycle
        await tick(80);

        gate.resolve();
        await ticks(20);

        expect(h.$('#scolta-sayt').textContent).not.toContain('Ceramic Smoker Guide');
    });

    test('saytExpand false makes no expansion call at all', async () => {
        const h = await boot(enrichSetup({
            sayt: Object.assign({}, AI.sayt, { saytExpand: false }),
        }));

        h.type('kamado');
        await tick(200);

        expect(h.expandCalls).toHaveLength(0);
        expect(h.options()).toHaveLength(2);
    });

    test('a failed expansion degrades to the keyword suggestions already shown', async () => {
        const h = await boot(enrichSetup({
            expandResponse: { not: 'an expansion' },
        }));

        h.type('kamado');
        await tick(200);

        expect(h.options()).toHaveLength(2);
        expect(h.$('#scolta-sayt').textContent).toContain('Kamado Basics');
    });
});

// ---------------------------------------------------------------------------

describe('the off switch', () => {
    test('saytEnabled false adds no dropdown node and no combobox roles', async () => {
        const h = await boot(setup({
            sayt: { saytEnabled: false },
            rowsFor: () => DOCS.ka,
        }));

        const input = h.$('#scolta-query');
        expect(h.$('#scolta-sayt')).toBeNull();
        expect(input.hasAttribute('role')).toBe(false);
        expect(input.hasAttribute('aria-autocomplete')).toBe(false);
        expect(input.hasAttribute('aria-expanded')).toBe(false);
        expect(input.hasAttribute('aria-controls')).toBe(false);
    });

    test('saytEnabled false leaves the input listener doing exactly what it did before', async () => {
        const h = await boot(setup({
            sayt: { saytEnabled: false },
            rowsFor: () => DOCS.ka,
        }));

        const container = h.$('#scolta-search');
        // Round-trip through the same property the handler writes, so the
        // baseline and the comparison serialize identically (a same-value
        // assignment is a no-op and would leave the parsed literal in place).
        h.$('#scolta-search-clear').style.display = 'block';
        h.$('#scolta-search-clear').style.display = 'none';
        const before = container.innerHTML;

        h.type('kamado');
        await tick(300);

        // Clear-button toggle and preload: the whole of the old behaviour.
        expect(h.$('#scolta-search-clear').style.display).toBe('block');
        expect(h.window.__pfMock.preload).toHaveBeenCalledWith('kamado');
        // Nothing else. No suggest search, no suggestion render event.
        expect(h.realSearches()).toHaveLength(0);
        expect(h.rendered).toHaveLength(0);

        // And no new DOM: undo the one mutation the old handler makes and the
        // container is byte-identical to what init() built.
        h.$('#scolta-search-clear').style.display = 'none';
        expect(container.innerHTML).toBe(before);
    });

    test('saytEnabled false touches no storage on a committed search', async () => {
        const h = await boot(setup({
            sayt: { saytEnabled: false },
            rowsFor: () => DOCS.ka,
        }));

        h.$('#scolta-query').value = 'kamado';
        await h.window.Scolta.doSearch();
        await ticks(20);

        expect(h.window.localStorage.getItem(RECENT_KEY)).toBeNull();
    });

    test('saytEnabled false leaves Enter running the search', async () => {
        const h = await boot(setup({
            sayt: { saytEnabled: false },
            rowsFor: () => DOCS.ka,
        }));

        h.type('kamado');
        await tick(50);
        const e = h.key('Enter');
        expect(e.defaultPrevented).toBe(false);
        await ticks(20);

        expect(h.$('#scolta-results-header').textContent).toContain('results for');
    });
});
