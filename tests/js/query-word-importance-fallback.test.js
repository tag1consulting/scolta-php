'use strict';

// Unit tests for the JS fallback (WASM-off) per-query-word importance weighting.
// Extracts titleMatchScoreFallback / contentMatchScoreFallback straight from the
// canonical scolta.js and drives them with a controllable config. The fallback
// must mirror the Rust scorer: incidental-word matches are down-weighted, and an
// absent map reproduces the pre-importance formula.
const fs = require('fs');
const path = require('path');

const scoltaSource = fs.readFileSync(
  path.resolve(__dirname, '../../assets/js/scolta.js'),
  'utf-8'
);

function extractFallbacks() {
  const fnSource = `
    var CONFIG;
    function getInstanceConfig() { return CONFIG; }
    ${scoltaSource.match(/function titleMatchScoreFallback[\s\S]*?\n  \}/)[0]}
    ${scoltaSource.match(/function contentMatchScoreFallback[\s\S]*?\n  \}/)[0]}
    return {
      title: titleMatchScoreFallback,
      content: contentMatchScoreFallback,
      setConfig: function(c) { CONFIG = c; },
    };
  `;
  return new Function(fnSource)();
}

describe('JS fallback query-word importance weighting', () => {
  let fb;
  const baseConfig = {
    TITLE_MATCH_BOOST: 1.0,
    TITLE_ALL_TERMS_MULTIPLIER: 1.5,
    CONTENT_MATCH_BOOST: 0.4,
    INCIDENTAL_MATCH_WEIGHT: 0.3,
    AI_QUERY_WORD_IMPORTANCE: true,
  };

  beforeAll(() => { fb = extractFallbacks(); });
  beforeEach(() => { fb.setConfig(Object.assign({}, baseConfig)); });

  const importance = { grilled: 'incidental', vegetables: 'content' };

  test('incidental-only title match scores below content-only title match', () => {
    const meat = fb.title('Grilled Pork Tenderloin', 'grilled vegetables', importance);
    const veg = fb.title('Roasted Vegetables Medley', 'grilled vegetables', importance);
    expect(veg).toBeGreaterThan(meat);
  });

  test('content-only title match earns the all-terms multiplier (content-keyed)', () => {
    // matched_weight = 1.0 (vegetables), denom = 2 terms, all CONTENT matched.
    const veg = fb.title('Steamed Vegetables', 'grilled vegetables', importance);
    const expected = baseConfig.TITLE_MATCH_BOOST * baseConfig.TITLE_ALL_TERMS_MULTIPLIER * (1.0 / 2.0);
    expect(veg).toBeCloseTo(expected, 10);
  });

  test('incidental-only title match equals weight/terms with no multiplier', () => {
    const meat = fb.title('Grilled Pork Tenderloin', 'grilled vegetables', importance);
    const expected = baseConfig.TITLE_MATCH_BOOST * (0.3 / 2.0);
    expect(meat).toBeCloseTo(expected, 10);
  });

  test('content match down-weights the incidental word', () => {
    const meat = fb.content('a grilled dish', 'grilled vegetables', importance);
    const veg = fb.content('fresh vegetables', 'grilled vegetables', importance);
    expect(veg).toBeGreaterThan(meat);
  });

  test('absent importance reproduces the unweighted formula', () => {
    const withMap = fb.title('Grilled Pork Tenderloin', 'grilled vegetables', null);
    const expected = baseConfig.TITLE_MATCH_BOOST * (1 / 2); // matchCount 1 of 2, no multiplier
    expect(withMap).toBeCloseTo(expected, 10);
  });

  test('AI_QUERY_WORD_IMPORTANCE off disables weighting', () => {
    fb.setConfig(Object.assign({}, baseConfig, { AI_QUERY_WORD_IMPORTANCE: false }));
    const off = fb.title('Grilled Pork Tenderloin', 'grilled vegetables', importance);
    const unweighted = baseConfig.TITLE_MATCH_BOOST * (1 / 2);
    expect(off).toBeCloseTo(unweighted, 10);
  });
});
