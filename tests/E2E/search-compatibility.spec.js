// @ts-check
/**
 * End-to-end test: prove pagefind.js can search a PHP-generated index.
 *
 * Builds an index with the PHP indexer (same 25-page corpus), serves it
 * via HTTP, loads pagefind.js (ES module) in a headless browser, and runs
 * search queries. If search returns correct results, the PHP index format
 * is proven compatible with pagefind.js.
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const OUTPUT_DIR = path.join(__dirname, '../../.e2e-output');

let server;
let baseUrl;

test.beforeAll(async () => {
    if (fs.existsSync(OUTPUT_DIR)) {
        fs.rmSync(OUTPUT_DIR, { recursive: true, force: true });
    }

    const buildScript = path.join(__dirname, 'build-php-index.php');
    execSync(`php ${buildScript} ${OUTPUT_DIR}`, {
        cwd: path.join(__dirname, '../..'),
        stdio: 'pipe',
    });

    const pagefindDir = path.join(OUTPUT_DIR, 'pagefind');
    if (!fs.existsSync(path.join(pagefindDir, 'pagefind-entry.json'))) {
        throw new Error('pagefind-entry.json not found after build');
    }

    // pagefind.js is an ES module. It uses import.meta.url to find its
    // base path, then loads entry.json, wasm, and index chunks relative
    // to that path. The HTML page must use <script type="module">.
    const html = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>E2E</title></head>
<body>
<script type="module">
import * as pagefind from '/pagefind.js';
window.__pf = pagefind;
window.__pfReady = pagefind.search('test').then(() => true).catch(e => e.message);
</script>
</body></html>`;
    fs.writeFileSync(path.join(pagefindDir, 'index.html'), html);

    server = http.createServer((req, res) => {
        const urlPath = new URL(req.url, 'http://localhost').pathname;
        let filePath = path.join(pagefindDir, decodeURIComponent(urlPath === '/' ? '/index.html' : urlPath));
        if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
            const ext = path.extname(filePath);
            const types = {
                '.html': 'text/html; charset=utf-8',
                '.js': 'application/javascript',
                '.json': 'application/json',
                '.pagefind': 'application/octet-stream',
            };
            res.writeHead(200, {
                'Content-Type': types[ext] || 'application/octet-stream',
                'Access-Control-Allow-Origin': '*',
            });
            res.end(fs.readFileSync(filePath));
        } else {
            res.writeHead(404);
            res.end('Not found: ' + req.url);
        }
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

async function searchWith(page, query) {
    await page.goto(baseUrl);

    // Wait for pagefind to initialize (the initial search('test') in the module).
    await page.waitForFunction(
        () => window.__pfReady !== undefined,
        {},
        { timeout: 15000 }
    );

    const results = await page.evaluate(async (q) => {
        try {
            const res = await window.__pf.search(q);
            const loaded = await Promise.all(
                res.results.slice(0, 5).map((r) => r.data())
            );
            return {
                totalCount: res.results.length,
                results: loaded.map((d) => ({
                    url: d.url,
                    title: d.meta?.title || '',
                })),
                error: null,
            };
        } catch (e) {
            return { totalCount: 0, results: [], error: e.message };
        }
    }, query);

    return results;
}

const SEARCH_TESTS = [
    { query: 'search', minResults: 1, desc: 'common word across pages' },
    { query: 'algorithm', minResults: 1, desc: 'technical term' },
    { query: 'install', minResults: 1, desc: 'installation page' },
    { query: 'running', minResults: 1, desc: 'stemmed word' },
    { query: 'culture', minResults: 1, desc: 'diacritics page' },
];

for (const { query, minResults, desc } of SEARCH_TESTS) {
    test(`search "${query}" returns results (${desc})`, async ({ page }) => {
        const results = await searchWith(page, query);
        expect(results.error).toBeNull();
        expect(results.totalCount).toBeGreaterThanOrEqual(minResults);
        for (const r of results.results) {
            expect(r.url).toBeTruthy();
            expect(r.title).toBeTruthy();
        }
    });
}

test('nonsense query returns zero results', async ({ page }) => {
    const results = await searchWith(page, 'xyzzy12345nonsenseword');
    expect(results.error).toBeNull();
    expect(results.totalCount).toBe(0);
});
