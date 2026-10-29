/**
 * Federated sources: other sites' Pagefind bundles searched alongside this one.
 *
 * Each remote runs as its own Pagefind instance (createInstance), not through
 * pagefind.mergeIndex(): a merged index that fails to load rejects every later
 * search on the page. What these pin is that a remote can only ever add
 * results — one that is down, built for another Pagefind release, missing the
 * page language, or failing mid-search leaves the local results intact.
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
    'pagefind = mockPagefind'
);

function result(id, url, title) {
    return {
        id,
        score: 1,
        words: [],
        data: () => Promise.resolve({
            url,
            content: title + ' content about things.',
            word_count: 20,
            excerpt: title + ' excerpt about things.',
            locations: [],
            meta: { title },
            filters: {},
        }),
    };
}

function searchResponse(results) {
    return Promise.resolve({ results, filters: {}, totalFilters: {}, unfilteredResultCount: results.length });
}

/**
 * A remote instance as createInstance() returns it. Pagefind applies baseUrl
 * itself, so its results already carry absolute URLs.
 */
function remoteInstance(opts, behaviour) {
    const origin = opts.baseUrl.replace(/\/$/, '');
    return {
        opts,
        init: () => Promise.resolve(),
        destroy: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        preload: () => Promise.resolve(),
        search: (q) => {
            if (behaviour.failSearch && q !== '') return Promise.reject(new Error('chunk 404'));
            if (q === '') return searchResponse([]);
            return searchResponse([result('en_remote1', origin + '/node/1', 'Remote things page')]);
        },
    };
}

function createMockPagefind(remoteBehaviour) {
    const created = [];
    const mock = {
        init: () => Promise.resolve(),
        preload: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        mergeIndex: () => Promise.resolve(),
        search: (q) => (q === ''
            ? searchResponse([])
            : searchResponse([result('en_local1', '/local-page', 'Local things page')])),
        createInstance: (opts) => {
            const inst = remoteInstance(opts, remoteBehaviour[opts.basePath] || {});
            created.push(inst);
            return inst;
        },
    };
    return { mock, created };
}

const DOCS = 'https://docs.example.com/sites/docs/files/scolta-pagefind/pagefind/';
const BLOG = 'https://blog.example.com/sites/blog/files/scolta-pagefind/pagefind/';
const SHOP = 'https://shop.example.com/sites/shop/files/scolta-pagefind/pagefind/';

async function boot(mockPagefind, sources, entries) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: 'http://www.example.com/search?q=things', runScripts: 'outside-only' },
    );
    const { window } = dom;
    window.console = Object.assign({}, console, {
        warn: () => {}, log: () => {}, error: () => {}, debug: () => {},
    });
    window.fetch = (u) => {
        u = String(u);
        for (const [base, entry] of Object.entries(entries)) {
            if (u.startsWith(base + 'pagefind-entry.json')) {
                if (!entry) return Promise.resolve({ ok: false, status: 404 });
                return Promise.resolve({ ok: true, json: () => Promise.resolve(entry) });
            }
        }
        if (/pagefind-entry\.json/.test(u)) {
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    version: '1.5.0',
                    languages: { en: { hash: 'en_local', wasm: 'en', page_count: 10 } },
                }),
            });
        }
        return Promise.resolve({ ok: false, status: 404 });
    };
    window.scrollTo = () => {};
    window.mockPagefind = mockPagefind;
    window.scolta = {
        pagefindPath: '/pagefind/pagefind.js',
        facetMode: 'disabled',
        federatedSources: sources,
        scoring: {
            MAX_PAGEFIND_RESULTS: 50, RESULTS_PER_PAGE: 10,
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
    // Initial auto-search, the remotes joining, and the one re-run.
    await new Promise(r => setTimeout(r, 300));
    return window;
}

const shownUrls = (window) =>
    [...window.document.querySelectorAll('#scolta-results .scolta-result-url')]
        .map(a => a.textContent.trim());

const badges = (window) =>
    [...window.document.querySelectorAll('#scolta-results .scolta-site-badge')]
        .map(b => b.textContent.trim());

const entry = (version, langs) => ({
    version,
    languages: Object.fromEntries(langs.map(l => [l, { hash: l + '_x', wasm: l, page_count: 5 }])),
});

describe('federated sources', () => {
    test('a healthy remote joins with absolute URLs and a source badge', async () => {
        const { mock, created } = createMockPagefind({});
        const window = await boot(mock,
            [{ label: 'Docs', url: DOCS + 'pagefind.js', weight: 1.5 }],
            { [DOCS]: entry('1.5.0', ['en']) });

        expect(created).toHaveLength(1);
        expect(created[0].opts).toMatchObject({
            basePath: DOCS,
            baseUrl: 'https://docs.example.com/',
            indexWeight: 1.5,
            noWorker: true,
        });
        const urls = shownUrls(window);
        expect(urls).toContain('/local-page');
        expect(urls).toContain('https://docs.example.com/node/1');
        expect(badges(window)).toEqual(['Docs']);
    });

    test('unreachable, wrong-version and wrong-language remotes are skipped', async () => {
        const { mock, created } = createMockPagefind({});
        const window = await boot(mock, [
            { label: 'Docs', url: DOCS + 'pagefind.js' },
            { label: 'Blog', url: BLOG + 'pagefind.js' },
            { label: 'Shop', url: SHOP + 'pagefind.js' },
        ], {
            [DOCS]: null,
            [BLOG]: entry('1.3.0', ['en']),
            [SHOP]: entry('1.5.0', ['de']),
        });

        // None got as far as an instance: the entry file ruled each one out.
        expect(created).toHaveLength(0);
        expect(shownUrls(window)).toEqual(['/local-page']);
    });

    test('a remote that fails mid-search drops out of that search only', async () => {
        const { mock } = createMockPagefind({ [DOCS]: { failSearch: true } });
        const window = await boot(mock, [
            { label: 'Docs', url: DOCS + 'pagefind.js' },
            { label: 'Blog', url: BLOG + 'pagefind.js' },
        ], {
            [DOCS]: entry('1.5.0', ['en']),
            [BLOG]: entry('1.5.0', ['en']),
        });

        const urls = shownUrls(window);
        expect(urls).toContain('/local-page');
        expect(urls).toContain('https://blog.example.com/node/1');
        expect(urls).not.toContain('https://docs.example.com/node/1');
    });

    test('this site and repeated entries are not searched as remotes', async () => {
        const { mock, created } = createMockPagefind({});
        const window = await boot(mock, [
            { label: 'Self', url: 'http://www.example.com/pagefind/pagefind.js' },
            { label: 'Docs', url: DOCS + 'pagefind.js' },
            { label: 'Docs again', url: DOCS + 'pagefind.js?v=2' },
        ], { [DOCS]: entry('1.5.0', ['en']) });

        expect(created.map(c => c.opts.basePath)).toEqual([DOCS]);
        expect(shownUrls(window).filter(u => u === '/local-page')).toHaveLength(1);
    });

    test('no sources configured creates no instances', async () => {
        const { mock, created } = createMockPagefind({});
        const window = await boot(mock, [], {});

        expect(created).toHaveLength(0);
        expect(shownUrls(window)).toEqual(['/local-page']);
        expect(badges(window)).toEqual([]);
    });
});
