/**
 * JS half of the renderer parity gate.
 *
 * Each fixture in tests/fixtures/render-parity/ is rendered through the REAL
 * scolta.js formatSummary() path (booted in JSDOM, summary delivered via the
 * mocked /summarize endpoint) and asserted with the same mustContain /
 * mustNotContain expectations that tests/Util/RenderParityTest.php asserts
 * against PHP MarkdownRenderer::render(). Drift on either side fails that
 * side's suite.
 *
 * Deliberate, documented differences (headings are JS-only; PHP
 * entity-encodes quotes) are kept out of the fixtures — see the fixture
 * directory README.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'), 'utf-8'
);
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = global.__pfMock'
);

const FIXTURE_DIR = path.resolve(__dirname, '../fixtures/render-parity');
const fixtures = fs.readdirSync(FIXTURE_DIR)
    .filter(f => f.endsWith('.json'))
    .map(f => [path.basename(f, '.json'), JSON.parse(fs.readFileSync(path.join(FIXTURE_DIR, f), 'utf-8'))]);

const tick = () => new Promise(r => setTimeout(r, 0));
async function ticks(n) { for (let i = 0; i < n; i++) await tick(); }

// Boot the real scolta.js and render `summary` through the AI-overview path.
async function renderSummary(summary) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (q) => Promise.resolve({
            results: [{
                id: `${q}-0`,
                data: () => Promise.resolve({
                    url: '/doc',
                    meta: { title: 'Doc' },
                    excerpt: 'branch words',
                    content: 'branch words here',
                    locations: [],
                }),
            }],
        }),
    };

    window.fetch = jest.fn((url) => {
        const u = String(url);
        const respond = (body) => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        });
        if (u.includes('pagefind-entry.json')) return respond({ languages: { en: {} } });
        if (u === '/summarize') return respond({ summary });
        return respond({});
    });
    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);
    window.scolta = {
        scoring: {
            AI_EXPAND_QUERY: false,
            AI_SUMMARIZE: true,
            AUTO_LANGUAGE_FILTER: false,
            MAX_PAGEFIND_RESULTS: 30,
        },
        endpoints: { expand: '/expand', summarize: '/summarize', followup: '/followup' },
        pagefindPath: '/pf.js', wasmPath: '/wasm.js',
        siteName: 'Test', container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');

    await ticks(15);
    window.document.querySelector('#scolta-query').value = 'branch';
    window.Scolta.doSearch();
    await ticks(40);

    const summaryText = window.document.querySelector('.scolta-ai-summary-text');
    expect(summaryText).toBeTruthy();
    return summaryText.innerHTML;
}

describe('renderer parity — JS side of the shared contract', () => {
    test.each(fixtures)('%s', async (name, fixture) => {
        const html = await renderSummary(fixture.input);

        for (const needle of fixture.mustContain || []) {
            expect(html).toContain(needle);
        }
        for (const needle of fixture.mustNotContain || []) {
            expect(html).not.toContain(needle);
        }
    });

    test('fixture directory is not empty', () => {
        expect(fixtures.length).toBeGreaterThan(0);
    });
});
