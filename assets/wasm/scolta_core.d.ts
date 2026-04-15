/* tslint:disable */
/* eslint-disable */

/**
 * Score multiple queries against their respective result sets in a single call.
 *
 * Input: JSON string with shape:
 * ```json
 * {
 *   "queries": [
 *     { "query": "search terms", "results": [...], "config": {...} },
 *     { "query": "other query",  "results": [...] }
 *   ],
 *   "default_config": { "language": "en" }
 * }
 * ```
 *
 * Per-query `"config"` overrides `"default_config"` for that entry.
 *
 * Output: JSON string — array of arrays of scored results, one inner array
 * per input query, in the same order.
 */
export function batch_score_results(input: string): string;

/**
 * Return a JSON description of all available functions.
 */
export function describe(): string;

/**
 * Get a raw prompt template by name.
 *
 * Input: Prompt name string ("expand_query", "summarize", "follow_up").
 * Output: Raw template string with {SITE_NAME} and {SITE_DESCRIPTION} placeholders.
 */
export function get_prompt(name: string): string;

/**
 * Merge original and expanded search results.
 *
 * Input: JSON string with shape:
 *   `{ "original": [...], "expanded": [...], "config": {...} }`
 *
 * Output: JSON string — merged and deduplicated results.
 */
export function merge_results(input: string): string;

/**
 * Parse an LLM expansion response into individual search terms.
 *
 * Accepts two input forms:
 *
 * 1. **Bare string** — treated as the raw LLM response; language defaults to `"en"`.
 *    ```text
 *    ["term1", "term2"]
 *    ```
 *
 * 2. **JSON object** — allows specifying a language for stop word filtering.
 *    ```json
 *    { "text": "[\"term1\", \"term2\"]", "language": "de" }
 *    ```
 *
 * Output: JSON string — array of extracted, filtered terms.
 */
export function parse_expansion(input: string): string;

/**
 * Resolve a prompt template with variable substitution.
 *
 * Input: JSON string with shape:
 *   `{ "prompt_name": "expand_query", "site_name": "...", "site_description": "..." }`
 *
 * Output: The resolved prompt string.
 */
export function resolve_prompt(input: string): string;

/**
 * Score search results against a query.
 *
 * Input: JSON string with shape:
 *   `{ "query": "search terms", "results": [...], "config": {...} }`
 *
 * Output: JSON string — array of scored results, sorted descending.
 */
export function score_results(input: string): string;

/**
 * Convert scoring config to JavaScript-friendly format.
 *
 * Input: JSON string of scoring config.
 * Output: JSON string with JS-style keys (UPPER_SNAKE_CASE).
 */
export function to_js_scoring_config(input: string): string;

/**
 * Return the scolta-core version string.
 */
export function version(): string;

export type InitInput = RequestInfo | URL | Response | BufferSource | WebAssembly.Module;

export interface InitOutput {
    readonly memory: WebAssembly.Memory;
    readonly batch_score_results: (a: number, b: number, c: number) => void;
    readonly describe: (a: number) => void;
    readonly get_prompt: (a: number, b: number, c: number) => void;
    readonly merge_results: (a: number, b: number, c: number) => void;
    readonly parse_expansion: (a: number, b: number, c: number) => void;
    readonly resolve_prompt: (a: number, b: number, c: number) => void;
    readonly score_results: (a: number, b: number, c: number) => void;
    readonly to_js_scoring_config: (a: number, b: number, c: number) => void;
    readonly version: (a: number) => void;
    readonly __wbindgen_add_to_stack_pointer: (a: number) => number;
    readonly __wbindgen_export: (a: number, b: number) => number;
    readonly __wbindgen_export2: (a: number, b: number, c: number, d: number) => number;
    readonly __wbindgen_export3: (a: number, b: number, c: number) => void;
}

export type SyncInitInput = BufferSource | WebAssembly.Module;

/**
 * Instantiates the given `module`, which can either be bytes or
 * a precompiled `WebAssembly.Module`.
 *
 * @param {{ module: SyncInitInput }} module - Passing `SyncInitInput` directly is deprecated.
 *
 * @returns {InitOutput}
 */
export function initSync(module: { module: SyncInitInput } | SyncInitInput): InitOutput;

/**
 * If `module_or_path` is {RequestInfo} or {URL}, makes a request and
 * for everything else, calls `WebAssembly.instantiate` directly.
 *
 * @param {{ module_or_path: InitInput | Promise<InitInput> }} module_or_path - Passing `InitInput` directly is deprecated.
 *
 * @returns {Promise<InitOutput>}
 */
export default function __wbg_init (module_or_path?: { module_or_path: InitInput | Promise<InitInput> } | InitInput | Promise<InitInput>): Promise<InitOutput>;
