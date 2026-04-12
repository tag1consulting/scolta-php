const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const OUTPUT_DIR = path.join(__dirname, '../../.e2e-output');
let server, baseUrl;

test.beforeAll(async () => {
    if (!fs.existsSync(OUTPUT_DIR + '/pagefind/pagefind-entry.json')) {
        execSync(`php tests/E2E/build-php-index.php ${OUTPUT_DIR}`, { cwd: path.join(__dirname, '../..'), stdio: 'pipe' });
    }

    const pagefindDir = path.join(OUTPUT_DIR, 'pagefind');
    const html = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Debug</title></head>
<body>
<script type="module">
import * as pagefind from '/pagefind.js';
try {
    const res = await pagefind.search('test');
    document.title = 'OK:' + res.results.length;
} catch(e) {
    document.title = 'ERR:' + e.message;
}
</script>
</body></html>`;
    fs.writeFileSync(path.join(pagefindDir, 'debug.html'), html);

    server = http.createServer((req, res) => {
        const urlPath = new URL(req.url, 'http://localhost').pathname;
        let f = path.join(pagefindDir, decodeURIComponent(urlPath === '/' ? '/debug.html' : urlPath));
        console.log('REQ:', req.url, '->', fs.existsSync(f) ? 'OK' : '404');
        if (fs.existsSync(f) && fs.statSync(f).isFile()) {
            const types = {'.html':'text/html;charset=utf-8','.js':'application/javascript','.json':'application/json','.pagefind':'application/octet-stream'};
            res.writeHead(200, {'Content-Type': types[path.extname(f)] || 'application/octet-stream'});
            res.end(fs.readFileSync(f));
        } else {
            console.log('SERVER 404:', req.url);
            res.writeHead(404);
            res.end('');
        }
    });
    await new Promise(r => server.listen(0, () => { baseUrl = 'http://localhost:' + server.address().port; r(); }));
});

test.afterAll(async () => { if (server) server.close(); });

test('debug pagefind loading', async ({ page }) => {
    page.on('console', msg => console.log('BROWSER:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));
    page.on('requestfailed', req => console.log('REQUEST FAILED:', req.url(), req.failure()?.errorText));

    await page.goto(baseUrl);
    await page.waitForFunction(() => document.title.startsWith('OK:') || document.title.startsWith('ERR:'), {}, { timeout: 15000 });
    const title = await page.title();
    console.log('RESULT:', title);
    expect(title).toMatch(/^OK:/);
});
