/**
 * Which language indexes initPagefind() merges.
 *
 * pagefind.init() loads the index for the page's <html lang>, and when there
 * is none it falls back to the index with the most pages. scolta.js then
 * merges every other language so the language facet has all its values. It
 * must skip the index Pagefind already loaded, not the one named by
 * <html lang>: the PHP indexer writes a single index keyed "en" for every
 * language, so on a Spanish page the primary instance had already loaded "en"
 * and merging "en" again loaded the same index twice. Every result came back
 * twice, the second copy with a URL under the index directory that leads
 * nowhere.
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
    'pagefind = window.__pagefindMock'
);

function makePagefindMock() {
    return {
        init: jest.fn().mockResolvedValue(undefined),
        search: jest.fn().mockResolvedValue({ results: [] }),
        filters: jest.fn().mockResolvedValue({}),
        mergeIndex: jest.fn().mockResolvedValue(undefined),
    };
}

function tick(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Boot scolta.js on a page with the given <html lang> against an index whose
 * pagefind-entry.json lists `languages`. Returns the Pagefind mock and the
 * URLs fetched.
 */
async function boot(htmlLang, languages) {
    const langAttr = htmlLang === null ? '' : ` lang="${htmlLang}"`;
    const dom = new JSDOM(
        `<!DOCTYPE html><html${langAttr}><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com/search', runScripts: 'dangerously' }
    );
    const window = dom.window;
    const pagefindMock = makePagefindMock();
    window.__pagefindMock = pagefindMock;
    const fetched = [];
    window.fetch = jest.fn((url) => {
        fetched.push(String(url));
        if (String(url).includes('pagefind-entry.json')) {
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ version: '1.5.0', languages }),
            });
        }
        return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve({}) });
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    window.scrollTo = () => {};
    window.eval(patchedSource);
    window.scolta = {
        scoring: {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/files/index/pagefind/pagefind.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    };
    window.Scolta.init('#scolta-search');
    await tick(20);
    return { pagefindMock, fetched, window };
}

const single = { en: { hash: 'en_aaa', wasm: 'en', page_count: 44 } };
const two = {
    en: { hash: 'en_aaa', wasm: 'en', page_count: 30 },
    es: { hash: 'es_bbb', wasm: 'es', page_count: 14 },
};

describe('initPagefind language merge', () => {
    test('a page whose language has no index of its own does not merge the fallback index Pagefind already loaded', async () => {
        const { pagefindMock } = await boot('es', single);
        expect(pagefindMock.init).toHaveBeenCalled();
        expect(pagefindMock.mergeIndex).not.toHaveBeenCalled();
    });

    test('the fallback is the index with the most pages, as in Pagefind, not the first listed', async () => {
        const { pagefindMock } = await boot('fr', {
            de: { hash: 'de_ccc', wasm: 'de', page_count: 3 },
            en: { hash: 'en_aaa', wasm: 'en', page_count: 30 },
        });
        expect(pagefindMock.mergeIndex).toHaveBeenCalledTimes(1);
        expect(pagefindMock.mergeIndex.mock.calls[0][1]).toEqual(expect.objectContaining({ language: 'de' }));
    });

    test('a page with no lang attribute does not merge the fallback index either', async () => {
        const { pagefindMock } = await boot(null, single);
        expect(pagefindMock.mergeIndex).not.toHaveBeenCalled();
    });

    test('a page whose language has its own index merges each other language once', async () => {
        const { pagefindMock } = await boot('es', two);
        expect(pagefindMock.mergeIndex).toHaveBeenCalledTimes(1);
        expect(pagefindMock.mergeIndex.mock.calls[0][1]).toEqual(expect.objectContaining({ language: 'en' }));
    });

    test('a region subtag resolves to its base language index, as in Pagefind', async () => {
        const { pagefindMock } = await boot('es-MX', two);
        expect(pagefindMock.mergeIndex).toHaveBeenCalledTimes(1);
        expect(pagefindMock.mergeIndex.mock.calls[0][1]).toEqual(expect.objectContaining({ language: 'en' }));
    });

    test('an exact region index wins over its base language, as in Pagefind', async () => {
        const { pagefindMock } = await boot('pt-BR', {
            pt: { hash: 'pt_ddd', wasm: 'pt', page_count: 10 },
            'pt-br': { hash: 'pt-br_eee', wasm: 'pt', page_count: 5 },
        });
        expect(pagefindMock.mergeIndex).toHaveBeenCalledTimes(1);
        expect(pagefindMock.mergeIndex.mock.calls[0][1]).toEqual(expect.objectContaining({ language: 'pt' }));
    });

    test('the facet index is looked up under the hash of the index Pagefind loaded', async () => {
        const { fetched } = await boot('es', single);
        expect(fetched.some((u) => u.includes('scolta.en_aaa.facets'))).toBe(true);
    });
});
