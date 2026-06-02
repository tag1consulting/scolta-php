/* tslint:disable */
/* eslint-disable */

/**
 * Extract context from multiple content items in one call.
 *
 * # Stability
 * Status: experimental
 * Since: 0.2.3
 *
 * Input: JSON string with shape:
 * ```json
 * {
 *   "items": [{ "content": "...", "url": "...", "title": "..." }],
 *   "query": "search terms",
 *   "config": { "max_length": 6000 }
 * }
 * ```
 *
 * Output: JSON string — array of `{ url, title, context }` objects.
 */
export function batch_extract_context(input: string): string;

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
 * Extract the most relevant portion of article content for LLM context.
 *
 * # Stability
 * Status: experimental
 * Since: 0.2.3
 *
 * Input: JSON string with shape:
 * ```json
 * {
 *   "content": "full article text...",
 *   "query": "search terms",
 *   "config": { "max_length": 6000, "intro_length": 2000, "snippet_radius": 500 }
 * }
 * ```
 *
 * Output: JSON string — extracted context string.
 */
export function extract_context(input: string): string;

/**
 * Get a raw prompt template by name.
 *
 * Input: Prompt name string ("expand_query", "summarize", "follow_up").
 * Output: Raw template string with {SITE_NAME} and {SITE_DESCRIPTION} placeholders.
 */
export function get_prompt(name: string): string;

/**
 * Find priority pages matching a query.
 *
 * # Stability
 * Status: experimental
 * Since: 0.2.3
 *
 * Input: JSON string with shape:
 * ```json
 * { "query": "search terms", "priority_pages": [...] }
 * ```
 *
 * Output: JSON string — array of matching priority page objects.
 */
export function match_priority_pages(input: string): string;

/**
 * Merge N scored result sets with per-set weights and deduplication.
 *
 * Input: JSON string with shape:
 * ```json
 * {
 *   "sets": [
 *     { "results": [...], "weight": 1.0 },
 *     { "results": [...], "weight": 0.7 }
 *   ],
 *   "deduplicate_by": "url",
 *   "case_sensitive": false,
 *   "exclude_urls": ["/admin"],
 *   "normalize_urls": true
 * }
 * ```
 *
 * Output: JSON string — merged, weighted, and deduplicated results array.
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
 * 2. **JSON object** — full configuration including language, generic-term filtering,
 *    and merging with an existing term set.
 *    ```json
 *    {
 *      "text": "[\"term1\", \"term2\"]",
 *      "language": "en",
 *      "generic_terms": ["platform", "solution"],
 *      "existing_terms": ["drupal"]
 *    }
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
 * Redact PII from a query string before analytics logging.
 *
 * # Stability
 * Status: experimental
 * Since: 0.2.3
 *
 * Input: JSON string with shape:
 * ```json
 * {
 *   "query": "contact user@example.com",
 *   "config": { "redact_email": true, "redact_phone": true }
 * }
 * ```
 *
 * Output: JSON string — sanitized query string.
 */
export function sanitize_query(input: string): string;

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
 * Trim conversation history to fit within a character limit.
 *
 * # Stability
 * Status: experimental
 * Since: 0.2.3
 *
 * Input: JSON string with shape:
 * ```json
 * {
 *   "messages": [{ "role": "user", "content": "..." }],
 *   "config": { "max_length": 12000, "preserve_first_n": 2, "removal_unit": 2 }
 * }
 * ```
 *
 * Output: JSON string — trimmed messages array.
 */
export function truncate_conversation(input: string): string;

/**
 * Return the scolta-core version string.
 */
export function version(): string;

export type InitInput = RequestInfo | URL | Response | BufferSource | WebAssembly.Module;

export interface InitOutput {
    readonly memory: WebAssembly.Memory;
    readonly batch_extract_context: (a: number, b: number, c: number) => void;
    readonly batch_score_results: (a: number, b: number, c: number) => void;
    readonly describe: (a: number) => void;
    readonly extract_context: (a: number, b: number, c: number) => void;
    readonly get_prompt: (a: number, b: number, c: number) => void;
    readonly match_priority_pages: (a: number, b: number, c: number) => void;
    readonly merge_results: (a: number, b: number, c: number) => void;
    readonly parse_expansion: (a: number, b: number, c: number) => void;
    readonly resolve_prompt: (a: number, b: number, c: number) => void;
    readonly sanitize_query: (a: number, b: number, c: number) => void;
    readonly score_results: (a: number, b: number, c: number) => void;
    readonly truncate_conversation: (a: number, b: number, c: number) => void;
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
