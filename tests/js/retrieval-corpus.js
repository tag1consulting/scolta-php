'use strict';
/**
 * A twelve page corpus and a Pagefind double that answers with AND semantics
 * over whole words, for tests that pin what the search pipeline returns.
 *
 * search(q) matches a page when every word of q is one of its words, ordered
 * by how often those words occur (ties by corpus order), which is enough for
 * the OR fallback, the sub-word guard and the expansion merge to take the
 * paths they take on a real index. data() hands back a fresh copy on every
 * call, as Pagefind does. No page carries a date, so the recency term is zero
 * and scores do not drift with the clock.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const PAGES = [
    { url: '/gdpr-breach', title: 'GDPR breach notification', text: 'Under GDPR a personal data breach must be reported within 72 hours to the supervisory authority.' },
    { url: '/hipaa-breach', title: 'HIPAA breach notification rule', text: 'Covered entities notify affected individuals of a breach of unsecured health information.' },
    { url: '/breach-response', title: 'Responding to a data breach', text: 'A breach response plan names the team, the notification steps and the regulators to inform.' },
    { url: '/retention', title: 'Data retention policy', text: 'Retention schedules decide how long records are kept before deletion.' },
    { url: '/retention-guide', title: 'Data retention policy guide', text: 'A guide to writing a retention policy and keeping records.' },
    { url: '/pci', title: 'PCI DSS requirements', text: 'Cardholder data must be encrypted and the requirements cover network security.' },
    { url: '/fines', title: 'GDPR fines and penalties', text: 'Supervisory authorities can fine up to 4 percent of turnover for a breach.' },
    { url: '/sox', title: 'SOX real time disclosure', text: 'Public companies disclose material changes on a rapid and current basis.' },
    { url: '/encryption', title: 'Encryption at rest', text: 'Encrypt stored personal data to reduce breach notification duties.', meta: { type: 'guide' } },
    { url: '/contractors', title: 'Working with contractors', text: 'Processors and contractors must report a breach to the controller.', meta: { type: 'policy' } },
    { url: '/cookie', title: 'Cookie consent', text: 'Consent banners and tracking cookies under the ePrivacy rules.', meta: { url: 'https://example.com/privacy/cookies' } },
    { url: '/glossary', title: 'Glossary', text: 'breach retention encryption consent processor controller fine notification' },
];

function words(s) {
    return String(s).toLowerCase().replace(/[^a-z0-9\s]/g, ' ').split(/\s+/).filter(Boolean);
}

function pageFor(p) {
    return {
        url: p.url,
        meta: Object.assign({ title: p.title }, p.meta || {}),
        excerpt: p.text,
        content: p.title + '. ' + p.text,
    };
}

function createPagefind(calls) {
    return {
        init: () => Promise.resolve(),
        mergeIndex: () => Promise.resolve(),
        filters: () => Promise.resolve({}),
        preload: () => Promise.resolve(),
        search: (q) => {
            calls.push(q);
            const terms = q === null ? [] : words(q);
            const hits = [];
            PAGES.forEach((p, i) => {
                const ws = words(p.title + ' ' + p.text);
                if (!terms.every(t => ws.includes(t))) return;
                const freq = terms.reduce((n, t) => n + ws.filter(w => w === t).length, 0);
                hits.push({ p, i, freq });
            });
            hits.sort((a, b) => (b.freq - a.freq) || (a.i - b.i));
            return Promise.resolve({
                results: hits.map(h => ({ id: h.p.url, data: () => Promise.resolve(pageFor(h.p)) })),
            });
        },
    };
}

/**
 * A JSDOM window with scolta.js loaded against the corpus.
 *
 * `expansions` maps a query to the terms the expand endpoint returns for it.
 * `wasm`, when given, stands in for the dynamic import of the WASM glue.
 * `inject` is code placed at the SHARED SEARCH HELPERS anchor.
 */
function createCorpusWindow({ scoring = {}, expansions = {}, priorityPages, wasm, inject = '', container = true } = {}) {
    let source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/scolta.js'), 'utf-8');
    source = source.replace(/pagefind\s*=\s*await\s+import\s*\([^)]+\)/, 'pagefind = window.__pfMock');
    source = source.replace(/const wasm = await import\(wasmPath\);/, 'const wasm = window.__wasmMod; if (!wasm) throw new Error("no wasm");');
    if (inject) source = source.replace('// SHARED SEARCH HELPERS', '// SHARED SEARCH HELPERS\n' + inject);

    const dom = new JSDOM(
        `<!DOCTYPE html><html lang="en"><body>${container ? '<div id="scolta-search"></div>' : ''}</body></html>`,
        { url: 'https://example.com/', runScripts: 'dangerously' }
    );
    const win = dom.window;
    const pfCalls = [];
    const fetchCalls = [];
    win.__pfMock = createPagefind(pfCalls);
    if (wasm) win.__wasmMod = Object.assign({ default: () => Promise.resolve() }, wasm);
    win.fetch = jest.fn((url, opts) => {
        const u = String(url);
        if (u.includes('pagefind-entry.json')) {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ languages: { en: { page_count: PAGES.length } } }) });
        }
        fetchCalls.push({ url: u, body: opts && opts.body ? String(opts.body) : null });
        if (u === '/e') {
            const q = JSON.parse(opts.body).query;
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ terms: expansions[q] || [] }), text: () => Promise.resolve('') });
        }
        return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve({}), text: () => Promise.resolve(''), arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)) });
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn(), debug: jest.fn() };
    win.scrollTo = () => {};
    win.eval(source);
    win.scolta = {
        scoring: Object.assign({ AI_SUMMARIZE: false }, scoring),
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        wasmPath: '/wasm.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    };
    if (priorityPages) win.scolta.priority_pages = priorityPages;
    return { win, pfCalls, fetchCalls };
}

const settle = async (n = 40) => { for (let i = 0; i < n; i++) await new Promise(r => setTimeout(r, 0)); };

/** Runs one query through the widget's doSearch() and returns the ranked list. */
async function searchPage(win, query) {
    win.document.querySelector('#scolta-query').value = query;
    await win.Scolta.doSearch();
    await settle();
    return win.__getState().allScoredResults.map(r => [r.data.url, r.score]);
}

module.exports = { PAGES, createCorpusWindow, searchPage, settle };
