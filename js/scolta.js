/**
 * Scolta — AI-powered search (shared JavaScript)
 *
 * Reads configuration from window.scolta object.
 * Handles: Pagefind integration, scoring/re-ranking, search UI, AI client logic.
 *
 * TODO: Extract from Montefiore's pagefind-search.html
 */
(function() {
  'use strict';

  const defaults = {
    pagefindPath: '/_pagefind/pagefind.js',
    aiEndpoints: {
      expand: '/api/scolta/expand',
      summarize: '/api/scolta/summarize',
      followup: '/api/scolta/followup',
    },
    scoring: {
      recencyDecay: 0.1,
      titleBoost: 2.0,
      contentBoost: 1.0,
    },
  };

  window.scolta = Object.assign({}, defaults, window.scolta || {});

  console.log('Scolta initialized', window.scolta);
})();
