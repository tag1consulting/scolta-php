/**
 * Security regression tests for scolta.js rendering.
 *
 * Drives the REAL scolta.js inside JSDOM (same harness shape as
 * shared-render-bugs.test.js) against hostile data on every untrusted
 * channel that reaches an attribute or href:
 *
 *   1. LLM-generated expanded terms land in data-scolta-search-term="..."
 *      — a term containing `"` must not break out of the attribute.
 *   2. Result URLs come from index metadata — a javascript: URL must not
 *      become a clickable href on the result card.
 *   3. AI-summary markdown links — javascript:/data: schemes must render
 *      as plain text even with no domain allowlist configured, and a `"`
 *      inside an allowed URL must not break out of the href attribute.
 *   4. stripHtml() must parse untrusted HTML in an inert document
 *      (DOMParser), not via innerHTML on a live detached div.
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
//   rowsFor(query)   -> array of { url, title, content, excerpt, meta } rows
//   config           -> scoring config overrides
//   expandResponse   -> body returned by the /expand endpoint
//   summarizeResponse-> body returned by the /summarize endpoint
function setup({ rowsFor, config = {}, expandResponse = { terms: [] }, summarizeResponse = {} } = {}) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
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
                id: `${q}-${i}`,
                data: () => Promise.resolve({
                    url: row.url,
                    meta: Object.assign({ title: row.title }, row.meta || {}),
                    excerpt: row.excerpt || '',
                    content: row.content || '',
                    locations: [],
                }),
            }));
            return Promise.resolve({ results });
        },
    };

    window.fetch = jest.fn((url) => {
        const u = String(url);
        const respond = (body) => Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve(body),
            text: () => Promise.resolve(JSON.stringify(body)),
        });
        if (u.includes('pagefind-entry.json')) {
            return respond({ languages: { en: {} } });
        }
        if (u === '/expand') {
            return respond(expandResponse);
        }
        if (u === '/summarize') {
            return respond(summarizeResponse);
        }
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
        }, config),
        endpoints: { expand: '/expand', summarize: '/summarize', followup: '/followup' },
        pagefindPath: '/pf.js', wasmPath: '/wasm.js',
        siteName: 'Test', container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');

    const $ = sel => window.document.querySelector(sel);

    async function search(query) {
        await ticks(15);
        $('#scolta-query').value = query;
        const p = window.Scolta.doSearch();
        return p;
    }

    return { window, $, search };
}

describe('expanded terms — attribute escaping (escapeAttr)', () => {
    test('an LLM term containing `"` cannot break out of data-scolta-search-term', async () => {
        const evilTerm = 'rebase" data-pwned="1';
        const h = setup({
            rowsFor: () => [{ url: '/a', title: 'Git Rebase', content: 'rebase' }],
            config: { AI_EXPAND_QUERY: true },
            expandResponse: { terms: ['merge', evilTerm] },
        });
        await h.search('merge');
        await ticks(30);

        const spans = h.window.document.querySelectorAll('.scolta-expanded-term');
        expect(spans.length).toBeGreaterThan(0);

        const rendered = [...spans].find(s => s.textContent.includes('rebase'));
        expect(rendered).toBeTruthy();
        // The whole hostile string must round-trip as the attribute VALUE…
        expect(rendered.getAttribute('data-scolta-search-term')).toBe(evilTerm);
        // …and must not have minted a new attribute on the element.
        expect(rendered.hasAttribute('data-pwned')).toBe(false);
    });
});

describe('result cards — href scheme allowlist and title attribute', () => {
    test('a javascript: result URL is not clickable', async () => {
        const h = setup({
            rowsFor: () => [{
                url: 'javascript:alert(1)',
                title: 'Poisoned Doc',
                content: 'branch',
                meta: { url: 'javascript:alert(1)' },
            }],
        });
        await h.search('branch');
        await ticks(20);

        const titleLink = h.$('.scolta-result-title');
        const urlLink = h.$('.scolta-result-url');
        expect(titleLink).toBeTruthy();
        expect(titleLink.getAttribute('href')).toBe('#');
        expect(urlLink.getAttribute('href')).toBe('#');
    });

    test('a `"` in a result title cannot break out of the title attribute', async () => {
        const h = setup({
            rowsFor: () => [{
                url: '/a',
                title: 'Evil" onmouseover="window.__pwned=true',
                content: 'branch',
            }],
        });
        await h.search('branch');
        await ticks(20);

        const titleLink = h.$('.scolta-result-title');
        expect(titleLink).toBeTruthy();
        expect(titleLink.hasAttribute('onmouseover')).toBe(false);
        expect(titleLink.getAttribute('title')).toContain('Evil"');
    });

    test('a normal relative result URL stays clickable', async () => {
        const h = setup({
            rowsFor: () => [{ url: '/docs/branching', title: 'Branching', content: 'branch' }],
        });
        await h.search('branch');
        await ticks(20);

        const titleLink = h.$('.scolta-result-title');
        expect(titleLink.getAttribute('href')).toContain('/docs/branching');
    });
});

describe('AI summary links — scheme gate with empty allowlist (formatInline)', () => {
    async function renderSummary(summary) {
        const h = setup({
            rowsFor: () => [{ url: '/a', title: 'Doc', content: 'branch words here', excerpt: 'branch' }],
            config: { AI_SUMMARIZE: true },
            summarizeResponse: { summary },
        });
        await h.search('branch');
        await ticks(40);
        return h;
    }

    test('a javascript: markdown link renders as plain text, not an anchor', async () => {
        const h = await renderSummary('Click [here](javascript:alert(1)) for more.');
        const summaryText = h.$('.scolta-ai-summary-text');
        expect(summaryText).toBeTruthy();
        expect(summaryText.querySelector('a')).toBeNull();
        expect(summaryText.textContent).toContain('here');
    });

    test('a data: markdown link renders as plain text', async () => {
        const h = await renderSummary('See [payload](data:text/html;base64,PHNjcmlwdD4=).');
        const summaryText = h.$('.scolta-ai-summary-text');
        expect(summaryText.querySelector('a')).toBeNull();
    });

    test('a scheme hidden behind a tab is still blocked', async () => {
        const h = await renderSummary('Go [x](jav\tascript:alert(1)).');
        const summaryText = h.$('.scolta-ai-summary-text');
        expect(summaryText.querySelector('a')).toBeNull();
    });

    test('an https link with a `"` in the URL cannot break out of href', async () => {
        const h = await renderSummary('See [docs](https://example.com/?q=a"b) now.');
        const summaryText = h.$('.scolta-ai-summary-text');
        const a = summaryText.querySelector('a');
        expect(a).toBeTruthy();
        // The quote stays inside the href value; no attribute was minted.
        expect(a.getAttribute('href')).toBe('https://example.com/?q=a"b');
        expect(a.attributes.length).toBe(3); // href, target, rel
    });

    test('an ordinary https link still renders as an anchor', async () => {
        const h = await renderSummary('Visit [example](https://example.com/page).');
        const a = h.$('.scolta-ai-summary-text a');
        expect(a).toBeTruthy();
        expect(a.getAttribute('href')).toBe('https://example.com/page');
    });
});

describe('stripHtml — inert parsing', () => {
    test('an <img onerror> payload in a title neither renders nor executes', async () => {
        const h = setup({
            rowsFor: () => [{
                url: '/a',
                title: 'Doc <img src=x onerror="window.__pwned=true"> Title',
                content: 'branch',
                excerpt: 'branch <img src=x onerror="window.__pwned=true"> excerpt',
            }],
        });
        await h.search('branch');
        await ticks(20);

        expect(h.window.__pwned).toBeUndefined();
        const card = h.$('.scolta-result-card');
        expect(card).toBeTruthy();
        expect(card.querySelector('img')).toBeNull();
        expect(card.textContent).toContain('Doc');
    });

    test('stripHtml is DOMParser-based (no live detached-div innerHTML)', () => {
        // Structural pin: parsing untrusted HTML must happen in an inert
        // DOMParser document. A revert to the live-div pattern would pass the
        // behavioral test in JSDOM (which loads no resources) while
        // reintroducing eager resource loading in real browsers.
        const fnSource = scoltaSource.match(/function stripHtml\([\s\S]*?\n  \}/);
        expect(fnSource).toBeTruthy();
        expect(fnSource[0]).toContain('DOMParser');
        expect(fnSource[0]).not.toContain('div.innerHTML');
    });
});
