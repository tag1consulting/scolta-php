// @ts-check
/**
 * End-to-end test: search as you type against a real, locally built index.
 *
 * The Jest suites drive scolta.js in JSDOM against a Pagefind mock, which is
 * the right tool for the staleness, escaping and budget logic but cannot prove
 * the feature works against a genuine Pagefind index in a genuine browser.
 * This does: it builds an index with the PHP indexer, serves it alongside the
 * real bundle and stylesheet, and drives the widget with keyboard and mouse.
 *
 * What it pins:
 *   - typing populates the dropdown while the results region stays untouched;
 *   - arrows plus Enter act on a suggestion in BOTH saytSuggestionAction modes;
 *   - Escape closes the dropdown without clearing the input;
 *   - Enter with no active option still runs the full search.
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const REPO_ROOT = path.join(__dirname, '../..');
const OUTPUT_DIR = path.join(REPO_ROOT, '.e2e-output-sayt');
const CORPUS_DIR = path.join(REPO_ROOT, 'tests/fixtures/concordance/corpus');

let server;
let baseUrl;

function page_html(action) {
    return `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>SAYT E2E</title>
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
  saytEnabled: true,
  saytDebounceMs: 50,
  saytExpand: false,
  saytRecentSearches: false,
  saytSuggestionAction: ${JSON.stringify(action)}
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

    const TYPES = {
        '.html': 'text/html; charset=utf-8',
        '.js': 'application/javascript',
        '.css': 'text/css',
        '.json': 'application/json',
        '.wasm': 'application/wasm',
        '.pagefind': 'application/octet-stream',
    };

    // Serve the built index, the real bundle and stylesheet, the browser WASM,
    // and the corpus pages themselves so a suggestion link actually lands.
    server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        const urlPath = decodeURIComponent(url.pathname);

        if (urlPath === '/' || urlPath === '/index.html') {
            const body = page_html(url.searchParams.get('action') || 'navigate');
            res.writeHead(200, { 'Content-Type': TYPES['.html'] });
            res.end(body);
            return;
        }

        const candidates = [];
        if (urlPath === '/scolta.js') candidates.push(path.join(REPO_ROOT, 'assets/js/scolta.js'));
        else if (urlPath === '/scolta.css') candidates.push(path.join(REPO_ROOT, 'assets/css/scolta.css'));
        else if (urlPath.startsWith('/wasm/')) candidates.push(path.join(REPO_ROOT, 'assets', urlPath));
        else {
            candidates.push(path.join(OUTPUT_DIR, urlPath));
            candidates.push(path.join(CORPUS_DIR, path.basename(urlPath)));
        }

        for (const filePath of candidates) {
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

/** Load the widget and wait for Pagefind to finish initialising. */
async function openWidget(page, action = 'navigate') {
    await page.goto(action === 'navigate' ? baseUrl : `${baseUrl}/?action=${action}`);
    await page.waitForSelector('#scolta-query', { timeout: 15000 });
    // initPagefind() ends with a warm-up search; the search button becoming
    // usable is not observable, so wait for the module to be in hand by
    // driving one suggest cycle and letting it settle.
    await page.waitForFunction(
        () => !!document.querySelector('#scolta-sayt'),
        {},
        { timeout: 15000 }
    );
    await page.waitForTimeout(2000);
}

async function typeQuery(page, text) {
    const input = page.locator('#scolta-query');
    await input.click();
    await input.fill('');
    await input.type(text, { delay: 30 });
}

test('typing populates the dropdown and leaves the results region untouched', async ({ page }) => {
    await openWidget(page);
    await typeQuery(page, 'search');

    const options = page.locator('#scolta-sayt [role="option"]');
    await expect(options.first()).toBeVisible({ timeout: 10000 });
    expect(await options.count()).toBeGreaterThan(0);

    // ARIA combobox wiring is live.
    await expect(page.locator('#scolta-query')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#scolta-query')).toHaveAttribute('role', 'combobox');
    await expect(page.locator('#scolta-sayt')).toHaveAttribute('role', 'listbox');

    // Nothing in the results region moved: no search has been committed.
    expect(await page.locator('#scolta-results').innerHTML()).toBe('');
    expect(await page.locator('#scolta-results-header').innerHTML()).toBe('');
    await expect(page.locator('#scolta-layout')).toBeHidden();
});

test('arrows track the active option through aria-activedescendant', async ({ page }) => {
    await openWidget(page);
    await typeQuery(page, 'search');
    await expect(page.locator('#scolta-sayt [role="option"]').first()).toBeVisible({ timeout: 10000 });

    await page.keyboard.press('ArrowDown');
    const firstId = await page.locator('#scolta-sayt [role="option"]').first().getAttribute('id');
    await expect(page.locator('#scolta-query')).toHaveAttribute('aria-activedescendant', firstId);
    await expect(page.locator('#scolta-sayt [role="option"]').first())
        .toHaveAttribute('aria-selected', 'true');

    // Focus stays on the input throughout.
    expect(await page.evaluate(() => document.activeElement.id)).toBe('scolta-query');
});

test('navigate mode: ArrowDown then Enter follows the suggestion link', async ({ page }) => {
    await openWidget(page, 'navigate');
    await typeQuery(page, 'search');

    const first = page.locator('#scolta-sayt [role="option"]').first();
    await expect(first).toBeVisible({ timeout: 10000 });
    expect(await first.evaluate(el => el.tagName)).toBe('A');
    const href = await first.getAttribute('href');
    expect(href).toMatch(/\.html$/);

    await page.keyboard.press('ArrowDown');
    await Promise.all([
        page.waitForURL(url => url.pathname.endsWith('.html'), { timeout: 10000 }),
        page.keyboard.press('Enter'),
    ]);

    expect(new URL(page.url()).pathname).toBe(href);
});

test('search mode: ArrowDown then Enter runs the full search for that title', async ({ page }) => {
    await openWidget(page, 'search');
    await typeQuery(page, 'search');

    const first = page.locator('#scolta-sayt [role="option"]').first();
    await expect(first).toBeVisible({ timeout: 10000 });
    expect(await first.evaluate(el => el.tagName)).toBe('DIV');
    const title = (await first.locator('.scolta-sayt-title').innerText()).trim();

    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');

    await expect(page.locator('#scolta-layout')).toBeVisible({ timeout: 10000 });
    // The header pluralizes, so match the quoted query rather than the noun.
    await expect(page.locator('#scolta-results-header')).toContainText(`for "${title}"`);
    expect(await page.locator('#scolta-query').inputValue()).toBe(title);
    await expect(page.locator('#scolta-sayt')).toBeHidden();
});

test('Escape closes the dropdown without clearing the input', async ({ page }) => {
    await openWidget(page);
    await typeQuery(page, 'search');
    await expect(page.locator('#scolta-sayt [role="option"]').first()).toBeVisible({ timeout: 10000 });

    await page.keyboard.press('Escape');

    await expect(page.locator('#scolta-sayt')).toBeHidden();
    expect(await page.locator('#scolta-query').inputValue()).toBe('search');
    await expect(page.locator('#scolta-query')).toHaveAttribute('aria-expanded', 'false');
});

test('Enter with no active option still runs the typed query', async ({ page }) => {
    await openWidget(page);
    await typeQuery(page, 'search');
    await expect(page.locator('#scolta-sayt [role="option"]').first()).toBeVisible({ timeout: 10000 });

    await page.keyboard.press('Enter');

    await expect(page.locator('#scolta-layout')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#scolta-results-header')).toContainText('results for "search"');
    await expect(page.locator('#scolta-sayt')).toBeHidden();
});
