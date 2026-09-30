/**
 * Which language indexes initPagefind() merges into the primary instance.
 *
 * pagefind.init() loads the index for <html lang>, and when the entry file
 * has no bucket for that language it falls back to the bucket with the most
 * pages. The merge loop has to skip whatever the primary actually loaded,
 * not only the bucket named after the page language: merging the fallback
 * bucket a second time loads the same index twice and every result renders
 * twice. Seen on a Drupal site whose one index holds every translation under
 * a single `en` bucket: each hit appeared twice on /es/ pages.
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
        preload: jest.fn().mockResolvedValue(undefined),
    };
}

async function createWindow(htmlLang, entry) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html lang="${htmlLang}"><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com/es/', runScripts: 'dangerously' }
    );
    const window = dom.window;
    const pf = makePagefindMock();
    window.__pagefindMock = pf;

    window.fetch = jest.fn((url) => {
        const body = String(url).includes('pagefind-entry.json') ? entry : [];
        return Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        });
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);
    window.scolta = {
        scoring: {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/sites/default/files/scolta-pagefind/pagefind/pagefind.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
        facetMode: 'disabled',
    };
    window.Scolta.init('#scolta-search');
    await new Promise((resolve) => setTimeout(resolve, 20));
    return pf;
}

const bucket = (hash, pages) => ({ hash, wasm: 'en', page_count: pages });

describe('multilingual merge in initPagefind()', () => {

    test('a page language with no bucket does not merge the fallback bucket a second time', async () => {
        // One index, every translation in the `en` bucket (the Drupal indexer's output).
        const pf = await createWindow('es', { version: '1.4.0', languages: { en: bucket('en_abc', 38) } });

        expect(pf.init).toHaveBeenCalledTimes(1);
        expect(pf.mergeIndex).not.toHaveBeenCalled();
    });

    test('the fallback is the bucket pagefind.init() picks: the one with the most pages', async () => {
        const pf = await createWindow('es', { version: '1.4.0', languages: {
            fr: bucket('fr_1', 5),
            en: bucket('en_1', 40),
        } });

        // pagefind loaded `en` for this page, so only `fr` is new.
        expect(pf.mergeIndex).toHaveBeenCalledTimes(1);
        expect(pf.mergeIndex.mock.calls[0][1]).toEqual(expect.objectContaining({ language: 'fr' }));
    });

    test('every other language is still merged when the page language has its own bucket', async () => {
        const pf = await createWindow('es-MX', { version: '1.4.0', languages: {
            en: bucket('en_1', 40),
            es: bucket('es_1', 30),
            fr: bucket('fr_1', 5),
        } });

        const merged = pf.mergeIndex.mock.calls.map((c) => c[1].language).sort();
        expect(merged).toEqual(['en', 'fr']);
    });

    test('a merged index resolves URLs against the same base as the primary', async () => {
        // In pagefind's web worker there is no window, so a merged instance
        // keeps the origin on its basePath and derives an absolute baseUrl;
        // resolveUrl() passes absolute URLs through untouched and the result
        // links under the index directory instead of the page.
        const pf = await createWindow('en', { version: '1.4.0', languages: {
            en: bucket('en_1', 40),
            es: bucket('es_1', 30),
        } });

        expect(pf.mergeIndex).toHaveBeenCalledTimes(1);
        expect(pf.mergeIndex.mock.calls[0][1]).toEqual({
            language: 'es',
            baseUrl: '/sites/default/files/scolta-pagefind/',
        });
    });
});
