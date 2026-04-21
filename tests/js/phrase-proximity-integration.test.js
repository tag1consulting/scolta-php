'use strict';
/**
 * Integration tests for phrase-proximity scoring across the JS→WASM boundary.
 *
 * These tests load the production WASM binary (scolta_core_bg.wasm) and call
 * score_results() exactly as scoreResults() in scolta.js does: JSON in, JSON
 * out, locations array carried through serde into the Rust scorer.
 *
 * What this catches that the Rust unit tests cannot:
 *   - A stale WASM binary whose SearchResult struct lacks a `locations` field
 *     would silently drop the array into `extra` and return no phrase bonus.
 *   - A serde rename or field removal would fail here before reaching users.
 *   - Any miscasting (e.g., locations sent as strings instead of integers)
 *     would produce no phrase bonus and fail the ranking assertion.
 *
 * Fixtures mirror Rust tests in scoring.rs to ensure parity.
 */

const { getWasm } = require('./wasm-helper');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a minimal SearchResult JSON object.
 * `date` is left empty so recency boost is identical for all results and does
 * not affect the ranking assertions.
 */
function makeResult(url, title, excerpt, score, locations) {
    return {
        url,
        title,
        excerpt,
        date: '',
        score,
        locations: locations !== undefined ? locations : null,
    };
}

/**
 * Call score_results on the WASM module and return the sorted output array.
 */
function scoreViaWasm(query, results, config) {
    const wasm = getWasm();
    const input = JSON.stringify({ query, results, config: config || {} });
    const output = wasm.score_results(input);
    return JSON.parse(output);
}

// ---------------------------------------------------------------------------
// Test 1 — Adjacent phrase wins over single-term title match
//
// Mirrors Rust: test_phrase_adjacent_ranks_above_single_term_title
//
// r1 has "hello" in the title (title boost) but only one location.
// r2 has "hello world" adjacent in the excerpt (locations [0,1]) with no
// title match. The phrase-adjacent multiplier (2.5×) must push r2 above r1.
// ---------------------------------------------------------------------------
describe('phrase-proximity integration (WASM boundary)', () => {

    test('adjacent phrase in body ranks above single-term title match', () => {
        const r1 = makeResult(
            'https://example.com/1',
            'Hello Integrations',
            'Some content about modules',
            1.0,
            [0],        // only "hello" matched, at word position 0
        );
        const r2 = makeResult(
            'https://example.com/2',
            'Module Integration Guide',
            'hello world module documentation',
            1.0,
            [0, 1],     // "hello" at 0, "world" at 1 — adjacent phrase
        );

        const scored = scoreViaWasm('hello world', [r1, r2]);

        expect(scored.length).toBe(2);
        expect(scored[0].url).toBe('https://example.com/2');
    });

    // -------------------------------------------------------------------------
    // Test 2 — Near phrase (within window) beats scattered terms
    //
    // Mirrors Rust: test_phrase_near_ranks_above_scattered
    //
    // r1: positions [0, 50] — span 50 exceeds phrase_near_window (5), no bonus.
    // r2: positions [0, 4]  — span 4 ≤ phrase_near_window, near multiplier fires.
    // -------------------------------------------------------------------------
    test('near phrase beats scattered terms', () => {
        const r1 = makeResult(
            'https://example.com/scattered',
            'Title',
            'hello many words world',
            1.0,
            [0, 50],
        );
        const r2 = makeResult(
            'https://example.com/near',
            'Title',
            'hello quick world',
            1.0,
            [0, 4],
        );

        const scored = scoreViaWasm('hello world', [r1, r2]);

        expect(scored.length).toBe(2);
        expect(scored[0].url).toBe('https://example.com/near');
    });

    // -------------------------------------------------------------------------
    // Test 3 — No locations → fallback to term-only scoring, no crash
    //
    // Mirrors Rust: test_phrase_scoring_without_locations_no_crash
    //
    // When locations is null, phrase_proximity_multiplier returns 1.0 and
    // scoring falls back to the pre-phrase term-match algorithm.
    // -------------------------------------------------------------------------
    test('null locations falls back to term scoring without crashing', () => {
        const r1 = makeResult(
            'https://example.com/1',
            'Hello World Page',
            'hello world content',
            1.0,
            null,   // no locations
        );
        const r2 = makeResult(
            'https://example.com/2',
            'Other Page',
            'other content',
            0.5,
            null,
        );

        let scored;
        expect(() => {
            scored = scoreViaWasm('hello world', [r1, r2]);
        }).not.toThrow();

        // r1 must still win via title + content term matching.
        expect(scored[0].url).toBe('https://example.com/1');
    });

});
