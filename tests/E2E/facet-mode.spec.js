// @ts-check
/**
 * End-to-end test: facetMode, asserted at the network layer.
 *
 * The Jest suite drives facetMode in JSDOM against a Pagefind mock, where "no
 * .pf_filter chunk was fetched" can only be asserted through its proximate
 * cause: that no search carried a filters option and pagefind.filters() was
 * never called. That is the right proxy, but it is still a proxy — it reasons
 * about what Pagefind WOULD do rather than watching it do it.
 *
 * This watches. It builds a real index with the PHP indexer, serves it with the
 * real bundle to a real browser, and records every HTTP request the page makes.
 * The two files the modes are about are then directly observable:
 *
 *   pagefind/scolta.<hash>.facets — the Scolta facet artifact
 *   pagefind/filter/*.pf_filter   — Pagefind's own filter chunk, whose arrival
 *                                   is the regression this feature must never
 *                                   cause. Once one is loaded, Pagefind's
 *                                   get_filters scans it on every later search
 *                                   for the life of the page, and nothing
 *                                   unloads it short of pagefind.destroy().
 *
 * The corpus is the 25-page fixture the other E2E specs use, carrying a
 * `category` filter, so both files genuinely exist in the built index — an
 * assertion that nothing was fetched is worthless against an index with nothing
 * to fetch, and the eager case below is what proves they are really there.
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const REPO_ROOT = path.join(__dirname, '../..');
const OUTPUT_DIR = path.join(REPO_ROOT, '.e2e-output-facet-mode');

let server;
let baseUrl;

function pageHtml(facetMode) {
    // AUTO_LANGUAGE_FILTER is off so the only facet activity is the one the
    // test performs: the language auto-filter is itself a selection, and it
    // would trigger the deferred load before any click.
    return `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>facetMode E2E</title>
<link rel="stylesheet" href="/scolta.css"></head>
<body>
<div id="scolta-search"></div>
<script>
window.scolta = {
  scoring: { AI_EXPAND_QUERY: false, AI_SUMMARIZE: false, AUTO_LANGUAGE_FILTER: false },
  endpoints: { expand: '/api/expand', summarize: '/api/summarize', followup: '/api/followup' },
  pagefindPath: '/pagefind/pagefind.js',
  wasmPath: '/wasm/scolta_core.js',
  siteName: 'E2E',
  container: '#scolta-search',
  saytEnabled: false,
  facetMode: ${JSON.stringify(facetMode)}
};
</script>
<script src="/scolta.js"></script>
</body></html>`;
}

test.beforeAll(async () => {
    if (fs.existsSync(OUTPUT_DIR)) {
        fs.rmSync(OUTPUT_DIR, { recursive: true, force: true });
    }

    execSync(`php ${path.join(__dirname, 'build-php-index.php')} ${OUTPUT_DIR}`, {
        cwd: REPO_ROOT,
        stdio: 'pipe',
    });

    const pagefindDir = path.join(OUTPUT_DIR, 'pagefind');
    if (!fs.existsSync(path.join(pagefindDir, 'pagefind-entry.json'))) {
        throw new Error('pagefind-entry.json not found after build');
    }
    // Fail the run, not a single assertion, if the corpus stopped carrying the
    // two files every test below is about. The facet artifact's filename
    // carries the pf_meta hash it was built against (FacetIndexWriter), so it
    // is found by pattern rather than by a fixed name.
    if ((fs.readdirSync(pagefindDir).filter(f => /^scolta\..+\.facets$/.test(f))).length === 0) {
        throw new Error('the built index has no scolta.<hash>.facets — nothing here would mean anything');
    }
    const filterDir = path.join(pagefindDir, 'filter');
    if (!fs.existsSync(filterDir) || fs.readdirSync(filterDir).length === 0) {
        throw new Error('the built index has no .pf_filter chunk — the regression guard would be vacuous');
    }

    const TYPES = {
        '.html': 'text/html; charset=utf-8',
        '.js': 'application/javascript',
        '.css': 'text/css',
        '.json': 'application/json',
        '.wasm': 'application/wasm',
        '.pagefind': 'application/octet-stream',
    };

    server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        const urlPath = decodeURIComponent(url.pathname);

        if (urlPath === '/' || urlPath === '/index.html') {
            res.writeHead(200, { 'Content-Type': TYPES['.html'] });
            res.end(pageHtml(url.searchParams.get('mode') || 'eager'));
            return;
        }

        const candidates = [];
        if (urlPath === '/scolta.js') candidates.push(path.join(REPO_ROOT, 'assets/js/scolta.js'));
        else if (urlPath === '/scolta.css') candidates.push(path.join(REPO_ROOT, 'assets/css/scolta.css'));
        else if (urlPath.startsWith('/wasm/')) candidates.push([path.join(REPO_ROOT, 'assets'), urlPath]);
        else candidates.push([OUTPUT_DIR, urlPath]);

        for (const candidate of candidates) {
            let filePath;
            if (Array.isArray(candidate)) {
                // Resolve, then confirm the result is still inside its root.
                const [root, rel] = candidate;
                filePath = path.resolve(root, '.' + path.posix.normalize('/' + rel));
                if (filePath !== root && !filePath.startsWith(root + path.sep)) continue;
            } else {
                filePath = candidate;
            }
            if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
                res.writeHead(200, {
                    'Content-Type': TYPES[path.extname(filePath)] || 'application/octet-stream',
                    'Access-Control-Allow-Origin': '*',
                });
                res.end(fs.readFileSync(filePath));
                return;
            }
        }
        res.writeHead(404);
        res.end('Not found: ' + req.url);
    });

    await new Promise((resolve) => {
        server.listen(0, () => {
            baseUrl = `http://localhost:${server.address().port}`;
            resolve();
        });
    });
});

test.afterAll(async () => {
    if (server) server.close();
});

/**
 * Load the widget under one mode, recording every request the page makes.
 *
 * Requests are recorded from before navigation, so the init-time loads the
 * eager case is about are captured too.
 */
async function openWidget(page, mode) {
    const requests = [];
    page.on('request', r => requests.push(new URL(r.url()).pathname));

    await page.goto(`${baseUrl}/?mode=${mode}`);
    await page.waitForSelector('#scolta-query', { timeout: 15000 });
    // initPagefind() ends with a warm-up search and, under 'eager', the
    // taxonomy load. Neither is observable from the DOM, so settle instead.
    await page.waitForTimeout(2500);

    return {
        requests,
        facetArtifactLoads: () => requests.filter(p => /scolta\.[^/]+\.facets$/.test(p)).length,
        filterChunkLoads: () => requests.filter(p => /\.pf_filter$/.test(p)).length,
    };
}

async function runSearch(page, query) {
    const input = page.locator('#scolta-query');
    await input.click();
    await input.fill(query);
    await input.press('Enter');
    await page.waitForSelector('.scolta-result-card', { timeout: 15000 });
    await page.waitForTimeout(1500);
}

/**
 * Apply a facet selection the way a host's own facet UI does, and settle.
 *
 * `site` is the dimension this corpus actually carries — build-php-index.php
 * maps the fixture's `category:` attribute onto it — and `Technology` is its
 * largest value. Both are real, so the selection genuinely filters rather than
 * matching nothing and passing vacuously.
 */
async function applyFacet(page) {
    await page.evaluate(() => window.Scolta.toggleFilter('site', 'Technology'));
    await page.waitForTimeout(2500);
}

const RESULTS_FOR_SEARCH = 6;
const RESULTS_FILTERED_TO_TECHNOLOGY = 3;

/**
 * Note on the filter panel: it is NOT asserted here, in any mode.
 *
 * This corpus's only dimensions are `site` and `language`, both of which
 * renderFilters() deliberately skips as infrastructure
 * (SKIP_FILTER_DIMENSIONS), so the panel is empty under eager, deferred and
 * disabled alike and a DOM assertion would pass without discriminating between
 * them. Verified against the pre-change bundle: it renders nothing here either.
 * Panel rendering — populated under eager, absent under disabled — is pinned in
 * tests/js/facet-index.test.js, whose mock supplies an ordinary dimension.
 *
 * What this file is for is the network layer, which the JSDOM suite cannot see.
 */

test('eager (default): the artifact loads at init, and filtering never fetches a filter chunk', async ({ page }) => {
    const net = await openWidget(page, 'eager');

    // Arrives before any search. This also proves the built index really
    // carries the artifact, which every "nothing was fetched" assertion in the
    // other two modes depends on.
    expect(net.facetArtifactLoads()).toBe(1);

    await runSearch(page, 'search');
    expect(await page.locator('.scolta-result-card').count()).toBe(RESULTS_FOR_SEARCH);

    await applyFacet(page);
    expect(await page.locator('.scolta-result-card').count()).toBe(RESULTS_FILTERED_TO_TECHNOLOGY);

    // The property the artifact exists to provide, and the baseline the other
    // modes must match.
    expect(net.filterChunkLoads()).toBe(0);
});

test('deferred: page load and an unfiltered search fetch neither file', async ({ page }) => {
    const net = await openWidget(page, 'deferred');

    expect(net.facetArtifactLoads()).toBe(0);

    await runSearch(page, 'search');
    expect(await page.locator('.scolta-result-card').count()).toBe(RESULTS_FOR_SEARCH);

    // The win: a session that never filters pays for neither file.
    expect(net.facetArtifactLoads()).toBe(0);
    expect(net.filterChunkLoads()).toBe(0);
});

test('deferred: the first facet selection fetches the artifact once and filters identically', async ({ page }) => {
    const net = await openWidget(page, 'deferred');
    await runSearch(page, 'search');
    expect(net.facetArtifactLoads()).toBe(0);

    await applyFacet(page);

    // The whole design in three numbers. The artifact arrived; Pagefind was
    // never asked to filter; and the result list is the one eager produces.
    // Had the deferred load not completed above the branch that hands filters
    // to Pagefind, the middle number would be 1 and every later search on the
    // page would pay for it.
    expect(net.facetArtifactLoads()).toBe(1);
    expect(net.filterChunkLoads()).toBe(0);
    expect(await page.locator('.scolta-result-card').count()).toBe(RESULTS_FILTERED_TO_TECHNOLOGY);

    // Repeated facet activity must not refetch.
    await applyFacet(page);
    expect(net.facetArtifactLoads()).toBe(1);
    expect(net.filterChunkLoads()).toBe(0);
});

test('disabled: neither file is fetched, and search works with facets inert', async ({ page }) => {
    const net = await openWidget(page, 'disabled');

    await runSearch(page, 'search');

    // The mode removes facets, not search.
    expect(await page.locator('.scolta-result-card').count()).toBe(RESULTS_FOR_SEARCH);
    expect(net.facetArtifactLoads()).toBe(0);
    expect(net.filterChunkLoads()).toBe(0);

    // A host calling the public API anyway must not resurrect either fetch, and
    // must not narrow the list: with no artifact, honouring the selection could
    // only mean handing it to Pagefind, which is the chunk load this mode exists
    // to avoid.
    await applyFacet(page);
    expect(await page.locator('.scolta-result-card').count()).toBe(RESULTS_FOR_SEARCH);
    expect(net.facetArtifactLoads()).toBe(0);
    expect(net.filterChunkLoads()).toBe(0);
});
