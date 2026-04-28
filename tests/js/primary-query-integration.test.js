'use strict';

const { getWasm } = require('./wasm-helper');

describe('primary_query propagation', () => {
  let wasm;

  beforeAll(() => {
    wasm = getWasm();
  });

  test('score_results with primary_query boosts title match', () => {
    const results = [
      {
        title: 'Thai Green Curry',
        url: '/thai-curry',
        excerpt: 'A Southeast Asian classic with coconut milk and fresh herbs.',
        score: 0.5,
        locations: [],
      },
      {
        title: 'Pasta Primavera',
        url: '/pasta',
        excerpt: 'Thai basil optional in this Italian spring vegetable dish.',
        score: 0.5,
        locations: [],
      },
    ];

    const config = {
      language: 'en',
      title_match_boost: 1.0,
      title_all_terms_multiplier: 1.5,
      content_match_boost: 0.4,
      content_all_terms_multiplier: 1.2,
    };

    // Score with expanded query only — no primary_query
    const withoutPrimary = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'Southeast Asian cooking',
      results: results,
      config: config,
    })));

    // Score with expanded query + primary_query
    const withPrimary = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'Southeast Asian cooking',
      results: results,
      config: config,
      primary_query: 'Thai recipes',
    })));

    // With primary_query, "Thai Green Curry" should score higher because
    // "Thai" from primary_query matches the title.
    const thaiWithout = withoutPrimary.find(r => r.url === '/thai-curry').score;
    const thaiWith = withPrimary.find(r => r.url === '/thai-curry').score;
    expect(thaiWith).toBeGreaterThan(thaiWithout);

    // "Pasta Primavera" should NOT get a primary_query title boost
    const pastaWithout = withoutPrimary.find(r => r.url === '/pasta').score;
    const pastaWith = withPrimary.find(r => r.url === '/pasta').score;
    // Pasta score may change slightly due to content matching, but should not
    // get a title boost — "Thai" and "recipes" are not in "Pasta Primavera"
    expect(pastaWith).toBeCloseTo(pastaWithout, 1);
  });

  test('score_results without primary_query is backward compatible', () => {
    const results = [
      {
        title: 'Test Page',
        url: '/test',
        excerpt: 'Test content for backward compatibility check.',
        score: 0.5,
        locations: [],
      },
    ];

    // No primary_query field at all — must not error
    const output = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'test',
      results: results,
      config: { language: 'en' },
    })));

    expect(output).toHaveLength(1);
    expect(output[0].url).toBe('/test');
  });

  test('primary_query with empty string is treated as absent', () => {
    const results = [
      {
        title: 'Sample',
        url: '/sample',
        excerpt: 'Sample content.',
        score: 0.5,
        locations: [],
      },
    ];

    // Empty string primary_query — should behave same as absent
    const withEmpty = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'sample',
      results: results,
      config: { language: 'en' },
      primary_query: '',
    })));

    const withAbsent = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'sample',
      results: results,
      config: { language: 'en' },
    })));

    expect(withEmpty[0].score).toBeCloseTo(withAbsent[0].score, 5);
  });
});
