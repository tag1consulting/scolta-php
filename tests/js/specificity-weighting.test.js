/**
 * Specificity-weighted ranking of partial matches.
 *
 * A common word in the query used to flood the head of the result list because
 * the ranker rewarded matching it as much as matching the rare terms that carry
 * the intent. These tests execute scolta.js in JSDOM against a recording
 * Pagefind mock whose corpus makes one typed word ubiquitous ("apollo", 80 of
 * 100 docs) and the other rare ("crisis", 3 of 100). The full-query AND search
 * returns nothing, so the OR fallback engages — the exact path where the flood
 * happened.
 *
 * With specificity weighting ON, the rare term's sub-query keeps near-full
 * weight while the ubiquitous term's is damped toward the floor, so a "crisis"
 * document leads and the banner stops crying failure. With it OFF (flat 0.6
 * weighting, the previous behavior), the ubiquitous term is no longer damped
 * and an "apollo" document leads. WASM import fails in JSDOM, so the JS
 * fallback scorer runs — which is fine: the specificity multiplier is applied
 * in scolta.js at merge time, independent of the WASM path.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Patch the Pagefind dynamic import to use a recording mock from the window.
const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = global.__pfMock'
);

const TOTAL_DOCS = 100;
// Document counts per single-term query. "apollo" is ubiquitous, "crisis" rare.
const CORPUS = { apollo: 80, crisis: 3 };

function buildResults(query) {
    let n;
    if (query === null || query === undefined || query === '') {
        n = TOTAL_DOCS; // filter-only search => corpus size
    } else if (Object.prototype.hasOwnProperty.call(CORPUS, query)) {
        n = CORPUS[query];
    } else if (String(query).includes(' ')) {
        n = 0; // multi-word AND (the full query) matches nothing => OR fallback
    } else {
        n = 2;
    }
    const results = [];
    for (let i = 0; i < n; i++) {
        results.push({
            id: `${query}-${i}`,
            data: () => Promise.resolve({
                url: `/${query}/${i}`,
                meta: { title: `${query} ${i}` },
                excerpt: `${query} document ${i}`,
                content: `${query} document ${i}`,
                locations: [],
            }),
        });
    }
    return { results };
}

function createWindow(specificityWeighting) {
    const dom = new JSDOM(
        `<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>`,
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const window = dom.window;

    window.__pfMock = {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        search: (query) => Promise.resolve(buildResults(query)),
    };

    window.fetch = jest.fn((url) => {
        const u = String(url);
        if (u.includes('pagefind-entry.json')) {
            return Promise.resolve({
                ok: true, status: 200,
                json: () => Promise.resolve({ languages: { en: { page_count: TOTAL_DOCS } } }),
                text: () => Promise.resolve('{}'),
            });
        }
        // Expansion disabled below, but answer defensively.
        return Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ terms: [] }),
            text: () => Promise.resolve('{}'),
        });
    });

    window.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    window.scrollTo = () => {};

    window.eval(patchedSource);

    window.scolta = {
        scoring: {
            SPECIFICITY_WEIGHTING: specificityWeighting,
            AI_EXPAND_QUERY: false,
            AI_SUMMARIZE: false,
        },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        wasmPath: '/wasm.js', // import fails in JSDOM => JS fallback scoring
        siteName: 'Test',
        container: '#scolta-search',
    };
    window.Scolta.init('#scolta-search');
    return window;
}

const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

async function runSearch(specificityWeighting) {
    const window = createWindow(specificityWeighting);
    for (let i = 0; i < 10; i++) await tick(0);
    const input = window.document.querySelector('#scolta-query');
    input.value = 'apollo crisis';
    await window.Scolta.doSearch();
    for (let i = 0; i < 30; i++) await tick(0);

    const titles = [...window.document.querySelectorAll('#scolta-results .scolta-result-title')]
        .map(a => a.textContent.trim());
    const header = window.document.querySelector('#scolta-results-header').textContent;
    const firstCrisisRank = titles.findIndex(t => t.startsWith('crisis'));
    return { titles, header, firstCrisisRank };
}

describe('specificity-weighted ranking (OR fallback)', () => {
    test('rare term leads and the banner does not cry failure when specificity is ON', async () => {
        const { titles, header, firstCrisisRank } = await runSearch(true);
        expect(titles.length).toBeGreaterThan(0);
        // A rare-term ("crisis") document is #1, not the ubiquitous "apollo".
        expect(titles[0].startsWith('crisis')).toBe(true);
        expect(firstCrisisRank).toBe(0);
        // A strong specific term matched, so the header stops framing it as a
        // failure and the summary hedge (not asserted here) softens too.
        expect(header).toContain('showing best matches');
        expect(header).not.toContain('no exact matches found');
    });

    test('ubiquitous term floods and the failure banner shows when specificity is OFF', async () => {
        const { titles, header, firstCrisisRank } = await runSearch(false);
        expect(titles.length).toBeGreaterThan(0);
        // Previous behavior: the ubiquitous typed word leads the list.
        expect(titles[0].startsWith('apollo')).toBe(true);
        expect(firstCrisisRank).toBeGreaterThan(0);
        expect(header).toContain('no exact matches found, showing partial matches');
    });

    test('specificity strictly improves the rare term rank', async () => {
        const on = await runSearch(true);
        const off = await runSearch(false);
        expect(on.firstCrisisRank).toBeLessThan(off.firstCrisisRank);
    });
});
