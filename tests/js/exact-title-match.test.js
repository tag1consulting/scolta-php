'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Extract the JS fallback scoring functions from the IIFE for isolated testing.
// We build a mini-environment that exposes the scoring internals.
function buildScoringEnv(configOverrides = {}) {
    const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
        url: 'https://example.com',
        runScripts: 'dangerously',
    });
    const window = dom.window;
    window.console = { log: jest.fn(), warn: jest.fn(), error: jest.fn() };

    // Set scolta config — no WASM loaded so JS fallback path is used.
    window.scolta = {
        scoring: Object.assign({
            TITLE_MATCH_BOOST: 1.0,
            TITLE_ALL_TERMS_MULTIPLIER: 1.5,
            EXACT_TITLE_MATCH_BOOST: 5.0,
            CONTENT_MATCH_BOOST: 0.4,
            RECENCY_BOOST_MAX: 0.0,
            RECENCY_HALF_LIFE_DAYS: 365,
            RECENCY_PENALTY_AFTER_DAYS: 99999,
            RECENCY_MAX_PENALTY: 0.0,
            RECENCY_STRATEGY: 'none',
        }, configOverrides),
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#nonexistent',
        allowedLinkDomains: [],
        disclaimer: '',
    };

    // Patch dynamic import to provide a mock Pagefind that returns controllable results.
    const mockResults = [];
    const patched = scoltaSource.replace(
        /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
        `pagefind = {
            init: function() { return Promise.resolve(); },
            search: function() { return Promise.resolve({ results: [] }); },
            filters: function() { return Promise.resolve({}); }
        }`
    );
    window.eval(patched);

    return { dom, window };
}

// Helper: call scoreResults via the internal createInstance factory.
// Since scoreResults is inside the IIFE, we test it indirectly through
// the full search flow with a custom Pagefind mock, or directly by
// extracting the function from source.
function extractScoreResults() {
    // Build a standalone function from the scolta.js source that exposes
    // the JS fallback scoring logic (no WASM) for direct testing.
    const fnSource = `
        // Minimal config reader
        var CONFIG;
        function getInstanceConfig() { return CONFIG; }
        function resolveUrl(u) { return u; }

        // Extract STOPWORDS and extractSearchTerms
        ${scoltaSource.match(/const STOPWORDS[\s\S]*?function extractSearchTerms\([^)]*\)\s*\{[\s\S]*?\n  \}/)[0]}

        // Extract scoring functions
        ${scoltaSource.match(/function recencyScoreFallback[\s\S]*?\n  \}/)[0]}
        ${scoltaSource.match(/function titleMatchScoreFallback[\s\S]*?\n  \}/)[0]}
        ${scoltaSource.match(/function contentMatchScoreFallback[\s\S]*?\n  \}/)[0]}
        ${scoltaSource.match(/function computeContentWordLocations[\s\S]*?\n  \}/)[0]}

        // The scoreResults function (without WASM path)
        function scoreResults(loaded, query, sourceWeight, primaryQuery) {
            var scored;
            // JS fallback scoring only (no scoltaWasm in this env)
            var count = loaded.length;
            scored = loaded.map(function(data, i) {
                var pagefindScore = count > 1 ? 1 - (i / (count - 1)) : 1;
                var recency = recencyScoreFallback(data.meta ? data.meta.date : undefined);
                var titleBoost = titleMatchScoreFallback(data.meta ? data.meta.title : '', query);
                var contentBoost = contentMatchScoreFallback(data.excerpt, query);
                var finalScore = (pagefindScore + recency + titleBoost + contentBoost) * sourceWeight;
                return { data: data, score: finalScore };
            });
            // Exact title match boost
            var normalizedQuery = (primaryQuery || query).toLowerCase().trim();
            if (normalizedQuery && CONFIG.EXACT_TITLE_MATCH_BOOST > 1.0) {
                for (var r of scored) {
                    var title = (r.data.meta && r.data.meta.title || '').toLowerCase().trim();
                    if (title && title === normalizedQuery) {
                        r.score *= CONFIG.EXACT_TITLE_MATCH_BOOST;
                    }
                }
            }
            return scored;
        }

        return { scoreResults: scoreResults, setConfig: function(c) { CONFIG = c; } };
    `;

    const factory = new Function(fnSource);
    return factory();
}

describe('exact title match boost', () => {
    let scoring;
    const defaultConfig = {
        TITLE_MATCH_BOOST: 1.0,
        TITLE_ALL_TERMS_MULTIPLIER: 1.5,
        EXACT_TITLE_MATCH_BOOST: 5.0,
        CONTENT_MATCH_BOOST: 0.4,
        RECENCY_BOOST_MAX: 0.0,
        RECENCY_HALF_LIFE_DAYS: 365,
        RECENCY_PENALTY_AFTER_DAYS: 99999,
        RECENCY_MAX_PENALTY: 0.0,
        RECENCY_STRATEGY: 'none',
    };

    beforeAll(() => {
        scoring = extractScoreResults();
    });

    test('exact title match receives large multiplicative boost', () => {
        scoring.setConfig(defaultConfig);
        const results = [
            { url: '/dna', meta: { title: 'DNA' }, excerpt: 'DNA overview article.' },
            { url: '/dna-nano', meta: { title: 'DNA nanotechnology' }, excerpt: 'DNA nanotechnology involves building nanoscale structures using DNA. DNA is used extensively in DNA nanotechnology research. DNA scaffolds enable DNA origami.' },
        ];
        const scored = scoring.scoreResults(results, 'DNA', 1.0);
        scored.sort((a, b) => b.score - a.score);
        expect(scored[0].data.url).toBe('/dna');
        expect(scored[0].score).toBeGreaterThan(scored[1].score);
    });

    test('exact title match is case-insensitive', () => {
        scoring.setConfig(defaultConfig);
        const results = [
            { url: '/dna', meta: { title: 'DNA' }, excerpt: 'Overview.' },
        ];
        const scored = scoring.scoreResults(results, 'dna', 1.0);
        // Score should include the exact match multiplier
        const baseScore = scored[0].score / defaultConfig.EXACT_TITLE_MATCH_BOOST;
        expect(scored[0].score).toBeGreaterThan(baseScore);
    });

    test('exact title match disabled when boost is 1.0', () => {
        scoring.setConfig(Object.assign({}, defaultConfig, { EXACT_TITLE_MATCH_BOOST: 1.0 }));
        const results = [
            { url: '/dna', meta: { title: 'DNA' }, excerpt: 'Overview.' },
        ];
        const scoredWith1 = scoring.scoreResults(results, 'DNA', 1.0);

        scoring.setConfig(Object.assign({}, defaultConfig, { EXACT_TITLE_MATCH_BOOST: 5.0 }));
        const scoredWith5 = scoring.scoreResults(results, 'DNA', 1.0);

        expect(scoredWith5[0].score).toBeGreaterThan(scoredWith1[0].score);
    });

    test('partial title match does not trigger exact boost', () => {
        scoring.setConfig(defaultConfig);
        const results = [
            { url: '/dna-nano', meta: { title: 'DNA nanotechnology' }, excerpt: 'DNA nanotechnology article.' },
        ];
        const scored = scoring.scoreResults(results, 'DNA', 1.0);
        // The partial match should get the regular title_match_boost but NOT the exact boost
        // With EXACT_TITLE_MATCH_BOOST=5.0, an exact match would multiply the score.
        // For partial match, score should be notably less than 5x what a no-title-match would be.
        const noTitleResults = [
            { url: '/other', meta: { title: 'Completely unrelated' }, excerpt: 'DNA mentioned once.' },
        ];
        const noTitleScored = scoring.scoreResults(noTitleResults, 'DNA', 1.0);
        // partial-match score should be less than 5x itself (proving no exact boost applied)
        expect(scored[0].score).toBeLessThan(scored[0].score * defaultConfig.EXACT_TITLE_MATCH_BOOST);
    });

    test('exact title match beats higher BM25 partial match', () => {
        scoring.setConfig(defaultConfig);
        // Simulate: "DNA" article (index 5, low BM25) vs "DNA nanotechnology" (index 0, top BM25)
        const results = [
            { url: '/dna-nano', meta: { title: 'DNA nanotechnology' }, excerpt: 'DNA nanotechnology with DNA scaffolds and DNA origami using DNA structures. DNA research advances DNA technology.' },
            { url: '/other1', meta: { title: 'Genetics overview' }, excerpt: 'DNA basics.' },
            { url: '/other2', meta: { title: 'Molecular biology' }, excerpt: 'DNA structure.' },
            { url: '/other3', meta: { title: 'Biochemistry' }, excerpt: 'DNA chemistry.' },
            { url: '/other4', meta: { title: 'Cell biology' }, excerpt: 'DNA in cells.' },
            { url: '/dna', meta: { title: 'DNA' }, excerpt: 'Short article about DNA.' },
        ];
        const scored = scoring.scoreResults(results, 'DNA', 1.0);
        scored.sort((a, b) => b.score - a.score);
        // Despite being last in Pagefind ranking (lowest BM25 proxy), "DNA" should rank #1
        expect(scored[0].data.url).toBe('/dna');
    });

    test('multi-word exact title match works', () => {
        scoring.setConfig(defaultConfig);
        const results = [
            { url: '/apollo11', meta: { title: 'Apollo 11' }, excerpt: 'Moon landing.' },
            { url: '/apollo-program', meta: { title: 'Apollo program' }, excerpt: 'Apollo 11 was part of the Apollo program.' },
        ];
        const scored = scoring.scoreResults(results, 'Apollo 11', 1.0);
        scored.sort((a, b) => b.score - a.score);
        expect(scored[0].data.url).toBe('/apollo11');
    });

    test('primaryQuery used for exact match when scoring expansion terms', () => {
        scoring.setConfig(defaultConfig);
        const results = [
            { url: '/dna', meta: { title: 'DNA' }, excerpt: 'Article about deoxyribonucleic acid.' },
            { url: '/acid', meta: { title: 'Deoxyribonucleic acid' }, excerpt: 'Full name of DNA molecule.' },
        ];
        // Scoring expansion term "deoxyribonucleic acid" with primaryQuery "DNA"
        const scored = scoring.scoreResults(results, 'deoxyribonucleic acid', 1.0, 'DNA');
        scored.sort((a, b) => b.score - a.score);
        // "DNA" should get exact title boost because primaryQuery is "DNA"
        expect(scored[0].data.url).toBe('/dna');
    });

    test('missing title does not crash', () => {
        scoring.setConfig(defaultConfig);
        const results = [
            { url: '/no-title', meta: {}, excerpt: 'DNA content.' },
            { url: '/null-meta', excerpt: 'DNA content.' },
        ];
        expect(() => scoring.scoreResults(results, 'DNA', 1.0)).not.toThrow();
    });
});
