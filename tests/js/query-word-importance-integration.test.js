'use strict';

// Exercises the real WASM scorer (the JS→WASM boundary) for the per-query-word
// importance ranking signal (issue #163 follow-up). The map down-weights matches
// on "incidental" query words so a result matching only the incidental word ranks
// below one matching the content word.
const { getWasm } = require('./wasm-helper');

describe('query_word_importance scoring', () => {
  let wasm;

  beforeAll(() => {
    wasm = getWasm();
  });

  const config = {
    language: 'en',
    title_match_boost: 1.0,
    title_all_terms_multiplier: 1.5,
    content_match_boost: 0.4,
    content_all_terms_multiplier: 1.2,
    incidental_match_weight: 0.3,
  };

  // "grilled vegetables": grilled is incidental, vegetables is content. A
  // grilled-meat dish (matches only "grilled") must rank below a vegetable dish
  // (matches "vegetables") once importance is supplied.
  const results = () => [
    {
      title: 'Grilled Pork Tenderloin',
      url: '/meat',
      excerpt: 'A grilled pork main course.',
      score: 1.0,
      locations: [],
    },
    {
      title: 'Roasted Vegetables Medley',
      url: '/veg',
      excerpt: 'An assortment of vegetables.',
      score: 1.0,
      locations: [],
    },
  ];

  test('incidental-only match ranks below content match with importance', () => {
    const out = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'grilled vegetables',
      primary_query: 'grilled vegetables',
      results: results(),
      config: config,
      query_word_importance: { grilled: 'incidental', vegetables: 'content' },
    })));
    const veg = out.find(r => r.url === '/veg').score;
    const meat = out.find(r => r.url === '/meat').score;
    expect(veg).toBeGreaterThan(meat);
  });

  test('omitting importance leaves the two single-term matches tied', () => {
    const out = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'grilled vegetables',
      primary_query: 'grilled vegetables',
      results: results(),
      config: config,
    })));
    const veg = out.find(r => r.url === '/veg').score;
    const meat = out.find(r => r.url === '/meat').score;
    // Each matches exactly one of two terms → equal title boost → tied.
    expect(veg).toBeCloseTo(meat, 6);
  });

  test('incidental_match_weight = 1.0 reproduces no-map scores byte-for-byte', () => {
    const noMap = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'grilled vegetables',
      primary_query: 'grilled vegetables',
      results: results(),
      config: config,
    })));
    const unity = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'grilled vegetables',
      primary_query: 'grilled vegetables',
      results: results(),
      config: Object.assign({}, config, { incidental_match_weight: 1.0 }),
      query_word_importance: { grilled: 'incidental', vegetables: 'content' },
    })));
    for (const r of noMap) {
      expect(unity.find(x => x.url === r.url).score).toBe(r.score);
    }
  });

  test('all-content query ordering is unchanged by an (empty) importance map', () => {
    const both = [
      { title: 'Crispy Soft Bread', url: '/a', excerpt: 'crispy and soft', score: 1.0, locations: [] },
      { title: 'Crispy Wafer', url: '/b', excerpt: 'just crispy', score: 1.0, locations: [] },
    ];
    const plain = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'crispy soft', primary_query: 'crispy soft', results: both, config: config,
    })));
    const labeled = JSON.parse(wasm.score_results(JSON.stringify({
      query: 'crispy soft', primary_query: 'crispy soft', results: both, config: config,
      query_word_importance: { crispy: 'content', soft: 'content' },
    })));
    for (const r of plain) {
      expect(labeled.find(x => x.url === r.url).score).toBe(r.score);
    }
  });

  test('malformed importance map does not error', () => {
    for (const bad of ['nope', [1, 2, 3], { grilled: 7 }]) {
      const out = JSON.parse(wasm.score_results(JSON.stringify({
        query: 'grilled vegetables',
        results: results(),
        config: config,
        query_word_importance: bad,
      })));
      expect(out).toHaveLength(2);
    }
  });
});
