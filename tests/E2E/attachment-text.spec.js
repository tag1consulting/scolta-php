// @ts-check
/**
 * End-to-end test: stock pagefind.js decodes the attachment weight bucket.
 *
 * The PHP round-trip test proves the encoder and decoder in this package agree
 * with each other, which is not the property that matters — Pagefind's own Rust
 * decoder reads these bytes in production. This spec drops the PHP decoder
 * entirely: it builds a real index, serves the real stock bundle to a real
 * browser, and searches for a word that exists only in attachment text. A hit
 * proves the multi-bucket position format survives the decoder that counts.
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const OUTPUT_DIR = path.join(__dirname, '../../.e2e-attachment-output');

let server;
let baseUrl;

test.beforeAll(async () => {
    if (fs.existsSync(OUTPUT_DIR)) {
        fs.rmSync(OUTPUT_DIR, { recursive: true, force: true });
    }

    const buildScript = path.join(__dirname, 'build-attachment-index.php');
    execSync(`php ${buildScript} ${OUTPUT_DIR}`, {
        cwd: path.join(__dirname, '../..'),
        stdio: 'pipe',
    });

    const pagefindDir = path.join(OUTPUT_DIR, 'pagefind');
    if (!fs.existsSync(path.join(pagefindDir, 'pagefind-entry.json'))) {
        throw new Error('pagefind-entry.json not found after build');
    }

    const html = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>E2E attachment</title></head>
<body>
<script type="module">
import * as pagefind from '/pagefind.js';
window.__pf = pagefind;
window.__pfReady = pagefind.search('petals').then(() => true).catch(e => e.message);
</script>
</body></html>`;
    fs.writeFileSync(path.join(pagefindDir, 'index.html'), html);

    server = http.createServer((req, res) => {
        const urlPath = new URL(req.url, 'http://localhost').pathname;
        const filePath = path.join(pagefindDir, decodeURIComponent(urlPath === '/' ? '/index.html' : urlPath));
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
    await page.waitForFunction(() => window.__pfReady !== undefined, {}, { timeout: 15000 });

    return page.evaluate(async (q) => {
        try {
            const res = await window.__pf.search(q);
            const loaded = await Promise.all(res.results.slice(0, 5).map((r) => r.data()));
            return {
                totalCount: res.results.length,
                results: loaded.map((d) => ({
                    url: d.url,
                    excerpt: d.excerpt || '',
                    weights: (d.weighted_locations || []).map((l) => l.weight),
                })),
                error: null,
            };
        } catch (e) {
            return { totalCount: 0, results: [], error: e.message };
        }
    }, query);
}

test('a word found only in attachment text is searchable', async ({ page }) => {
    const results = await searchWith(page, 'zygomorphic');

    expect(results.error).toBeNull();
    expect(results.totalCount).toBe(1);
    expect(results.results[0].url).toContain('lesson-plants');
});

test('an attachment match excerpts from the attachment text', async ({ page }) => {
    const results = await searchWith(page, 'zygomorphic');

    expect(results.error).toBeNull();
    // Proves the position landed inside the fragment content rather than past
    // its end: Pagefind slices the excerpt out of content by word position, so
    // a misaligned position yields the wrong words or none.
    expect(results.results[0].excerpt.toLowerCase()).toContain('zygomorphic');
});

test('attachment matches carry a lighter weight than body matches', async ({ page }) => {
    const attachment = await searchWith(page, 'zygomorphic');
    const body = await searchWith(page, 'petals');

    expect(attachment.error).toBeNull();
    expect(body.error).toBeNull();

    const attachmentWeight = Math.max(...attachment.results[0].weights);
    const bodyWeight = Math.max(...body.results[0].weights);

    expect(bodyWeight).toBeCloseTo(1.0, 5);
    expect(attachmentWeight).toBeLessThan(bodyWeight);
});

test('body search is unaffected by the presence of attachment text', async ({ page }) => {
    const results = await searchWith(page, 'sediment');

    expect(results.error).toBeNull();
    expect(results.totalCount).toBe(1);
    expect(results.results[0].url).toContain('lesson-rocks');
});
