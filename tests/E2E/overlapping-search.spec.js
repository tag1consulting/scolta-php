// @ts-check
/**
 * End-to-end test: two searches committed in quick succession paint the
 * second one's results, never the first's.
 *
 * Each doSearch() cycle awaits Pagefind and the fragment loads before it
 * paints. When the user commits a second query while the first is still
 * loading, the second can finish first (its fragments are already cached),
 * and the first then resolves last. This pins that the late, superseded
 * cycle neither repaints the list nor replaces the stored results.
 *
 * The first query is made the slow one by holding back every fragment
 * request while it is in flight; the second query's fragments were loaded by
 * an earlier search, so Pagefind serves them from its own cache.
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const REPO_ROOT = path.join(__dirname, '../..');
const OUTPUT_DIR = path.join(REPO_ROOT, '.e2e-output-overlap');
const CORPUS_DIR = path.join(REPO_ROOT, 'tests/fixtures/concordance/corpus');

let server;
let baseUrl;

const PAGE_HTML = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Overlap E2E</title></head>
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
  saytEnabled: false
};
</script>
<script src="/scolta.js"></script>
</body></html>`;

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
        '.json': 'application/json',
        '.wasm': 'application/wasm',
        '.pagefind': 'application/octet-stream',
    };

    server = http.createServer((req, res) => {
        const urlPath = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
        if (urlPath === '/' || urlPath === '/index.html') {
            res.writeHead(200, { 'Content-Type': TYPES['.html'] });
            res.end(PAGE_HTML);
            return;
        }
        let root, rel;
        if (urlPath === '/scolta.js') [root, rel] = [path.join(REPO_ROOT, 'assets/js'), 'scolta.js'];
        else if (urlPath.startsWith('/wasm/')) [root, rel] = [path.join(REPO_ROOT, 'assets'), urlPath];
        else [root, rel] = [OUTPUT_DIR, urlPath];
        // Resolve, then confirm the result is still inside its root.
        const filePath = path.resolve(root, '.' + path.posix.normalize('/' + rel));
        if ((filePath === root || filePath.startsWith(root + path.sep))
            && fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
            res.writeHead(200, { 'Content-Type': TYPES[path.extname(filePath)] || 'application/octet-stream' });
            res.end(fs.readFileSync(filePath));
            return;
        }
        res.writeHead(404);
        res.end('Not found');
    });
    await new Promise((resolve) => server.listen(0, () => {
        baseUrl = `http://localhost:${server.address().port}`;
        resolve();
    }));
});

test.afterAll(async () => {
    if (server) server.close();
    if (fs.existsSync(OUTPUT_DIR)) fs.rmSync(OUTPUT_DIR, { recursive: true, force: true });
});

async function commit(page, query) {
    await page.fill('#scolta-query', query);
    await page.press('#scolta-query', 'Enter');
}

async function resultTitles(page) {
    return page.locator('#scolta-results .scolta-result-title').allInnerTexts();
}

test('a superseded search that resolves last does not repaint its results', async ({ page }) => {
    await page.goto(baseUrl);
    await page.waitForSelector('#scolta-query', { timeout: 15000 });

    // Search the second query once, settled, so its fragments are cached.
    await commit(page, 'approximately');
    await expect(page.locator('#scolta-results-header')).toContainText('"approximately"', { timeout: 15000 });
    const expected = await resultTitles(page);
    expect(expected).toContain('Numbers and Ranges');
    expect(expected).toEqual(['Numbers and Ranges']);

    // Hold back every fragment fetch, so the first query's cycle is the slow one.
    let released = false;
    let held = 0;
    await page.route(/\/fragment\//, async (route) => {
        held++;
        while (!released) await new Promise((r) => setTimeout(r, 50));
        await route.continue();
    });

    await commit(page, 'content');
    await commit(page, 'approximately');

    // The second query paints from cache while the first is still held.
    await expect(page.locator('#scolta-results-header')).toContainText('"approximately"', { timeout: 15000 });
    expect(await resultTitles(page)).toEqual(expected);
    expect(held).toBeGreaterThan(0);

    // Let the superseded cycle finish, and give it time to paint if it would.
    released = true;
    await page.waitForTimeout(1500);

    await expect(page.locator('#scolta-results-header')).toContainText('"approximately"');
    expect(await resultTitles(page)).toEqual(expected);
    expect(await page.inputValue('#scolta-query')).toBe('approximately');
});
