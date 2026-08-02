// @ts-check
/**
 * End-to-end test: the AI summary slot must not shift the page.
 *
 * The summary is fetched after the result list is already painted (the
 * summarize call is deliberately deferred until query expansion has settled,
 * so the model sees the ranking the user sees). Inserting it above
 * #scolta-results therefore pushes the entire list down, and every pixel of
 * that push is cumulative layout shift.
 *
 * JSDOM has no layout, so it cannot see this at all. This spec runs the real
 * bundle in real Chromium against a real Pagefind index, drives the four
 * summary outcomes from a controllable stub endpoint, and reads the browser's
 * own layout-shift entries.
 *
 * Methodology: CLS is measured over the search cycle only, with the observer
 * installed after the page has settled. The summary's own contribution is the
 * difference between a run with AI_SUMMARIZE on and the same run with it off
 * (the "disabled" case is the floor: the result list replacing "Searching..."
 * shifts whatever sits below it either way, and that is not this slot's doing).
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const REPO_ROOT = path.join(__dirname, '../..');
const OUTPUT_DIR = path.join(REPO_ROOT, '.e2e-output-summary-cls');
const CORPUS_DIR = path.join(REPO_ROOT, 'tests/fixtures/concordance/corpus');

// BOTH delays must exceed the 500ms recent-input window, and that is the whole
// point of these numbers.
//
// The slot is inserted twice: once when the skeleton appears (after expansion
// settles) and again when the skeleton is swapped for the resolved summary.
// With a fast stub the first insertion lands inside the 500ms that follows the
// search click, so the browser attributes it to the user and excludes it, and
// the test flatters the widget by measuring only the second one. A real query
// expansion is an LLM round trip well past 500ms, so in production both
// insertions count. These delays reproduce that.
const EXPAND_DELAY_MS = 1200;
const SUMMARIZE_DELAY_MS = 900;

const SHORT_SUMMARY =
    'The concordance corpus collects short reference pages. These results cover the topic directly.';

// Comfortably taller than any sane reserved height, so the clamp path is
// exercised rather than the fits-inside path.
const LONG_SUMMARY = Array.from(
    { length: 12 },
    (_, i) =>
        `Paragraph ${i + 1}: the corpus discusses this subject across several related pages, `
        + 'each covering a different facet of it in enough detail that the summary runs well past '
        + 'the height any reasonable collapsed slot would reserve for it.'
).join('\n\n');

let server;
let baseUrl;

function pageHtml(opts) {
    const summarize = opts.summarize !== false;
    return `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Summary CLS E2E</title>
<link rel="stylesheet" href="/scolta.css">
<style>${opts.extraCss || ''}</style></head>
<body>
<div id="scolta-search"></div>
<script>
window.scolta = {
  scoring: {
    AI_EXPAND_QUERY: true,
    AI_SUMMARIZE: ${summarize ? 'true' : 'false'},
    AUTO_LANGUAGE_FILTER: false
  },
  endpoints: {
    expand: '/api/expand',
    summarize: '/api/summarize?mode=${encodeURIComponent(opts.mode || 'short')}',
    followup: '/api/followup'
  },
  pagefindPath: '/pagefind/pagefind.js',
  wasmPath: '/wasm/scolta_core.js',
  siteName: 'E2E',
  container: '#scolta-search',
  saytEnabled: false
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

    const TYPES = {
        '.html': 'text/html; charset=utf-8',
        '.js': 'application/javascript',
        '.css': 'text/css',
        '.json': 'application/json',
        '.wasm': 'application/wasm',
        '.pagefind': 'application/octet-stream',
    };

    const sendJson = (res, status, body, delay) => {
        setTimeout(() => {
            res.writeHead(status, {
                'Content-Type': TYPES['.json'],
                'Access-Control-Allow-Origin': '*',
            });
            res.end(JSON.stringify(body));
        }, delay);
    };

    server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        const urlPath = decodeURIComponent(url.pathname);

        if (urlPath === '/' || urlPath === '/index.html') {
            const body = pageHtml({
                summarize: url.searchParams.get('summarize') !== '0',
                mode: url.searchParams.get('mode') || 'short',
                extraCss: url.searchParams.get('css') || '',
            });
            res.writeHead(200, { 'Content-Type': TYPES['.html'] });
            res.end(body);
            return;
        }

        // Expansion is a no-op that costs time: it reproduces the real gap
        // between the result paint and the summarize call without changing
        // the ranking under test.
        if (urlPath === '/api/expand') {
            sendJson(res, 200, { terms: [], sort_hint: null, subject_terms: null, filter_hint: null }, EXPAND_DELAY_MS);
            return;
        }

        if (urlPath === '/api/summarize') {
            const mode = url.searchParams.get('mode') || 'short';
            if (mode === 'error') {
                sendJson(res, 500, { error: 'stub failure' }, SUMMARIZE_DELAY_MS);
                return;
            }
            if (mode === 'empty') {
                sendJson(res, 200, {}, SUMMARIZE_DELAY_MS);
                return;
            }
            sendJson(res, 200, { summary: mode === 'long' ? LONG_SUMMARY : SHORT_SUMMARY }, SUMMARIZE_DELAY_MS);
            return;
        }

        const candidates = [];
        if (urlPath === '/scolta.js') candidates.push(path.join(REPO_ROOT, 'assets/js/scolta.js'));
        else if (urlPath === '/scolta.css') candidates.push(path.join(REPO_ROOT, 'assets/css/scolta.css'));
        else if (urlPath.startsWith('/wasm/')) candidates.push([path.join(REPO_ROOT, 'assets'), urlPath]);
        else {
            candidates.push([OUTPUT_DIR, urlPath]);
            candidates.push([CORPUS_DIR, path.basename(urlPath)]);
        }

        for (const candidate of candidates) {
            let filePath;
            if (Array.isArray(candidate)) {
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
 * Load the widget, let the page settle, then start counting layout shifts.
 * Shifts from the page load itself are deliberately excluded: what is under
 * test is the search cycle.
 */
async function openAndArm(page, query = {}) {
    const params = new URLSearchParams(query).toString();
    await page.goto(params ? `${baseUrl}/?${params}` : baseUrl);
    await page.waitForSelector('#scolta-query', { timeout: 15000 });
    await page.waitForTimeout(2500);

    await page.evaluate(() => {
        window.__cls = 0;
        window.__shifts = [];
        window.__obs = new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (entry.hadRecentInput) continue;
                window.__cls += entry.value;
                window.__shifts.push({
                    value: entry.value,
                    at: Math.round(entry.startTime),
                    // Which nodes actually moved. Without this a regression
                    // here is a bare number with no way to tell whether the
                    // summary slot or something else caused it.
                    sources: (entry.sources || []).map((s) => {
                        const n = s.node;
                        if (!n) return '(detached)';
                        return (n.id ? '#' + n.id : '')
                            + (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/).join('.') : '')
                            + (n.nodeName ? ' <' + n.nodeName.toLowerCase() + '>' : '');
                    }),
                });
            }
        });
        window.__obs.observe({ type: 'layout-shift', buffered: false });
    });
}

/** Commit a search and wait for the whole cycle (including summarize) to settle. */
async function runSearch(page, term = 'content') {
    await page.locator('#scolta-query').fill(term);
    await page.locator('#scolta-search-btn').click();
    await page.waitForSelector('#scolta-results .scolta-result-card', { timeout: 20000 });
    // Expansion + summarize round trips, plus a margin for the paint that
    // follows them and for the shift to be reported.
    await page.waitForTimeout(EXPAND_DELAY_MS + SUMMARIZE_DELAY_MS + 1500);
}

async function readCls(page) {
    return page.evaluate(() => ({ cls: window.__cls, shifts: window.__shifts }));
}

/**
 * The floor: the same search with the summary feature off. Whatever this
 * measures is the result list's own doing, not the summary slot's.
 */
async function measureDisabledFloor(page) {
    await openAndArm(page, { summarize: '0' });
    await runSearch(page);
    return (await readCls(page)).cls;
}

test('summary slot adds no layout shift: short summary', async ({ page }) => {
    const floor = await measureDisabledFloor(page);

    await openAndArm(page, { mode: 'short' });
    await runSearch(page);
    const { cls, shifts } = await readCls(page);

    const attributable = cls - floor;
    console.log(`[cls] short summary: total=${cls.toFixed(4)} floor=${floor.toFixed(4)} attributable=${attributable.toFixed(4)}`);
    console.log(`[cls] short summary shifts: ${JSON.stringify(shifts)}`);

    await expect(page.locator('#scolta-ai-summary')).toBeVisible();
    expect(attributable).toBeLessThan(0.01);
});

test('summary slot adds no layout shift: long summary', async ({ page }) => {
    const floor = await measureDisabledFloor(page);

    await openAndArm(page, { mode: 'long' });
    await runSearch(page);
    const { cls, shifts } = await readCls(page);

    const attributable = cls - floor;
    console.log(`[cls] long summary: total=${cls.toFixed(4)} floor=${floor.toFixed(4)} attributable=${attributable.toFixed(4)}`);
    console.log(`[cls] long summary shifts: ${JSON.stringify(shifts)}`);

    expect(attributable).toBeLessThan(0.01);
});

test('expanding a clamped summary costs no layout shift', async ({ page }) => {
    await openAndArm(page, { mode: 'long' });
    await runSearch(page);

    const before = (await readCls(page)).cls;

    const toggle = page.locator('[data-scolta-summary-toggle]');
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.waitForTimeout(1000);

    const after = (await readCls(page)).cls;
    console.log(`[cls] show-more click: before=${before.toFixed(4)} after=${after.toFixed(4)} added=${(after - before).toFixed(4)}`);

    // The expansion follows a click, so the shift carries hadRecentInput and
    // is excluded from the metric by definition. Nothing must leak past it.
    expect(after - before).toBeLessThan(0.01);
});

test('summary error state adds no layout shift', async ({ page }) => {
    const floor = await measureDisabledFloor(page);

    await openAndArm(page, { mode: 'error' });
    await runSearch(page);
    const { cls } = await readCls(page);

    const attributable = cls - floor;
    console.log(`[cls] error summary: total=${cls.toFixed(4)} floor=${floor.toFixed(4)} attributable=${attributable.toFixed(4)}`);
    expect(attributable).toBeLessThan(0.01);
});

test('summary disabled reserves nothing', async ({ page }) => {
    await openAndArm(page, { summarize: '0' });
    await runSearch(page);

    const box = await page.locator('#scolta-ai-summary').boundingBox();
    console.log(`[cls] disabled summary box: ${JSON.stringify(box)}`);
    // Hidden entirely: no reserved gap above the results for a deployment
    // that does not run the AI summary at all.
    await expect(page.locator('#scolta-ai-summary')).toBeHidden();
});

test('a theme can change the reserved height with one custom property', async ({ page }) => {
    await openAndArm(page, { mode: 'long' });
    await runSearch(page);
    const defaultHeight = await page
        .locator('#scolta-ai-summary .scolta-ai-summary-text')
        .evaluate((el) => el.getBoundingClientRect().height);

    await openAndArm(page, {
        mode: 'long',
        css: '#scolta-search { --scolta-summary-collapsed-lines: 12; }',
    });
    await runSearch(page);
    const overriddenHeight = await page
        .locator('#scolta-ai-summary .scolta-ai-summary-text')
        .evaluate((el) => el.getBoundingClientRect().height);

    console.log(`[cls] reserved text height: default=${defaultHeight} overridden=${overriddenHeight}`);
    expect(overriddenHeight).toBeGreaterThan(defaultHeight + 20);
});


test('an empty summary collapses the slot rather than leaving a dead box', async ({ page }) => {
    const floor = await measureDisabledFloor(page);

    await openAndArm(page, { mode: 'empty' });
    await runSearch(page);
    const { cls } = await readCls(page);

    // The one state that does not reach zero, deliberately.
    //
    // When the model returns nothing there is nothing worth reserving space
    // for, so the slot collapses to exactly what a deployment with the summary
    // disabled shows. Collapsing an already-reserved box is itself an upward
    // shift, and no user input can be credited for it, so it counts: measured
    // 0.215 here against 0.240 for the same case before the reservation
    // existed. Slightly better, still not good.
    //
    // The alternative is a labelled empty box that never collapses — zero
    // shift, permanently dead space on every summary that comes back empty.
    // Collapsing is the chosen trade. The bound below is a regression guard on
    // the measured value, not an endorsement of it.
    console.log(`[cls] empty summary: total=${cls.toFixed(4)} floor=${floor.toFixed(4)} attributable=${(cls - floor).toFixed(4)}`);
    await expect(page.locator('#scolta-ai-summary')).toBeHidden();
    expect(cls - floor).toBeLessThan(0.25);
});
