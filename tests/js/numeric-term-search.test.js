/**
 * Terms that start with a digit, searched with Pagefind's own runtime.
 *
 * Builds the corpus from tests/Index/NumericTermOrderTest.php with the PHP
 * indexer, then loads the pagefind.js and WebAssembly the build emits in a
 * Node child process and searches it. Pagefind picks a chunk by comparing the
 * query with each chunk's range as strings, so a term the indexer ordered any
 * other way is missing pages here even when it is present in the index.
 */

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const BUILD = path.join(__dirname, 'fixtures', 'build-numeric-index.php');
const SEARCH = path.join(__dirname, 'fixtures', 'pagefind-node-search.mjs');

// "1" is left out: Pagefind prefix-matches it against every other digit term.
const TERMS = ['9', '10', '10th', '01', '0001', '1812', '1800s', '2nd', '11mm', '2021'];

describe('digit-leading terms in a PHP-built index', () => {
    let outDir;
    let expected;
    let found;

    beforeAll(() => {
        outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'scolta-numeric-search-'));
        expected = JSON.parse(execFileSync('php', [BUILD, outDir], { encoding: 'utf8' }));
        found = JSON.parse(execFileSync(
            process.execPath,
            [SEARCH, path.join(outDir, 'pagefind'), ...TERMS, 'war of 1812'],
            { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] },
        ));
    }, 60000);

    afterAll(() => {
        if (outDir) {
            fs.rmSync(outDir, { recursive: true, force: true });
        }
    });

    test.each(TERMS)('a search for %s returns every page that contains it', (term) => {
        // Contains, not equals: Pagefind also prefix-matches, so "10" finds "10th".
        expect(found[term]).toEqual(expect.arrayContaining(expected[term]));
    });

    test('a search for "war of 1812" returns every page that says it', () => {
        expect(found['war of 1812']).toEqual([...expected['1812']].sort());
    });
});
