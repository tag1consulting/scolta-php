/**
 * Recall guard for LLM filter hints — behavioral tests in JSDOM.
 *
 * Regression (2026-06-09, parity Q10/Q19/Q20): the expand response's
 * filter_hint auto-applied and STACKED topic filters that are individually
 * plausible but jointly near-empty. "most popular git workflows" collapsed
 * from 76 results to 1 on the git-manual corpus (live repro) because the
 * hint {topic: "Branching"} was applied unconditionally.
 *
 * The guard probes the real index before committing: a hint is auto-applied
 * only when the filtered union keeps at least FILTER_HINT_MIN_RESULTS results
 * AND at least FILTER_HINT_MIN_RATIO of the unfiltered union. Declined hints
 * become offered (clickable, not applied) chips.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Pagefind mock that honors opts.filters against per-doc facet values, so the
// guard's count probes see realistic collapse behavior.
const guardedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = {' +
    '  init: function() { return Promise.resolve(); },' +
    '  search: function(q, opts) {' +
    // Only the expansion terms hit: the typed query is a multi-word AND that
    // matches nothing on this corpus (the real Q20 shape — results come from
    // the expanded-term union, which is what the hint filters).
    '    let docs = (window.__matchTerms || []).indexOf(q) !== -1 ? (window.__docs || []) : [];' +
    '    const f = (opts && opts.filters) || null;' +
    '    if (f) {' +
    '      docs = docs.filter(function(d) {' +
    '        return Object.entries(f).every(function(entry) {' +
    '          const have = (d.filters || {})[entry[0]];' +
    '          const want = entry[1];' +
    '          if (want && typeof want === "object" && Array.isArray(want.any)) { return want.any.indexOf(have) !== -1; }' +
    '          return have === want;' +
    '        });' +
    '      });' +
    '    }' +
    '    (window.__pfCalls = window.__pfCalls || []).push({ q: q, opts: opts || null });' +
    '    return Promise.resolve({ filters: {}, results: docs.map(function(d) { return { id: d.url, data: function() { return Promise.resolve(d); } }; }) });' +
    '  },' +
    '  filters: function() { return Promise.resolve(window.__filters || {}); }' +
    '}'
).replace(
    '// SHARED SEARCH HELPERS',
    '// SHARED SEARCH HELPERS\n  window.__getFilterState = function() { return { activeFilters, llmAppliedFilters, offeredLlmFilters, allScoredResults }; };'
);

function makeDocs(spec) {
    // spec: array of [count, filters] pairs.
    const docs = [];
    let i = 0;
    for (const [count, filters] of spec) {
        for (let n = 0; n < count; n++) {
            i++;
            docs.push({
                url: '/doc-' + i,
                // Unique single-token titles: scolta.js fuzzy-dedupes results
                // whose titles share most words (deduplicateByTitle).
                meta: { title: 'entry' + i + 'guide' },
                excerpt: 'git workflow content',
                content: 'git workflow content',
                filters,
            });
        }
    }
    return docs;
}

function createGuardWindow(expandResponse, pagefindFilters, docs, scoring) {
    // lang="en" feeds defaultLangCode so AUTO_LANGUAGE_FILTER tests can
    // engage; inert for the rest (the flag defaults to false).
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;
    win.__docs = docs;
    win.__matchTerms = (expandResponse && expandResponse.terms) || [];
    win.__filters = pagefindFilters;
    win.fetch = jest.fn().mockImplementation(url => {
        if (url === '/e') {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(expandResponse) });
        }
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve('') });
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    win.scrollTo = () => {};
    win.eval(guardedSource);
    win.scolta = {
        scoring: scoring || {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    };
    win.Scolta.init('#scolta-search');
    return win;
}

async function runSearch(win, query) {
    const input = win.document.querySelector('#scolta-query');
    input.value = query;
    win.document.querySelector('#scolta-search-btn').click();
    await new Promise(r => setTimeout(r, 250));
}

describe('filter-hint recall guard', () => {

    test('collapsing hint is offered, not applied — recall preserved', async () => {
        // 29 docs without the hinted topic, 1 with it: the git-manual Q20 shape.
        const docs = makeDocs([
            [29, { topic: 'Basics' }],
            [1, { topic: 'Branching' }],
        ]);
        const win = createGuardWindow(
            { terms: ['git workflows', 'branching models'], filter_hint: { topic: 'Branching' } },
            { topic: { Basics: 29, Branching: 1 } },
            docs
        );

        await runSearch(win, 'most popular git workflows');

        const state = win.__getFilterState();
        expect(state.llmAppliedFilters).toEqual({});
        expect(state.offeredLlmFilters).toEqual({ topic: 'Branching' });
        expect(state.activeFilters.topic).toBeUndefined();

        // Full recall: all 30 docs in the result set, not 1.
        expect(state.allScoredResults.length).toBe(30);

        // The offered chip renders; no applied badge.
        const indicator = win.document.querySelector('#scolta-filter-indicator');
        expect(indicator.style.display).toBe('block');
        expect(indicator.innerHTML).toContain('Filter by topic: Branching');
        expect(indicator.innerHTML).not.toContain('Filtered: topic');
    });

    test('hint on a dimension absent from the index is dropped entirely (no chip)', async () => {
        // Observed live on the git-manual drupal demo: filter_fields names
        // "topic" while the index exposes "section" — every search filtered
        // by the hint returns 0. Offering it would be a one-click empty page.
        const docs = makeDocs([
            [30, { section: 'Basics' }],
        ]);
        const win = createGuardWindow(
            { terms: ['git workflows', 'branching models'], filter_hint: { topic: 'Branching' } },
            { section: { Basics: 30 } },
            docs
        );

        await runSearch(win, 'most popular git workflows');

        const state = win.__getFilterState();
        expect(state.llmAppliedFilters).toEqual({});
        expect(state.offeredLlmFilters).toEqual({});
        expect(state.allScoredResults.length).toBe(30);
        expect(win.document.querySelector('#scolta-filter-indicator').style.display).toBe('none');
    });

    test('healthy hint applies exactly as before', async () => {
        // 20 of 35 docs match the hint: a useful narrowing, not a collapse
        // (the blessed "merge conflict resolution" → Merging baseline shape).
        const docs = makeDocs([
            [15, { topic: 'Basics' }],
            [20, { topic: 'Merging' }],
        ]);
        const win = createGuardWindow(
            { terms: ['merge conflicts', 'conflict markers'], filter_hint: { topic: 'Merging' } },
            { topic: { Basics: 15, Merging: 20 } },
            docs
        );

        await runSearch(win, 'merge conflict resolution');

        const state = win.__getFilterState();
        expect(state.llmAppliedFilters).toEqual({ topic: 'Merging' });
        expect(state.offeredLlmFilters).toEqual({});
        expect([...state.activeFilters.topic]).toEqual(['Merging']);
        expect(state.allScoredResults.length).toBe(20);

        const indicator = win.document.querySelector('#scolta-filter-indicator');
        expect(indicator.innerHTML).toContain('Filtered: topic = Merging');
        expect(indicator.innerHTML).not.toContain('Filter by');
    });

    test('stacked hints with healthy marginals but collapsed joint: first applies, second is offered', async () => {
        // topic=Comparisons alone keeps 12, level=Expert alone keeps 10, but
        // their intersection is 1 doc — the Q20-django stacking shape.
        const docs = makeDocs([
            [18, { topic: 'Basics', level: 'Beginner' }],
            [11, { topic: 'Comparisons', level: 'Beginner' }],
            [9, { topic: 'Basics', level: 'Expert' }],
            [1, { topic: 'Comparisons', level: 'Expert' }],
        ]);
        const win = createGuardWindow(
            { terms: ['git workflows'], filter_hint: { topic: 'Comparisons', level: 'Expert' } },
            { topic: { Basics: 27, Comparisons: 12 }, level: { Beginner: 29, Expert: 10 } },
            docs
        );

        await runSearch(win, 'most popular git workflows');

        const state = win.__getFilterState();
        expect(state.llmAppliedFilters).toEqual({ topic: 'Comparisons' });
        expect(state.offeredLlmFilters).toEqual({ level: 'Expert' });
        expect(state.allScoredResults.length).toBe(12);
    });

    test('clicking an offered chip applies the filter explicitly', async () => {
        const docs = makeDocs([
            [29, { topic: 'Basics' }],
            [1, { topic: 'Branching' }],
        ]);
        const win = createGuardWindow(
            { terms: ['git workflows'], filter_hint: { topic: 'Branching' } },
            { topic: { Basics: 29, Branching: 1 } },
            docs
        );

        await runSearch(win, 'most popular git workflows');
        expect(win.__getFilterState().offeredLlmFilters).toEqual({ topic: 'Branching' });

        win.document.querySelector('[data-scolta-filter-offer-dim]').click();
        await new Promise(r => setTimeout(r, 250));

        const state = win.__getFilterState();
        expect(state.llmAppliedFilters).toEqual({ topic: 'Branching' });
        expect(state.offeredLlmFilters).toEqual({});
        expect([...state.activeFilters.topic]).toEqual(['Branching']);
    });

    test('language auto-filter narrowing survives and scopes the guard baseline', async () => {
        // 10 en docs + 20 de docs; hint keeps 6 of the 10 en docs. The
        // baseline must be the language-narrowed 10 (hint healthy: 6/10),
        // not the raw 30.
        const docs = makeDocs([
            [4, { topic: 'Basics', language: 'en' }],
            [6, { topic: 'Merging', language: 'en' }],
            [20, { topic: 'Basics', language: 'de' }],
        ]);
        const win = createGuardWindow(
            { terms: ['merge conflicts'], filter_hint: { topic: 'Merging' } },
            { topic: { Basics: 24, Merging: 6 }, language: { en: 10, de: 20 } },
            docs,
            { AUTO_LANGUAGE_FILTER: true, AI_LANGUAGES: ['en', 'de'] }
        );

        await runSearch(win, 'merge conflict resolution');

        const state = win.__getFilterState();
        // Language auto-filter applied silently (no badge for it), hint applied on top.
        expect([...state.activeFilters.language]).toEqual(['en']);
        expect(state.llmAppliedFilters).toEqual({ topic: 'Merging' });
        expect(state.allScoredResults.length).toBe(6);

        const indicator = win.document.querySelector('#scolta-filter-indicator');
        expect(indicator.innerHTML).not.toContain('language');
    });

    test('guard disabled (both knobs 0) restores always-apply behavior', async () => {
        const docs = makeDocs([
            [29, { topic: 'Basics' }],
            [1, { topic: 'Branching' }],
        ]);
        const win = createGuardWindow(
            { terms: ['git workflows'], filter_hint: { topic: 'Branching' } },
            { topic: { Basics: 29, Branching: 1 } },
            docs,
            { FILTER_HINT_MIN_RESULTS: 0, FILTER_HINT_MIN_RATIO: 0 }
        );

        await runSearch(win, 'most popular git workflows');

        const state = win.__getFilterState();
        expect(state.llmAppliedFilters).toEqual({ topic: 'Branching' });
        expect(state.offeredLlmFilters).toEqual({});
        expect(state.allScoredResults.length).toBe(1);
    });

    test('case-insensitive hint canonicalization still happens before the guard', async () => {
        const docs = makeDocs([
            [15, { topic: 'Basics' }],
            [20, { topic: 'Merging' }],
        ]);
        const win = createGuardWindow(
            { terms: ['merge conflicts'], filter_hint: { topic: 'merging' } },
            { topic: { Basics: 15, Merging: 20 } },
            docs
        );

        await runSearch(win, 'merge conflict resolution');

        const state = win.__getFilterState();
        expect(state.llmAppliedFilters).toEqual({ topic: 'Merging' });
    });
});

describe('filter-hint recall guard: defaults', () => {
    test('FILTER_HINT_MIN_RESULTS default is 5 in both config blocks', () => {
        const matches = scoltaSource.match(/FILTER_HINT_MIN_RESULTS:\s*s\.FILTER_HINT_MIN_RESULTS\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        expect(matches.length).toBeGreaterThanOrEqual(2);
        for (const m of matches) {
            expect(m).toContain('5');
        }
    });

    test('FILTER_HINT_MIN_RATIO default is 0.1 in both config blocks', () => {
        const matches = scoltaSource.match(/FILTER_HINT_MIN_RATIO:\s*s\.FILTER_HINT_MIN_RATIO\s*\?\?\s*([\d.]+)/g);
        expect(matches).not.toBeNull();
        expect(matches.length).toBeGreaterThanOrEqual(2);
        for (const m of matches) {
            expect(m).toContain('0.1');
        }
    });
});
