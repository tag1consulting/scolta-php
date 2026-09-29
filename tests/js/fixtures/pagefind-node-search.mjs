/**
 * Search a built index with the pagefind.js and WebAssembly files it ships,
 * in Node, with fetch served from the index directory.
 *
 * Usage: node pagefind-node-search.mjs <pagefind dir> <term> [<term> ...]
 * Prints a JSON map of term => sorted result URLs.
 */

import { readFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';

const [dir, ...terms] = process.argv.slice(2);
if (!dir || terms.length === 0) {
    console.error('Usage: node pagefind-node-search.mjs <pagefind dir> <term> [<term> ...]');
    process.exit(2);
}

const base = pathToFileURL(dir.endsWith('/') ? dir : dir + '/');

// Node's fetch does not serve file: URLs; pagefind.js only needs the body.
globalThis.fetch = async (input) => {
    const url = new URL(String(input), base);
    const body = await readFile(fileURLToPath(url));
    return new Response(body, { status: 200 });
};

const pagefind = await import(new URL('pagefind.js', base).href);
await pagefind.options({ basePath: base.href, baseUrl: '/' });
await pagefind.init();

const out = {};
for (const term of terms) {
    const search = await pagefind.search(term);
    const data = await Promise.all(search.results.map((r) => r.data()));
    out[term] = data.map((d) => d.url).sort();
}
console.log(JSON.stringify(out));
