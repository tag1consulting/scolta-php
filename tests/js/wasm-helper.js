'use strict';
/**
 * Synchronous WASM loader for Jest integration tests.
 *
 * scolta_core.js is an ES module (wasm-pack --target web). This helper
 * transforms it into evaluatable CommonJS-compatible code, then calls
 * initSync() with the binary buffer so tests can call score_results() etc.
 * synchronously without any dynamic import() or fetch().
 *
 * This is the production WASM binary — not a mock. Tests that load it are
 * exercising the actual JS→WASM boundary, not just the Rust scorer in isolation.
 */

const path = require('path');
const fs = require('fs');
const { pathToFileURL } = require('url');

const WASM_GLUE = path.resolve(__dirname, '../../assets/wasm/scolta_core.js');
const WASM_BIN = path.resolve(__dirname, '../../assets/wasm/scolta_core_bg.wasm');

function buildModule() {
    let source = fs.readFileSync(WASM_GLUE, 'utf-8');

    // Replace import.meta.url (the only import.meta usage) with the actual
    // file URL. The glue only uses it as a default base for the .wasm path;
    // we use initSync with a buffer so this code path never runs.
    const fileUrl = pathToFileURL(WASM_GLUE).href;
    source = source.replace(/import\.meta\.url/g, JSON.stringify(fileUrl));

    // Strip the 'export' keyword from every named export declaration so the
    // functions become plain local declarations visible within the factory body.
    source = source.replace(/^export function /gm, 'function ');
    source = source.replace(/^export async function /gm, 'async function ');

    // Remove the final re-export statement (the only remaining `export` line).
    source = source.replace(/^export\s*\{[^}]*\};\s*$/m, '');

    // Collect named exports at the end of the factory body and return them.
    const tail = `
        return {
            score_results: score_results,
            batch_score_results: batch_score_results,
            batch_extract_context: batch_extract_context,
            merge_results: merge_results,
            version: version,
            initSync: initSync,
            init: __wbg_init,
        };
    `;

    // new Function() gives us a clean global scope with access to Node globals
    // (WebAssembly, TextEncoder, TextDecoder, URL, Date) without polluting the
    // outer module scope or triggering Jest's module system for the ESM source.
    return new Function(source + tail)(); // eslint-disable-line no-new-func
}

let _instance = null;

/**
 * Return the initialized WASM module, loading it on first call.
 *
 * @returns {{ score_results: function, batch_score_results: function, version: function }}
 */
function getWasm() {
    if (_instance) return _instance;
    const mod = buildModule();
    const wasmBuffer = fs.readFileSync(WASM_BIN);
    mod.initSync({ module: wasmBuffer });
    _instance = mod;
    return mod;
}

module.exports = { getWasm };
