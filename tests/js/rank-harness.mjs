/**
 * Deterministic offline ranking harness for the Apollo specificity-tuning sweep.
 *
 * Loads the REAL browser stack in Node — real Pagefind index, real scolta-core
 * WASM, real scolta.js ranking + merge path — and drives it with FROZEN
 * expansion terms so the ONLY variable between runs is the scoring config. No
 * network, no LLM, no browser. One query ≈ a few ms, so a full sweep is fast.
 *
 * Fidelity: the per-term Pagefind result sets are deterministic given a fixed
 * index, the WASM scorer is the production binary, and the specificity/merge
 * orchestration is the exact code the browser runs. The frozen expansions are
 * the live temperature-0 terms captured 2026-07-23.
 *
 *   node rank-harness.mjs                 # scorecard for all rows, base config
 *   node rank-harness.mjs --config=k=v,…  # override scoring knobs for a sweep
 *   node rank-harness.mjs --top=12 -v     # print ranked lists
 *
 * Requires the Apollo demo's Pagefind index to exist at PF_DIR (build it with
 * `ddev wp scolta build` in demos/apollo-blog).
 */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const HERE = path.dirname(fileURLToPath(import.meta.url));
// SCOLTA_JS env override lets the sweep score an alternate build (e.g. the
// committed HEAD copy) with everything else held fixed, so "candidate vs
// current deploy" is a single-variable comparison.
const SCOLTA_JS = process.env.SCOLTA_JS
  ? path.resolve(process.env.SCOLTA_JS)
  : path.resolve(HERE, '../../assets/js/scolta.js');
const WASM_GLUE = path.resolve(HERE, '../../assets/wasm/scolta_core.js');
const WASM_BIN = path.resolve(HERE, '../../assets/wasm/scolta_core_bg.wasm');
const PF_DIR = path.resolve(
  HERE, '../../../../demos/apollo-blog/wp-content/uploads/scolta/pagefind'
) + '/';

if (!existsSync(PF_DIR + 'pagefind-entry.json')) {
  console.error('Pagefind index not found at', PF_DIR);
  console.error('Build it: cd demos/apollo-blog && ddev wp scolta build');
  process.exit(2);
}

// ---------------------------------------------------------------------------
// Exact scoring config the demo serves (preset "blog"). Sweep overrides merge
// on top of this so the baseline is byte-identical to live.
// ---------------------------------------------------------------------------
const BASE_CONFIG = {
  RECENCY_BOOST_MAX: 0.1, RECENCY_HALF_LIFE_DAYS: 3650,
  RECENCY_PENALTY_AFTER_DAYS: 36500, RECENCY_MAX_PENALTY: 0.05,
  TITLE_MATCH_BOOST: 1.5, TITLE_ALL_TERMS_MULTIPLIER: 1.5,
  EXACT_TITLE_MATCH_BOOST: 5, CONTENT_MATCH_BOOST: 0.5,
  PHRASE_ADJACENT_MULTIPLIER: 2.5, PHRASE_NEAR_MULTIPLIER: 1.5,
  PHRASE_NEAR_WINDOW: 5, PHRASE_WINDOW: 15,
  EXCERPT_LENGTH: 350, RESULTS_PER_PAGE: 12, MAX_PAGEFIND_RESULTS: 60,
  AI_EXPAND_QUERY: true, AI_SUMMARIZE: false,
  AI_SUMMARY_TOP_N: 10, AI_SUMMARY_MAX_CHARS: 4000,
  EXPAND_PRIMARY_WEIGHT: 0.6, CROSS_LIST_BONUS: 0.05,
  EXPAND_SUBWORD_MAX_FREQ: 0.05, EXPAND_SUBWORD_DENYLIST: [],
  FILTER_HINT_MIN_RESULTS: 5, FILTER_HINT_MIN_RATIO: 0.1,
  EXPANSION_COMBINE_MODE: 'round_robin', EXPANSION_PER_TERM_TOP_K: 3,
  AI_MAX_FOLLOWUPS: 3, AI_LANGUAGES: ['en'], AUTO_LANGUAGE_FILTER: false,
  LANGUAGE: 'en', CUSTOM_STOP_WORDS: [], RECENCY_STRATEGY: 'exponential',
  RECENCY_CURVE: [],
};

// ---------------------------------------------------------------------------
// Frozen live expansions (temperature 0, captured 2026-07-23). '' = no expansion.
// ---------------------------------------------------------------------------
const FROZEN = {
  'apollo 13 crisis': ['oxygen tank explosion', 'successful failure', 'Lovell Swigert Haise', 'lunar module lifeboat', 'carbon dioxide scrubbers', 'mission control Houston'],
  'apollo 1 fire': ['Gus Grissom', 'Ed White', 'Roger Chaffee', 'launch pad 34', 'cabin fire', 'January 27 1967'],
  'last one': ['final', 'most recent', 'latest entry'],
  'scary moment': ['frightening experience', 'terrifying incident', 'close call', 'near miss'],
  'Pete Conrad': ['Charles Conrad', 'Conrad Jr', 'Apollo 12 commander', 'third man on moon'],
  'Cernan last words': ['Gene Cernan final message', 'last man on moon farewell', 'Apollo 17 departure speech', 'Cernan lunar surface goodbye'],
  'The Eagle Has Landed': [],
};

// Expansion override for what-if analysis and Phase-4 frozen-vs-live reconciliation.
// SCOLTA_EXP_OVERRIDE='scary moment=malfunction|program alarm|abort|emergency; last one=final|latest'
// replaces the frozen expansion for the named queries (terms split on '|', rows on ';').
// Ranking is unchanged; only the expansion fed in changes, so it isolates "what must the
// live expansion produce for this row to pass" from the ranking config.
if (process.env.SCOLTA_EXP_OVERRIDE) {
  for (const row of process.env.SCOLTA_EXP_OVERRIDE.split(';')) {
    const [q, terms] = row.split('=');
    if (q && terms != null) FROZEN[q.trim()] = terms.split('|').map(t => t.trim()).filter(Boolean);
  }
}

// ---------------------------------------------------------------------------
// Acceptance table. `must` rows gate the scorecard; `better` rows are bonus.
// Matchers normalize punctuation/curly-quotes away.
// ---------------------------------------------------------------------------
const norm = s => (s || '').toLowerCase().replace(/[’'`]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
const rankOf = (titles, needle) => titles.findIndex(t => norm(t).includes(norm(needle))); // 0-based, -1 if absent
const inTop = (titles, needle, n) => { const r = rankOf(titles, needle); return r >= 0 && r < n; };
const CRISIS3 = ['houston weve had a problem', 'lifeboat', 'carbon dioxide'];
const FIRE3 = ['worst day', 'terrible friday', 'after the fire'];

const ACCEPTANCE = [
  {
    query: 'apollo 13 crisis',
    must: [
      ['real crisis post at #1', (t) => CRISIS3.some(c => rankOf(t, c) === 0)],
      ['Cuban Missile Crisis not in top 3', (t) => !inTop(t, 'cuban missile', 3)],
      ['all three crisis posts in top 8', (t) => CRISIS3.every(c => inTop(t, c, 8))],
      ['Apollo 15 Home off page (not top 12)', (t) => !inTop(t, 'apollo 15 home', 12)],
    ],
    better: [['Cuban Missile Crisis off first page', (t) => !inTop(t, 'cuban missile', 12)]],
  },
  {
    query: 'apollo 1 fire',
    must: [
      ['real fire post at #1', (t) => FIRE3.some(c => rankOf(t, c) === 0)],
      ['Apollo 8 Comes Home below every real fire post', (t) => {
        const a8 = rankOf(t, 'apollo 8 comes home');
        const fires = FIRE3.map(c => rankOf(t, c)).filter(r => r >= 0);
        if (a8 < 0) return true; // not on page → below all
        return fires.length > 0 && fires.every(r => r < a8);
      }],
    ],
    better: [['A Terrible Friday Afternoon in top 5', (t) => inTop(t, 'terrible friday', 5)]],
  },
  {
    query: 'last one',
    must: [
      ['top two are Counting Down + Last Footprint', (t) => {
        const a = new Set([norm(t[0]), norm(t[1])].map(x => x));
        return [t[0], t[1]].filter(Boolean).some(x => norm(x).includes('counting down to the last landing'))
          && [t[0], t[1]].filter(Boolean).some(x => norm(x).includes('last footprint'));
      }],
      ['Gordon Cooper not in top 2', (t) => !inTop(t, 'gordon cooper', 2)],
      ['Gemini 12 The Last One around 5 (rank 3–7)', (t) => { const r = rankOf(t, 'gemini 12'); return r >= 3 && r <= 6; }],
      ['about 75 results (60–90)', (_t, count) => count >= 60 && count <= 90],
    ],
    better: [['Gordon Cooper off first page', (t) => !inTop(t, 'gordon cooper', 12)]],
  },
  {
    query: 'scary moment',
    must: [
      ['Apollo 10 descent + Eagle descent both in top 4', (t) => inTop(t, 'apollo 10s descent', 4) && inTop(t, 'eagle has landed', 4)],
      ['Conrad First Words / Armstrong Voice / Feather+Hammer all out of top 3',
        (t) => !inTop(t, 'first words on the moon', 3) && !inTop(t, 'armstrongs voice', 3) && !inTop(t, 'feather and the hammer', 3)],
    ],
    better: [['both tense posts in top 2', (t) => inTop(t, 'apollo 10s descent', 2) && inTop(t, 'eagle has landed', 2)]],
  },
  {
    query: 'Pete Conrad',
    must: [['#1 is a Conrad post', (t) => norm(t[0]).includes('conrad')]],
    better: [['top 3 all Conrad posts', (t) => t.slice(0, 3).every(x => norm(x).includes('conrad'))]],
  },
  {
    query: 'Cernan last words',
    must: [
      ['The Last Footprint at #1', (t) => rankOf(t, 'last footprint') === 0],
      ['Pioneer 10 not in top 3', (t) => !inTop(t, 'pioneer 10', 3)],
    ],
    better: [['Pioneer 10 off first page', (t) => !inTop(t, 'pioneer 10', 12)]],
  },
  {
    query: 'The Eagle Has Landed',
    must: [
      // The post's real title is "Tranquility Base Here — The Eagle Has Landed",
      // so this is a containment check, not string equality.
      ['exact post "The Eagle Has Landed" at #1', (t) => norm(t[0]).includes('the eagle has landed')],
      ['exact path: few results (≤ 10)', (_t, count) => count > 0 && count <= 10],
    ],
    better: [],
  },
];

// ---------------------------------------------------------------------------
// Real Pagefind, loaded once, reused across all queries (search is stateless).
// ---------------------------------------------------------------------------
async function loadPagefind() {
  const realFetch = global.fetch;
  global.fetch = async (input, init) => {
    const url = typeof input === 'string' ? input : (input.url || String(input));
    if (url.startsWith('file:')) {
      const p = fileURLToPath(url.split('?')[0]);
      return new Response(readFileSync(p), { status: 200, headers: { 'content-type': 'application/octet-stream' } });
    }
    return realFetch(input, init);
  };
  global.Worker = class { constructor() { throw new Error('no worker in node'); } };
  const pf = await import(pathToFileURL(PF_DIR + 'pagefind.js').href);
  await pf.init();
  await pf.search(''); // warm
  return pf;
}

// Real production WASM, synchronously initialized (mirrors tests/js/wasm-helper.js),
// returning the FULL export surface scolta.js may call.
function loadWasm() {
  let source = readFileSync(WASM_GLUE, 'utf-8');
  source = source.replace(/import\.meta\.url/g, JSON.stringify(pathToFileURL(WASM_GLUE).href));
  source = source.replace(/^export function /gm, 'function ');
  source = source.replace(/^export async function /gm, 'async function ');
  source = source.replace(/^export\s*\{[^}]*\};\s*$/m, '');
  const tail = `
    return { score_results, batch_score_results, batch_extract_context, merge_results,
      match_priority_pages, sanitize_query, extract_context, parse_expansion,
      version, initSync, init: __wbg_init };`;
  const mod = new Function(source + tail)(); // eslint-disable-line no-new-func
  mod.initSync({ module: readFileSync(WASM_BIN) });
  return mod;
}

// ---------------------------------------------------------------------------
// Patched scolta.js source: inject the real pagefind + WASM instances.
// ---------------------------------------------------------------------------
function patchedScolta() {
  let src = readFileSync(SCOLTA_JS, 'utf-8');
  src = src.replace(/pagefind\s*=\s*await\s+import\s*\([^)]+\)/, 'pagefind = global.__pfMock');
  src = src.replace(/const wasm = await import\(wasmPath\);/, 'const wasm = global.__scoltaWasm;');
  src = src.replace(/await wasm\.default\(\);/, '/* wasm preinitialized */;');
  // --scores: publish the FINAL merged+sorted list (after the OR fallback and
  // its agreement bonus) so the sweep sees the list the user actually gets, plus
  // a per-URL breakdown of base score vs agreement bonus and how many terms
  // agreed. Ranking at the expansion stage alone is misleading: both failing
  // queries have an empty AND result and are therefore decided by the OR path.
  if (process.env.DBG_SCORES) {
    src = src.replace(
      `      results.push({ data: e.data, score: e.top, agreementBonus: COOCCUR * agreementSum });`,
      `      try {
        const _u = resolveUrl(e.data.url || '');
        window.__dbgAgree = window.__dbgAgree || {};
        const _p = window.__dbgAgree[_u] || (window.__dbgAgree[_u] = { base: 0, bonus: 0, n: 0 });
        _p.base = e.top; _p.bonus = COOCCUR * agreementSum; _p.n = agreements.length;
      } catch (_e) {}
      results.push({ data: e.data, score: e.top, agreementBonus: COOCCUR * agreementSum });`
    );
      // Per-term df/specificity, and the pre-expansion (primary/OR) list, so the
    // origin of a score is attributable to a stage instead of guessed at.
    src = src.replace(
      `    const results = [];
    for (const e of byUrl.values()) {`,
      `    try {
      window.__dbgTerms = window.__dbgTerms || [];
      for (const [t, sp] of specByTerm) window.__dbgTerms.push({ t, sp, typed: !seedingTerms.has(t) });
    } catch (e) {}
    const results = [];
    for (const e of byUrl.values()) {`
    );
    src = src.replace(
      `    allScoredResults.sort((a, b) => b.score - a.score);
    allScoredResults = deduplicateByTitle(allScoredResults);

    const priorityPages = getInstancePriorityPages();`,
      `    allScoredResults.sort((a, b) => b.score - a.score);
    allScoredResults = deduplicateByTitle(allScoredResults);
    try {
      window.__dbgPre = allScoredResults.map(r => ({ t: (r.data.meta || {}).title || '', s: r.score }));
      window.__dbgPath = { primaryCount: primarySearch.results.length, usedOrFallback, terms: meaningfulTerms };
    } catch (e) {}

    const priorityPages = getInstancePriorityPages();`
    );

  // Dump the list AFTER the expansion merge resolves. The pre-expansion sort
    // in doSearch() renders first but is immediately superseded by this one, so
    // it is the wrong list to reason about.
    src = src.replace(
      `      applyAgreementBonus(allScoredResults, expandedResults);
      allScoredResults.sort((a, b) => b.score - a.score);
      allScoredResults = deduplicateByTitle(allScoredResults);`,
      `      applyAgreementBonus(allScoredResults, expandedResults);
      allScoredResults.sort((a, b) => b.score - a.score);
      allScoredResults = deduplicateByTitle(allScoredResults);
      try {
        window.__dbgScores = allScoredResults.map(r => {
          const d = (window.__dbgAgree || {})[resolveUrl(r.data.url || '')] || {};
          return { t: (r.data.meta || {}).title || '', s: r.score, base: d.base, bonus: d.bonus, n: d.n };
        });
      } catch (e) {}`
    );
  }
  return src;
}

const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

async function runQuery(queryText, config, pf, wasm, scoltaSrc) {
  const frozen = FROZEN[queryText] ?? [];
  const dom = new JSDOM(
    `<!DOCTYPE html><html lang="en"><body><div id="scolta-search"></div></body></html>`,
    { url: 'https://counting-down-apollo.ddev.site/search/', runScripts: 'dangerously' }
  );
  const w = dom.window;
  w.__pfMock = pf;
  w.__scoltaWasm = wasm;

  // window.fetch: entry.json from disk, expand → frozen terms, everything else benign.
  w.fetch = async (input) => {
    const url = String(typeof input === 'string' ? input : (input && input.url) || '');
    if (url.includes('pagefind-entry.json')) {
      return { ok: true, status: 200, json: async () => JSON.parse(readFileSync(PF_DIR + 'pagefind-entry.json', 'utf-8')), text: async () => '{}' };
    }
    if (url.includes('expand')) {
      return { ok: true, status: 200, json: async () => ({ terms: frozen }), text: async () => JSON.stringify({ terms: frozen }) };
    }
    return { ok: true, status: 200, json: async () => ({}), text: async () => '{}' };
  };
  const warnings = [];
  w.console = { log() {}, error(...a) { warnings.push('ERR ' + a.join(' ')); }, warn(...a) { warnings.push(a.join(' ')); }, debug() {} };
  w.scrollTo = () => {};

  w.scolta = {
    scoring: config,
    endpoints: { expand: '/e/expand', summarize: '/e/summarize', followup: '/e/followup' },
    pagefindPath: pathToFileURL(PF_DIR + 'pagefind.js').href,
    wasmPath: pathToFileURL(WASM_GLUE).href,
    siteName: 'Counting Down Apollo',
    container: '#scolta-search',
    allowedLinkDomains: [], disclaimer: '', currentLanguage: 'en',
  };

  w.eval(scoltaSrc);
  w.Scolta.init('#scolta-search');
  for (let i = 0; i < 20; i++) await tick(0); // let init + preload settle

  const input = w.document.querySelector('#scolta-query');
  input.value = queryText;
  await w.Scolta.doSearch();

  // Stabilize: expansion merges asynchronously via expandPromise.then(...).
  const readTitles = () => [...w.document.querySelectorAll('#scolta-results .scolta-result-title')].map(a => a.textContent.trim());
  let prev = null, stable = 0;
  for (let i = 0; i < 400 && stable < 6; i++) {
    await tick(3);
    const cur = readTitles().join('');
    if (cur === prev && cur !== '') stable++; else { stable = 0; prev = cur; }
  }
  const titles = readTitles();
  if (process.env.DBG_SCORES && (!process.env.DBG_QUERY || process.env.DBG_QUERY === queryText)) {
    const dbg = w.__dbgScores || [];
    const fmt = (r, i) => `  ${String(i + 1).padStart(2)}. tot ${r.s.toFixed(3)}`
      + `  base ${r.base == null ? '   -  ' : r.base.toFixed(3)}`
      + `  bonus ${r.bonus == null ? '   -  ' : r.bonus.toFixed(3)}`
      + `  nAgree ${r.n == null ? '-' : r.n}  ${r.t}`;
    const terms = w.__dbgTerms || [];
    if (terms.length) {
      console.log(`\n--- terms: ${queryText} ---`);
      for (const t of terms) {
        console.log(`   spec ${Number(t.sp).toFixed(3)}  ${t.typed ? 'TYPED    ' : 'expansion'}  ${t.t}`);
      }
    }
    const pre = w.__dbgPre || [];
    if (pre.length) {
      console.log(`\n--- path: ${JSON.stringify(w.__dbgPath)}`);
      console.log(`--- pre-expansion (primary/OR) top 10: ${queryText} ---`);
      pre.slice(0, 10).forEach((r, i) => console.log(`  ${String(i + 1).padStart(2)}. ${r.s.toFixed(3)}  ${r.t}`));
    }
    console.log(`\n--- scores: ${queryText} ---`);
    dbg.slice(0, 16).forEach((r, i) => console.log(fmt(r, i)));
    for (const needle of (process.env.DBG_FIND || '').split('|').filter(Boolean)) {
      const hit = dbg.findIndex(r => norm(r.t).includes(norm(needle)));
      console.log(`  find "${needle}": ${hit < 0 ? 'ABSENT' : fmt(dbg[hit], hit).trim()}`);
    }
  }
  const headerText = (w.document.querySelector('#scolta-results-header')?.textContent) || '';
  const m = headerText.replace(/,/g, '').match(/([\d]+)\s+results?/i);
  const count = m ? parseInt(m[1], 10) : titles.length;
  dom.window.close();
  return { titles, count, warnings };
}

// ---------------------------------------------------------------------------
function parseArgs() {
  const args = process.argv.slice(2);
  const out = { top: 0, verbose: false, config: {} };
  for (const a of args) {
    if (a === '-v' || a === '--verbose') out.verbose = true;
    else if (a.startsWith('--top=')) { out.top = parseInt(a.slice(6), 10) || 12; out.verbose = true; }
    else if (a === '--top') { out.top = 12; out.verbose = true; }
    else if (a.startsWith('--config=')) {
      for (const kv of a.slice(9).split(',')) {
        const [k, v] = kv.split('=');
        if (!k) continue;
        out.config[k.trim()] = /^-?\d+(\.\d+)?$/.test(v) ? parseFloat(v) : (v === 'true' ? true : v === 'false' ? false : v);
      }
    }
  }
  return out;
}

async function main() {
  const opts = parseArgs();
  const config = { ...BASE_CONFIG, ...opts.config };
  if (Object.keys(opts.config).length) console.log('Config overrides:', JSON.stringify(opts.config));

  const pf = await loadPagefind();
  const wasm = loadWasm();
  const scoltaSrc = patchedScolta();

  let mustTotal = 0, mustPass = 0, betterTotal = 0, betterPass = 0, rowsGreen = 0;
  const lines = [];
  for (const row of ACCEPTANCE) {
    const { titles, count, warnings } = await runQuery(row.query, config, pf, wasm, scoltaSrc);
    const mustResults = row.must.map(([name, fn]) => [name, !!fn(titles, count)]);
    const betterResults = row.better.map(([name, fn]) => [name, !!fn(titles, count)]);
    const rowGreen = mustResults.every(([, ok]) => ok);
    if (rowGreen) rowsGreen++;
    mustTotal += mustResults.length; mustPass += mustResults.filter(([, ok]) => ok).length;
    betterTotal += betterResults.length; betterPass += betterResults.filter(([, ok]) => ok).length;

    lines.push(`\n${rowGreen ? '✅' : '❌'} ${row.query}  (${count} results)`);
    for (const [name, ok] of mustResults) lines.push(`   ${ok ? 'PASS' : 'FAIL'}  must: ${name}`);
    for (const [name, ok] of betterResults) lines.push(`   ${ok ? ' +  ' : ' -  '}  better: ${name}`);
    const badWarn = warnings.filter(x => /fail|missed|error/i.test(x));
    if (badWarn.length) lines.push(`   ⚠ warnings: ${badWarn.slice(0, 3).join(' | ')}`);
    if (opts.verbose) {
      const n = opts.top || 12;
      titles.slice(0, n).forEach((t, i) => lines.push(`      ${String(i + 1).padStart(2)}. ${t}`));
    }
  }
  console.log(lines.join('\n'));
  console.log('\n' + '='.repeat(60));
  console.log(`SCORECARD: ${rowsGreen}/${ACCEPTANCE.length} rows green | must ${mustPass}/${mustTotal} | better ${betterPass}/${betterTotal}`);
  console.log('='.repeat(60));
  process.exit(rowsGreen === ACCEPTANCE.length ? 0 : 1);
}

main().catch(e => { console.error(e); process.exit(3); });
