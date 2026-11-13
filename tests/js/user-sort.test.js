/**
 * Explicit sort (setSort / getSort / ?sort=) — behavioral tests in JSDOM.
 *
 * The native sort path existed only behind the LLM's sort_hint, so a host
 * could not offer a "Sort: relevance | newest" control. These pin the seam
 * that makes it one: the sort is sticky across queries, lives in the URL,
 * searches the typed query only (no expansion request), and paints the sorted
 * list first rather than reordering a relevance paint.
 *
 * Also pins the comparator fix that came with it: parseFloat() read
 * "2026-11-10" as 2026, so a union of two sorted searches ordered ISO dates by
 * year alone.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Pagefind mock: a term matches the docs listed for it in window.__index, and
// opts.sort orders them the way Pagefind's native sort does.
const sortedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = {' +
    '  init: function() { return Promise.resolve(); },' +
    '  search: function(q, opts) {' +
    '    let docs = ((window.__index || {})[q] || []).slice();' +
    '    const sort = (opts && opts.sort) || null;' +
    '    if (sort) {' +
    '      const field = Object.keys(sort)[0];' +
    '      const dir = sort[field] === "desc" ? -1 : 1;' +
    '      docs = docs.filter(function(d) { return d.meta[field] !== undefined; });' +
    '      docs.sort(function(a, b) { return dir * String(a.meta[field]).localeCompare(String(b.meta[field])); });' +
    '    }' +
    '    (window.__pfCalls = window.__pfCalls || []).push({ q: q, opts: opts || null });' +
    '    return Promise.resolve({ filters: {}, results: docs.map(function(d) { return { id: d.url, data: function() { return Promise.resolve(d); } }; }) });' +
    '  },' +
    '  filters: function() { return Promise.resolve({}); }' +
    '}'
).replace(
    '// SHARED SEARCH HELPERS',
    '// SHARED SEARCH HELPERS\n  window.__getSortState = function() { return { allScoredResults, userSort, currentSortOverride }; };'
);

function doc(n, date) {
    return {
        url: '/doc-' + n,
        // Unique single-token titles, so nothing is collapsed as a duplicate.
        meta: { title: 'entry' + n + 'guide', date: date },
        excerpt: 'fractions content',
        content: 'fractions content',
        filters: {},
    };
}

function createWindow(index, expandResponse, url, top) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>',
        { url: url || 'https://example.com/search', runScripts: 'dangerously' }
    );
    const win = dom.window;
    win.__index = index;
    win.__expandCalls = 0;
    win.fetch = jest.fn().mockImplementation(reqUrl => {
        if (reqUrl === '/e') {
            win.__expandCalls++;
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(expandResponse || { terms: [] }) });
        }
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}), text: () => Promise.resolve('') });
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    win.scrollTo = () => {};
    win.__renders = [];
    win.document.addEventListener('scolta:results-rendered', e => {
        win.__renders.push({ urls: e.detail.results.map(r => r.data.url), sort: e.detail.sort });
    });
    win.eval(sortedSource);
    win.scolta = Object.assign({
        scoring: {},
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer: '',
    }, top || {});
    win.Scolta.init('#scolta-search');
    return win;
}

const settle = () => new Promise(r => setTimeout(r, 250));

async function runSearch(win, query) {
    win.document.querySelector('#scolta-query').value = query;
    win.document.querySelector('#scolta-search-btn').click();
    await settle();
}

const urlsOf = win => win.__getSortState().allScoredResults.map(r => r.data.url);

const INDEX = {
    fractions: [doc(1, '2024-03-01'), doc(2, '2026-01-15'), doc(3, '2025-07-04')],
    decimals: [doc(4, '2026-06-30'), doc(5, '2023-02-02')],
};

describe('explicit sort', () => {

    test('setSort orders the current search and writes ?sort=', async () => {
        const win = createWindow(INDEX);
        await runSearch(win, 'fractions');
        expect(win.Scolta.getSort()).toBeNull();

        win.Scolta.setSort({ field: 'date', direction: 'desc' });
        await settle();

        expect(urlsOf(win)).toEqual(['/doc-2', '/doc-3', '/doc-1']);
        expect(win.Scolta.getSort()).toEqual({ field: 'date', direction: 'desc' });
        expect(new URL(win.location.href).searchParams.get('sort')).toBe('date:desc');
        expect(win.__renders[win.__renders.length - 1].sort).toEqual({ field: 'date', direction: 'desc' });
    });

    test('ascending sort reverses it', async () => {
        const win = createWindow(INDEX);
        await runSearch(win, 'fractions');
        win.Scolta.setSort({ field: 'date', direction: 'asc' });
        await settle();
        expect(urlsOf(win)).toEqual(['/doc-1', '/doc-3', '/doc-2']);
    });

    test('sticky across a new query, which requests no expansion and never paints relevance order', async () => {
        const win = createWindow(INDEX, { terms: ['fractions'] });
        await runSearch(win, 'fractions');
        win.Scolta.setSort({ field: 'date', direction: 'desc' });
        await settle();

        const expandsBefore = win.__expandCalls;
        win.__renders.length = 0;
        await runSearch(win, 'decimals');

        expect(win.__expandCalls).toBe(expandsBefore);
        expect(urlsOf(win)).toEqual(['/doc-4', '/doc-5']);
        expect(new URL(win.location.href).searchParams.get('sort')).toBe('date:desc');
        // Every paint that showed rows showed them sorted.
        const painted = win.__renders.filter(r => r.urls.length > 0);
        expect(painted.length).toBeGreaterThan(0);
        for (const r of painted) {
            expect(r.urls).toEqual(['/doc-4', '/doc-5']);
        }
    });

    test('expansion terms are not unioned into an explicitly sorted search', async () => {
        const win = createWindow(INDEX, { terms: ['decimals'] });
        await runSearch(win, 'fractions');
        // Relevance: the expansion brought the decimals docs in.
        expect(urlsOf(win).length).toBe(5);

        win.Scolta.setSort({ field: 'date', direction: 'desc' });
        await settle();
        expect(urlsOf(win)).toEqual(['/doc-2', '/doc-3', '/doc-1']);
    });

    test('setSort(null) returns to relevance and drops the param', async () => {
        const win = createWindow(INDEX, { terms: ['decimals'] });
        await runSearch(win, 'fractions');
        win.Scolta.setSort({ field: 'date', direction: 'desc' });
        await settle();
        const expandsBefore = win.__expandCalls;

        win.Scolta.setSort(null);
        await settle();

        expect(win.Scolta.getSort()).toBeNull();
        expect(new URL(win.location.href).searchParams.has('sort')).toBe(false);
        // The stored expansion is reused, not re-billed.
        expect(win.__expandCalls).toBe(expandsBefore);
        expect(urlsOf(win).length).toBe(5);
    });

    test('a search that started sorted requests its expansion on return to relevance', async () => {
        const win = createWindow(INDEX, { terms: ['decimals'] }, 'https://example.com/search?q=fractions&sort=date:desc');
        await settle();
        expect(urlsOf(win)).toEqual(['/doc-2', '/doc-3', '/doc-1']);
        expect(win.__expandCalls).toBe(0);

        win.Scolta.setSort(null);
        await settle();
        expect(win.__expandCalls).toBe(1);
        expect(urlsOf(win).length).toBe(5);
    });

    test('a field no result carries falls back to relevance for that search', async () => {
        const win = createWindow(INDEX);
        await runSearch(win, 'fractions');
        win.Scolta.setSort({ field: 'price', direction: 'asc' });
        await settle();
        expect(urlsOf(win).length).toBe(3);
        expect(win.Scolta.getSort()).toBeNull();
    });

    test('a browse (no query) sorts too', async () => {
        // The mock keys a null term as "null": the whole corpus, as Pagefind
        // returns for one. 'disabled' keeps the browse on Pagefind rather than
        // the facet artifact this fixture does not have.
        const index = { null: INDEX.fractions.concat(INDEX.decimals) };
        const win = createWindow(index);
        win.document.querySelector('#scolta-query').value = '';
        win.document.querySelector('#scolta-search-btn').click();
        await settle();
        win.Scolta.setSort({ field: 'date', direction: 'desc' });
        await settle();
        expect(urlsOf(win)).toEqual(['/doc-4', '/doc-2', '/doc-3', '/doc-1', '/doc-5']);
        expect(win.Scolta.getSort()).toEqual({ field: 'date', direction: 'desc' });
    });

    test('clearSearch drops the sort', async () => {
        const win = createWindow(INDEX);
        await runSearch(win, 'fractions');
        win.Scolta.setSort({ field: 'date', direction: 'desc' });
        await settle();
        win.Scolta.clearSearch();
        expect(new URL(win.location.href).searchParams.has('sort')).toBe(false);
        await runSearch(win, 'fractions');
        expect(win.Scolta.getSort()).toBeNull();
    });

    test('rejects a malformed sort', async () => {
        const win = createWindow(INDEX);
        expect(() => win.Scolta.setSort({ field: 'date' })).toThrow(/setSort expects/);
        expect(() => win.Scolta.setSort({ field: 'da te', direction: 'asc' })).toThrow(/setSort expects/);
        expect(() => win.Scolta.setSort('date')).toThrow(/setSort expects/);
    });

    test('a malformed ?sort= is ignored', async () => {
        const win = createWindow(INDEX, null, 'https://example.com/search?q=fractions&sort=date:sideways');
        await settle();
        expect(win.Scolta.getSort()).toBeNull();
        expect(urlsOf(win).length).toBe(3);
    });
});

// A search under an explicit sort requests no expansion, so the header must
// not offer a switch for it.
describe('expansion switch in the results header', () => {

    const header = win => win.document.querySelector('#scolta-results-header');
    const toggle = win => header(win).querySelector('[data-scolta-expansion-toggle]');

    test('present under relevance', async () => {
        const win = createWindow(INDEX, { terms: ['decimals'] });
        await runSearch(win, 'fractions');
        expect(toggle(win)).not.toBeNull();
        expect(header(win).textContent).toContain('expanded terms');
    });

    test('absent while explicitly sorted, and back after setSort(null)', async () => {
        const win = createWindow(INDEX, { terms: ['decimals'] });
        await runSearch(win, 'fractions');

        win.Scolta.setSort({ field: 'date', direction: 'desc' });
        await settle();
        expect(toggle(win)).toBeNull();
        expect(header(win).textContent).toMatch(/results for "fractions"$/);

        // Still absent on a new query run under the same sort.
        await runSearch(win, 'decimals');
        expect(toggle(win)).toBeNull();
        expect(header(win).textContent).not.toContain('expanded terms');

        win.Scolta.setSort(null);
        await settle();
        expect(toggle(win)).not.toBeNull();
    });

    test('absent on a search that started sorted from ?sort=', async () => {
        const win = createWindow(INDEX, { terms: ['decimals'] }, 'https://example.com/search?q=fractions&sort=date:desc');
        await settle();
        expect(toggle(win)).toBeNull();

        win.Scolta.setSort(null);
        await settle();
        expect(toggle(win)).not.toBeNull();
    });

    test('present under an LLM sort hint, which does use expansion', async () => {
        const win = createWindow(INDEX, {
            terms: ['decimals'],
            sort_hint: { field: 'date', direction: 'desc' },
        });
        await runSearch(win, 'fractions');
        expect(win.Scolta.getSort()).toEqual({ field: 'date', direction: 'desc' });
        expect(toggle(win)).not.toBeNull();
    });
});

describe('LLM sort hint', () => {

    test('ISO dates from a union of terms order by full date, not year', async () => {
        // Same year across both terms: year-only comparison left them in
        // arrival order (all of "fractions", then all of "decimals").
        const index = {
            fractions: [doc(1, '2026-01-10'), doc(2, '2026-09-01')],
            decimals: [doc(3, '2026-05-05'), doc(4, '2026-12-25')],
        };
        const win = createWindow(index, {
            terms: ['decimals'],
            sort_hint: { field: 'date', direction: 'desc' },
        });
        await runSearch(win, 'fractions');
        expect(urlsOf(win)).toEqual(['/doc-4', '/doc-2', '/doc-3', '/doc-1']);
        // A hint is not the visitor's choice: it is not written to the URL.
        expect(new URL(win.location.href).searchParams.has('sort')).toBe(false);
    });
});

describe('configured sort control', () => {

    const TOP = { sortOptions: [{ field: 'date', direction: 'desc', label: 'Newest' }] };
    const control = win => win.document.querySelector('#scolta-sort-indicator');
    const optionTexts = win => [...control(win).querySelectorAll('[data-scolta-sort]')].map(a => a.textContent);
    const current = win => control(win).querySelector('[aria-current="true"]').textContent;

    test('without sortOptions nothing is rendered until a sort is in force', async () => {
        const win = createWindow(INDEX);
        await runSearch(win, 'fractions');
        expect(control(win).style.display).toBe('none');
        expect(control(win).querySelector('[data-scolta-sort]')).toBeNull();
    });

    test('renders Relevance plus each option, Relevance current', async () => {
        const win = createWindow(INDEX, null, null, TOP);
        await runSearch(win, 'fractions');
        expect(control(win).style.display).toBe('block');
        expect(control(win).querySelector('.scolta-sort-label').textContent).toBe('Sort:');
        expect(optionTexts(win)).toEqual(['Relevance', 'Newest']);
        expect(current(win)).toBe('Relevance');
    });

    test('options are links to the same search in that order', async () => {
        const win = createWindow(INDEX, null, null, TOP);
        await runSearch(win, 'fractions');
        const [relevance, newest] = control(win).querySelectorAll('[data-scolta-sort]');
        expect(newest.getAttribute('href')).toBe('/search?q=fractions&sort=date%3Adesc');
        expect(relevance.getAttribute('href')).toBe('/search?q=fractions');
    });

    test('clicking an option sorts in place; clicking Relevance undoes it', async () => {
        const win = createWindow(INDEX, null, null, TOP);
        await runSearch(win, 'fractions');

        control(win).querySelector('[data-scolta-sort="date:desc"]').click();
        await settle();
        expect(urlsOf(win)).toEqual(['/doc-2', '/doc-3', '/doc-1']);
        expect(current(win)).toBe('Newest');
        expect(new URL(win.location.href).searchParams.get('sort')).toBe('date:desc');

        control(win).querySelector('[data-scolta-sort=""]').click();
        await settle();
        expect(current(win)).toBe('Relevance');
        expect(new URL(win.location.href).searchParams.has('sort')).toBe(false);
    });

    test('a modified click is left to the browser', async () => {
        const win = createWindow(INDEX, null, null, TOP);
        await runSearch(win, 'fractions');
        const link = control(win).querySelector('[data-scolta-sort="date:desc"]');
        const event = new win.MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true });
        link.dispatchEvent(event);
        await settle();
        expect(event.defaultPrevented).toBe(false);
        expect(win.Scolta.getSort()).toBeNull();
    });

    test('an LLM hint on a configured option marks it current', async () => {
        const win = createWindow(INDEX, { terms: [], sort_hint: { field: 'date', direction: 'desc' } }, null, TOP);
        await runSearch(win, 'fractions');
        expect(current(win)).toBe('Newest');
    });

    test('a sort in force that is not an option keeps its badge', async () => {
        const win = createWindow(INDEX, null, null, TOP);
        await runSearch(win, 'fractions');
        win.Scolta.setSort({ field: 'date', direction: 'asc' });
        await settle();
        expect(control(win).querySelector('[aria-current="true"]')).toBeNull();
        expect(control(win).querySelector('.scolta-sort-badge')).not.toBeNull();
    });

    test('labels are overridable and escaped; malformed options are dropped', async () => {
        const win = createWindow(INDEX, null, null, {
            labels: { sort: 'Order by', sortRelevance: 'Best match' },
            sortOptions: [
                { field: 'date', label: '<b>New</b>' },
                { field: 'bad field', direction: 'desc', label: 'Nope' },
                { field: 'date', direction: 'sideways', label: 'Nope' },
                'date',
            ],
        });
        await runSearch(win, 'fractions');
        expect(control(win).querySelector('.scolta-sort-label').textContent).toBe('Order by');
        expect(optionTexts(win)).toEqual(['Best match', '<b>New</b>']);
        expect(control(win).querySelector('b')).toBeNull();
    });
});
