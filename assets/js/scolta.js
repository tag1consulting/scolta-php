/**
 * Scolta — AI-powered search with Pagefind integration.
 *
 * All site-specific references removed. Configuration is read from
 * window.scolta, which the host page must set before loading this script.
 *
 * Required window.scolta properties:
 *   scoring: { ... }        — Scoring parameters (see CONFIG below for keys)
 *   endpoints: {             — API endpoint paths
 *     expand: '/api/scolta/v1/expand-query',
 *     summarize: '/api/scolta/v1/summarize',
 *     followup: '/api/scolta/v1/followup',
 *   }
 *   pagefindPath: '/pagefind/pagefind.js'  — Path to Pagefind JS
 *   siteName: 'My Site'                    — Display name for the site
 *   container: '#scolta-search'            — CSS selector for the search container
 *   allowedLinkDomains: []                 — Domains allowed in summary links (empty = all)
 *   disclaimer: ''                         — Disclaimer text below AI summary (empty = none)
 *
 * Entry point: Scolta.init(containerSelector)
 *
 * SCORING ALGORITHM: Preserved exactly from the original implementation.
 *   - Recency decay: exponential boost for new content, penalty for old
 *   - Title match boost: word-boundary matching, all-terms multiplier
 *   - Content match boost: word-boundary matching against excerpt
 *   - Expanded-term weight decay: 0.7 → 0.65 → 0.60 → ... min 0.4
 *   - Jaccard deduplication: 0.7 threshold on title word overlap
 *   - OR fallback: if AND search returns <5 results, search each term individually
 *   - Parallel data loading: all .data() calls across all searches in one Promise.all()
 *   - Dual scoring: expanded results scored vs source term AND original query, higher wins
 */

(function (global) {
  'use strict';

  // ==========================================================================
  // CONFIGURATION — read from window.scolta.scoring, with defaults matching
  // the original implementation exactly.
  // ==========================================================================
  function getConfig() {
    const s = (global.scolta && global.scolta.scoring) || {};
    return {
      // Recency scoring
      RECENCY_BOOST_MAX: s.RECENCY_BOOST_MAX ?? 0.5,
      RECENCY_HALF_LIFE_DAYS: s.RECENCY_HALF_LIFE_DAYS ?? 365,
      RECENCY_PENALTY_AFTER_DAYS: s.RECENCY_PENALTY_AFTER_DAYS ?? 1825,
      RECENCY_MAX_PENALTY: s.RECENCY_MAX_PENALTY ?? 0.3,

      // Title/content match scoring
      TITLE_MATCH_BOOST: s.TITLE_MATCH_BOOST ?? 1.0,
      TITLE_ALL_TERMS_MULTIPLIER: s.TITLE_ALL_TERMS_MULTIPLIER ?? 1.5,
      CONTENT_MATCH_BOOST: s.CONTENT_MATCH_BOOST ?? 0.4,

      // Display
      EXCERPT_LENGTH: s.EXCERPT_LENGTH ?? 300,
      RESULTS_PER_PAGE: s.RESULTS_PER_PAGE ?? 10,
      MAX_PAGEFIND_RESULTS: s.MAX_PAGEFIND_RESULTS ?? 50,

      // AI features
      AI_EXPAND_QUERY: s.AI_EXPAND_QUERY ?? true,
      AI_SUMMARIZE: s.AI_SUMMARIZE ?? true,
      AI_SUMMARY_TOP_N: s.AI_SUMMARY_TOP_N ?? 5,
      AI_SUMMARY_MAX_CHARS: s.AI_SUMMARY_MAX_CHARS ?? 2000,
      EXPAND_PRIMARY_WEIGHT: s.EXPAND_PRIMARY_WEIGHT ?? 0.7,
      AI_MAX_FOLLOWUPS: s.AI_MAX_FOLLOWUPS ?? 3,
    };
  }

  function getEndpoints() {
    const e = (global.scolta && global.scolta.endpoints) || {};
    return {
      expand: e.expand || '/api/scolta/v1/expand-query',
      summarize: e.summarize || '/api/scolta/v1/summarize',
      followup: e.followup || '/api/scolta/v1/followup',
    };
  }

  function getSiteName() {
    return (global.scolta && global.scolta.siteName) || 'this site';
  }

  function getAllowedLinkDomains() {
    return (global.scolta && global.scolta.allowedLinkDomains) || [];
  }

  function getDisclaimer() {
    return (global.scolta && global.scolta.disclaimer) || '';
  }

  // ==========================================================================
  // STOPWORDS — filter before Pagefind search and LLM expansion.
  // Ported from tag1.com search. Pagefind ANDs all query words, so "who is
  // Loreen Babcock" fails because pages rarely contain "who" + "is" + both
  // name words. Stripping stopwords turns it into "Loreen Babcock" which works.
  // ==========================================================================
  const STOPWORDS = new Set([
    // Articles
    'a', 'an', 'the',
    // Personal pronouns
    'i', 'me', 'my', 'myself', 'mine', 'we', 'us', 'our', 'ours', 'ourselves',
    'you', 'your', 'yours', 'yourself', 'yourselves',
    'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself',
    'it', 'its', 'itself', 'they', 'them', 'their', 'theirs', 'themselves',
    'one', 'ones',
    // Demonstrative & relative pronouns
    'this', 'that', 'these', 'those', 'who', 'whom', 'whose', 'which', 'what',
    // Prepositions
    'about', 'above', 'across', 'after', 'against', 'along', 'among', 'around',
    'at', 'before', 'behind', 'below', 'beneath', 'beside', 'besides', 'between',
    'beyond', 'by', 'despite', 'down', 'during', 'except', 'for', 'from',
    'in', 'inside', 'into', 'like', 'near', 'of', 'off', 'on', 'onto',
    'out', 'outside', 'over', 'past', 'per', 'since', 'through', 'throughout',
    'to', 'toward', 'towards', 'under', 'underneath', 'until', 'up', 'upon',
    'with', 'within', 'without',
    // Conjunctions
    'and', 'but', 'or', 'nor', 'so', 'yet', 'both', 'either', 'neither',
    'although', 'because', 'however', 'if', 'once', 'than',
    'though', 'unless', 'when', 'whenever', 'where', 'wherever', 'while', 'whether',
    // Auxiliary & modal verbs
    'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
    'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing', 'done',
    'will', 'would', 'shall', 'should', 'can', 'could', 'may', 'might', 'must', 'ought',
    // Contractions (punctuation-stripped)
    'dont', 'doesnt', 'didnt', 'isnt', 'arent', 'wasnt', 'werent',
    'wont', 'wouldnt', 'shouldnt', 'couldnt', 'cant', 'cannot',
    'hasnt', 'havent', 'hadnt', 'mustnt',
    'im', 'ive', 'ill', 'youre', 'youve', 'youd', 'youll',
    'hes', 'shes', 'weve', 'theyre', 'theyve', 'theyd', 'theyll',
    'whats', 'whos', 'thats', 'theres', 'heres', 'lets',
    // Adverbs & degree words
    'also', 'always', 'ever', 'here', 'there', 'how', 'just',
    'never', 'now', 'often', 'only', 'quite', 'really',
    'still', 'then', 'too', 'very', 'well', 'already',
    'almost', 'even', 'much', 'rather', 'again', 'perhaps',
    'anyway', 'anymore', 'elsewhere', 'everywhere', 'somehow', 'why',
    // Determiners & quantifiers
    'all', 'another', 'any', 'each', 'every', 'few', 'many',
    'more', 'most', 'no', 'none', 'not', 'other', 'others',
    'own', 'same', 'several', 'some', 'such', 'enough',
    // Query-intent verbs (meta-language, not what users seek)
    'find', 'finding', 'found', 'need', 'needs', 'needed', 'needing',
    'want', 'wants', 'wanted', 'wanting', 'look', 'looking', 'looked', 'looks',
    'search', 'searching', 'searched', 'show', 'showing', 'shown', 'shows',
    'tell', 'telling', 'told', 'tells', 'give', 'giving', 'gave', 'given', 'gives',
    'help', 'helping', 'helped', 'helps', 'know', 'knowing', 'knew', 'known', 'knows',
    'see', 'seeing', 'saw', 'seen', 'sees', 'try', 'trying', 'tried', 'tries',
    'ask', 'asking', 'asked', 'asks', 'think', 'thinking', 'thought', 'thinks',
    'seem', 'seems', 'seemed', 'seeming', 'say', 'saying', 'said', 'says',
    // Common filler & function words
    'able', 'ago', 'away', 'back', 'else', 'far', 'got', 'gonna', 'gotta',
    'hence', 'hereby', 'herein', 'instead', 'merely', 'please', 'regarding',
    'therefore', 'thus', 'via', 'vs', 'whereas', 'whereby', 'wherein',
    'whatever', 'whichever', 'whoever', 'yes', 'ok', 'okay',
  ]);

  // Extract meaningful search terms from a query (filter stopwords).
  // "who is Loreen Babcock" → ["loreen", "babcock"]
  // If everything is filtered, fall back to words longer than 2 chars.
  function extractSearchTerms(query) {
    const words = query.toLowerCase().split(/\s+/).filter(w => w.length > 0);
    const meaningful = words
      .map(w => w.replace(/[^\w]/g, ''))
      .filter(w => !STOPWORDS.has(w) && w.length > 1);
    if (meaningful.length === 0) {
      return words.filter(w => w.length > 2);
    }
    return meaningful;
  }

  // ==========================================================================
  // SEARCH ENGINE
  // ==========================================================================
  let pagefind = null;
  let allScoredResults = [];
  let displayedCount = 0;
  let activeFilters = new Set();
  let conversationMessages = [];
  let followUpCount = 0;
  let abortController = null;
  let filterCounts = {};
  let currentQuery = "";
  let allHighlightTerms = [];
  let lastExpandedTerms = null;
  let searchVersion = 0;

  // --- DOM references (set during init) ---
  let els = {};

  // Initialize Pagefind and preload the WASM index.
  async function initPagefind() {
    const pagefindPath = (global.scolta && global.scolta.pagefindPath) || '/pagefind/pagefind.js';
    pagefind = await import(pagefindPath);
    await pagefind.init();
    // Warm the index: triggers WASM compilation + fragment download.
    await pagefind.search("");
    console.log("[scolta] Pagefind index preloaded");
  }

  // --- Scoring functions (preserved exactly from original) ---

  function recencyScore(dateStr) {
    const CONFIG = getConfig();
    if (!dateStr) return 0;
    try {
      const contentDate = new Date(dateStr);
      if (isNaN(contentDate.getTime())) return 0;
      const now = new Date();
      const ageDays = (now - contentDate) / (1000 * 60 * 60 * 24);

      if (ageDays < CONFIG.RECENCY_PENALTY_AFTER_DAYS) {
        // Exponential decay boost for newer content
        return CONFIG.RECENCY_BOOST_MAX *
          Math.exp(-ageDays / CONFIG.RECENCY_HALF_LIFE_DAYS * Math.LN2);
      }
      // Penalty for very old content
      const yearsOver = (ageDays - CONFIG.RECENCY_PENALTY_AFTER_DAYS) / 365;
      return -Math.min(CONFIG.RECENCY_MAX_PENALTY, yearsOver * 0.05);
    } catch { return 0; }
  }

  function titleMatchScore(title, query) {
    const CONFIG = getConfig();
    if (!title || !query) return 0;
    const titleLower = title.toLowerCase();
    const terms = query.toLowerCase().split(/\s+/).filter(t => t.length > 2);
    if (terms.length === 0) return 0;

    let matchCount = 0;
    for (const term of terms) {
      // Full-word boundary match (both sides) — "skin" must not boost "Skinner"
      const regex = new RegExp(`\\b${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, "i");
      if (regex.test(titleLower)) matchCount++;
    }

    if (matchCount === 0) return 0;
    let boost = CONFIG.TITLE_MATCH_BOOST;
    // Bonus when ALL search terms appear in title
    if (matchCount === terms.length && terms.length > 1) {
      boost *= CONFIG.TITLE_ALL_TERMS_MULTIPLIER;
    }
    return boost * (matchCount / terms.length);
  }

  function contentMatchScore(excerpt, query) {
    const CONFIG = getConfig();
    if (!excerpt || !query) return 0;
    const terms = query.toLowerCase().split(/\s+/).filter(t => t.length > 2);
    if (terms.length === 0) return 0;
    const excerptLower = excerpt.toLowerCase();

    let matchCount = 0;
    for (const term of terms) {
      const regex = new RegExp(`\\b${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, "i");
      if (regex.test(excerptLower)) matchCount++;
    }
    if (matchCount === 0) return 0;
    return CONFIG.CONTENT_MATCH_BOOST * (matchCount / terms.length);
  }

  // --- AI features ---

  async function expandQuery(query) {
    const CONFIG = getConfig();
    const endpoints = getEndpoints();
    if (!CONFIG.AI_EXPAND_QUERY) return null;
    try {
      const resp = await fetch(endpoints.expand, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ query }),
        signal: abortController?.signal,
      });
      console.log("[scolta:expand] status:", resp.status);
      if (!resp.ok) {
        const errText = await resp.text();
        console.warn("[scolta:expand] error response:", errText);
        return null;
      }
      const data = await resp.json();
      console.log("[scolta:expand] response:", data);
      const terms = Array.isArray(data) ? data : (Array.isArray(data?.terms) ? data.terms : null);
      return terms;
    } catch (e) {
      if (e.name === 'AbortError') return null;
      console.warn("[scolta:expand] failed:", e);
      return null;
    }
  }

  async function summarizeResults(query, results, expandedTerms = []) {
    const CONFIG = getConfig();
    const endpoints = getEndpoints();
    if (!CONFIG.AI_SUMMARIZE || results.length === 0) return null;
    const summaryEl = els.aiSummary;
    summaryEl.style.display = "block";
    summaryEl.className = "scolta-ai-summary loading";
    summaryEl.innerHTML = `
      <div class="scolta-ai-summary-label">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
        <span>AI Overview</span>
        <span class="scolta-ai-dots"><span>.</span><span>.</span><span>.</span></span>
      </div>
      <div class="scolta-ai-summary-text">
        <div class="scolta-ai-shimmer" style="width:95%"></div>
        <div class="scolta-ai-shimmer" style="width:88%"></div>
        <div class="scolta-ai-shimmer" style="width:72%"></div>
      </div>`;

    const topN = results.slice(0, CONFIG.AI_SUMMARY_TOP_N);
    const context = buildLLMContext(topN);

    try {
      const fullQuery = expandedTerms.length > 0
        ? `${query} (also searched: ${expandedTerms.join(', ')})`
        : query;

      const resp = await fetch(endpoints.summarize, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ query: fullQuery, context }),
        signal: abortController?.signal,
      });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      if (data.summary) {
        summaryEl.className = "scolta-ai-summary";
        const formatted = formatSummary(data.summary);

        const userContext = `Search query: ${fullQuery}\n\nSearch result excerpts:\n${context}`;
        conversationMessages = [
          { role: 'user', content: userContext },
          { role: 'assistant', content: data.summary },
        ];

        const disclaimer = getDisclaimer();
        const disclaimerHtml = disclaimer
          ? `<div class="scolta-ai-summary-disclaimer">${escapeHtml(disclaimer)}</div>`
          : '';

        summaryEl.innerHTML = `
          <div class="scolta-ai-summary-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
            <span>AI Overview</span>
          </div>
          <div class="scolta-ai-summary-text">${formatted}</div>
          <div id="scolta-followup-thread" class="scolta-ai-followup-thread" style="display:none;"></div>
          <div class="scolta-ai-followup-input" id="scolta-followup-input">
            <input type="text" id="scolta-followup-field" placeholder="Ask a follow-up question..."
                   onkeydown="if(event.key==='Enter')Scolta.submitFollowUp()">
            <button onclick="Scolta.submitFollowUp()" id="scolta-followup-btn">Ask</button>
            <span class="scolta-ai-followup-counter" id="scolta-followup-counter">${CONFIG.AI_MAX_FOLLOWUPS} remaining</span>
          </div>
          ${disclaimerHtml}`;
      } else {
        summaryEl.style.display = "none";
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
      console.warn("[scolta:summarize] failed:", e);
      summaryEl.className = "scolta-ai-summary error";
      summaryEl.innerHTML = `<div class="scolta-ai-summary-label">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
          <span>AI Overview</span>
        </div>
        <div class="scolta-ai-summary-text">Summary unavailable. Results shown below.</div>`;
    }
  }

  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  function stripHtml(text) {
    return text.replace(/<[^>]*>/g, "");
  }

  // Build LLM context string from an array of scored results.
  // Top 2 results get full page content for depth; remaining get excerpts.
  function buildLLMContext(results) {
    const CONFIG = getConfig();
    return results.map((r, i) => {
      const title = r.data.meta?.title || "Untitled";
      const url = r.data.meta?.url || "";
      const useFullContent = i < 2;
      const text = useFullContent
        ? stripHtml(r.data.content || r.data.excerpt || "")
        : stripHtml(r.data.excerpt || "");
      const trimmed = text.substring(0, CONFIG.AI_SUMMARY_MAX_CHARS);
      return `[${i + 1}] ${title}\n${url}\n${trimmed}`;
    }).join("\n\n");
  }

  // Convert lightweight markdown from Claude's summary into safe HTML.
  function formatSummary(text) {
    const escaped = escapeHtml(text);
    const lines = escaped.split('\n');
    let html = '';
    let inList = false;

    for (const line of lines) {
      const trimmed = line.trim();
      if (trimmed === '') {
        if (inList) { html += '</ul>'; inList = false; }
        continue;
      }
      if (trimmed.startsWith('- ')) {
        if (!inList) { html += '<ul>'; inList = true; }
        html += `<li>${formatInline(trimmed.substring(2))}</li>`;
      } else {
        if (inList) { html += '</ul>'; inList = false; }
        html += `<p>${formatInline(trimmed)}</p>`;
      }
    }
    if (inList) html += '</ul>';
    return html;
  }

  // Apply inline formatting: **bold** and [text](url) links.
  // Links are only rendered if the URL matches allowedLinkDomains
  // (empty list = allow all links).
  function formatInline(text) {
    const allowedDomains = getAllowedLinkDomains();
    return text
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
        if (allowedDomains.length === 0) {
          return `<a href="${url}" target="_blank" rel="noopener">${linkText}</a>`;
        }
        try {
          const parsed = new URL(url);
          const host = parsed.hostname.replace(/^www\./, '');
          if (allowedDomains.some(d => host === d || host.endsWith('.' + d))) {
            return `<a href="${url}" target="_blank" rel="noopener">${linkText}</a>`;
          }
        } catch {}
        // Non-allowed or invalid URL — show text only, no link
        return linkText;
      });
  }

  // --- Follow-up conversation ---

  async function searchForFollowUpContext(question) {
    if (!pagefind) return '';
    const terms = extractSearchTerms(question);
    const searchQuery = terms.length > 0 ? terms.join(' ') : question;
    try {
      const search = await pagefindSearch(searchQuery, new Set());
      const toLoad = Math.min(search.results.length, 20);
      if (toLoad === 0) return '';
      const loaded = await Promise.all(
        search.results.slice(0, toLoad).map(r => r.data())
      );
      const scored = scoreResults(loaded, searchQuery, 1.0);
      scored.sort((a, b) => b.score - a.score);
      const best = scored.slice(0, 5);
      const context = buildLLMContext(best);
      console.log(`[scolta:followup] Found ${best.length} additional results for: ${searchQuery} (from ${toLoad} candidates)`);
      return context;
    } catch (e) {
      console.warn("[scolta:followup] context search failed:", e);
      return '';
    }
  }

  function updateFollowUpCounter(remaining) {
    const CONFIG = getConfig();
    const counter = document.getElementById("scolta-followup-counter");
    if (counter) counter.textContent = `${remaining} remaining`;

    if (remaining <= 0) {
      followUpCount = CONFIG.AI_MAX_FOLLOWUPS;
      const inputEl = document.getElementById("scolta-followup-input");
      if (inputEl) {
        inputEl.innerHTML = '<span class="scolta-ai-followup-counter" style="width:100%;text-align:center;">Follow-up limit reached. Start a new search to ask more questions.</span>';
      }
    }
  }

  async function submitFollowUp() {
    const CONFIG = getConfig();
    const endpoints = getEndpoints();
    const input = document.getElementById("scolta-followup-field");
    const btn = document.getElementById("scolta-followup-btn");
    const question = input.value.trim();
    if (!question || conversationMessages.length === 0) return;
    if (followUpCount >= CONFIG.AI_MAX_FOLLOWUPS) return;

    input.disabled = true;
    btn.disabled = true;
    input.value = '';

    const threadEl = document.getElementById("scolta-followup-thread");
    threadEl.style.display = "block";
    const turnEl = document.createElement("div");
    turnEl.className = "scolta-ai-followup-turn";
    turnEl.innerHTML = `<div class="scolta-ai-followup-question">${escapeHtml(question)}</div>
      <div class="scolta-ai-followup-answer"><span class="scolta-ai-dots"><span>.</span><span>.</span><span>.</span></span></div>`;
    threadEl.appendChild(turnEl);
    turnEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    const extraContext = await searchForFollowUpContext(question);
    const userMessage = extraContext
      ? `${question}\n\nAdditional search results for this follow-up:\n${extraContext}`
      : question;

    conversationMessages.push({ role: 'user', content: userMessage });

    try {
      const resp = await fetch(endpoints.followup, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ messages: conversationMessages }),
      });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();

      if (data.response) {
        conversationMessages.push({ role: 'assistant', content: data.response });
        turnEl.querySelector(".scolta-ai-followup-answer").innerHTML = formatSummary(data.response);
        const remaining = data.remaining ?? (CONFIG.AI_MAX_FOLLOWUPS - followUpCount - 1);
        followUpCount++;
        updateFollowUpCounter(remaining);
      } else {
        turnEl.querySelector(".scolta-ai-followup-answer").textContent = "No response available.";
      }
    } catch (e) {
      console.warn("[scolta:followup] failed:", e);
      if (e.message && e.message.includes('429')) {
        turnEl.querySelector(".scolta-ai-followup-answer").textContent = "Follow-up limit reached.";
        updateFollowUpCounter(0);
      } else {
        turnEl.querySelector(".scolta-ai-followup-answer").textContent = "Follow-up unavailable. Please try again.";
        conversationMessages.pop();
      }
    }

    if (followUpCount < CONFIG.AI_MAX_FOLLOWUPS) {
      input.disabled = false;
      btn.disabled = false;
      input.focus();
    }
  }

  function renderExpandedTerms(terms, originalQuery) {
    const container = els.expandedTerms;
    if (!terms || terms.length === 0) {
      container.style.display = "none";
      return;
    }
    const filtered = terms.filter(t => t.toLowerCase() !== originalQuery.toLowerCase());
    if (filtered.length === 0) {
      container.style.display = "none";
      return;
    }
    container.style.display = "flex";
    container.innerHTML = '<span style="font-size:0.8rem;color:#666;margin-right:0.2rem;">Also try:</span>' +
      filtered
        .map(t => `<span class="scolta-expanded-term" onclick="Scolta.searchTerm('${t.replace(/'/g, "\\'")}')">${escapeHtml(t)}</span>`)
        .join("");
  }

  function searchTerm(term) {
    els.queryInput.value = term;
    doSearch();
  }

  // --- Pagefind search helper ---

  async function pagefindSearch(query, filters) {
    const searchOpts = {};
    if (filters && filters.size > 0) {
      const arr = [...filters];
      searchOpts.filters = {
        site: arr.length === 1 ? arr[0] : arr
      };
    }
    return pagefind.search(query, searchOpts);
  }

  // Score a set of loaded results against a query.
  function scoreResults(loaded, query, sourceWeight) {
    const count = loaded.length;
    return loaded.map((data, i) => {
      const pagefindScore = count > 1 ? 1 - (i / (count - 1)) : 1;
      const recency = recencyScore(data.meta?.date);
      const titleBoost = titleMatchScore(data.meta?.title, query);
      const contentBoost = contentMatchScore(data.excerpt, query);
      const finalScore = (pagefindScore + recency + titleBoost + contentBoost) * sourceWeight;
      return { data, score: finalScore };
    });
  }

  // Compute facet counts from actual result set.
  function computeFilterCounts(results) {
    const counts = {};
    for (const r of results) {
      const site = r.data.meta?.site;
      if (site) {
        counts[site] = (counts[site] || 0) + 1;
      }
    }
    return counts;
  }

  // Deduplicate results with near-identical titles using Jaccard similarity.
  // Run AFTER sorting — keeps the higher-scored result for each cluster.
  function deduplicateByTitle(results) {
    const kept = [];
    const seenTitles = [];

    for (const r of results) {
      const title = (r.data.meta?.title || '').toLowerCase();
      const base = title.split('|')[0].trim();
      const words = new Set(base.replace(/[^\w\s]/g, '').split(/\s+/).filter(w => w.length > 2));

      if (words.size === 0) {
        kept.push(r);
        continue;
      }

      // Check against all kept titles for high overlap (Jaccard >= 0.7)
      let isDuplicate = false;
      for (const seen of seenTitles) {
        const intersection = [...words].filter(w => seen.words.has(w)).length;
        const union = new Set([...words, ...seen.words]).size;
        if (union > 0 && intersection / union >= 0.7) {
          isDuplicate = true;
          break;
        }
      }

      if (!isDuplicate) {
        seenTitles.push({ words });
        kept.push(r);
      }
    }

    if (kept.length < results.length) {
      console.log(`[scolta:dedup] Removed ${results.length - kept.length} near-duplicate titles`);
    }
    return kept;
  }

  // Merge scored results, keeping highest score per URL.
  function mergeResults(currentResults, newResults) {
    const urlMap = new Map();
    for (const r of currentResults) {
      const url = r.data.meta?.url || r.data.url || '';
      const prev = urlMap.get(url);
      if (!prev || r.score > prev.score) {
        urlMap.set(url, r);
      }
    }
    for (const r of newResults) {
      const url = r.data.meta?.url || r.data.url || '';
      const prev = urlMap.get(url);
      if (!prev || r.score > prev.score) {
        urlMap.set(url, r);
      }
    }
    return [...urlMap.values()];
  }

  // ==========================================================================
  // SHARED SEARCH HELPERS
  // ==========================================================================

  async function loadAndScoreSearch(search, query, weight) {
    const CONFIG = getConfig();
    const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
    if (toLoad === 0) return [];
    const loaded = await Promise.all(
      search.results.slice(0, toLoad).map(r => r.data())
    );
    return scoreResults(loaded, query, weight);
  }

  async function searchAndLoadParallel(queries, filters, originalQuery) {
    const CONFIG = getConfig();
    if (queries.length === 0) return [];

    const searches = await Promise.all(
      queries.map(q => pagefindSearch(q.term, filters))
    );

    const loadPromises = [];
    for (let i = 0; i < searches.length; i++) {
      const search = searches[i];
      const { term, weight } = queries[i];
      const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
      for (let j = 0; j < toLoad; j++) {
        loadPromises.push(
          search.results[j].data().then(data => ({ data, term, weight }))
        );
      }
    }
    const allLoaded = await Promise.all(loadPromises);

    const byTerm = new Map();
    for (const item of allLoaded) {
      if (!byTerm.has(item.term)) byTerm.set(item.term, []);
      byTerm.get(item.term).push(item);
    }

    let results = [];
    for (const [term, items] of byTerm) {
      const weight = items[0].weight;
      const loaded = items.map(i => i.data);
      const scoredVsTerm = scoreResults(loaded, term, weight);
      const scoredVsOriginal = scoreResults(loaded, originalQuery, weight * 0.5);

      const best = scoredVsTerm.map((r, idx) => ({
        data: r.data,
        score: Math.max(r.score, scoredVsOriginal[idx].score),
      }));
      results = mergeResults(results, best);
    }

    return results;
  }

  async function mergeExpandedSearchResults(expandedTerms, originalQuery, searchQuery, preserveFilters, version) {
    const CONFIG = getConfig();
    if (!expandedTerms || expandedTerms.length === 0) return;

    const validTerms = expandedTerms.filter(
      t => t.toLowerCase() !== originalQuery.toLowerCase()
    );
    if (validTerms.length === 0) return;

    if (version !== searchVersion) {
      console.log('[scolta:expand] Discarding stale expansion (version', version, 'vs current', searchVersion, ')');
      return;
    }

    for (const term of validTerms) {
      for (const word of term.toLowerCase().split(/\s+/)) {
        if (word.length > 2 && !allHighlightTerms.includes(word)) {
          allHighlightTerms.push(word);
        }
      }
    }

    const queries = [];
    let weightIndex = 0;
    for (const term of validTerms) {
      const weight = Math.max(CONFIG.EXPAND_PRIMARY_WEIGHT - (weightIndex * 0.05), 0.4);
      queries.push({ term, weight });
      weightIndex++;

      const words = extractSearchTerms(term);
      if (words.length > 1) {
        for (const word of words) {
          if (word.length > 2 && !queries.some(q => q.term === word)) {
            const wordWeight = Math.max(CONFIG.EXPAND_PRIMARY_WEIGHT - (weightIndex * 0.05), 0.4);
            queries.push({ term: word, weight: wordWeight });
            weightIndex++;
          }
        }
      }
    }

    const expandedResults = await searchAndLoadParallel(queries, activeFilters, searchQuery);

    if (version !== searchVersion) {
      console.log('[scolta:expand] Discarding stale expansion after load (version', version, 'vs current', searchVersion, ')');
      return;
    }

    allScoredResults = mergeResults(allScoredResults, expandedResults);
    allScoredResults.sort((a, b) => b.score - a.score);
    allScoredResults = deduplicateByTitle(allScoredResults);
    displayedCount = 0;

    if (!preserveFilters) {
      filterCounts = computeFilterCounts(allScoredResults);
      renderFilters();
    }

    renderResults(true);
    console.log(`[scolta:expand] Merged ${allScoredResults.length} results from primary + ${validTerms.length} expanded terms`);
  }

  // --- Main search ---

  async function doSearch(preserveFilters) {
    preserveFilters = preserveFilters || false;
    const CONFIG = getConfig();
    const query = els.queryInput.value.trim();
    if (!query || !pagefind) return;

    const version = ++searchVersion;

    if (abortController) abortController.abort();
    abortController = new AbortController();

    currentQuery = query;
    displayedCount = 0;
    allScoredResults = [];
    conversationMessages = [];
    followUpCount = 0;
    if (!preserveFilters) {
      activeFilters.clear();
    }

    els.layout.style.display = "grid";
    els.results.innerHTML = '<p class="scolta-searching">Searching...</p>';
    els.resultsHeader.innerHTML = "";
    els.noResults.style.display = "none";
    els.aiSummary.style.display = "none";
    els.loadMore.style.display = "none";
    if (!preserveFilters) {
      els.expandedTerms.style.display = "none";
    }

    const meaningfulTerms = extractSearchTerms(query);
    const searchQuery = meaningfulTerms.length > 0 ? meaningfulTerms.join(' ') : query;
    console.log('[scolta:search] Filtered query:', JSON.stringify(searchQuery), '(original:', JSON.stringify(query), ')');

    allHighlightTerms = meaningfulTerms.length > 0
      ? meaningfulTerms.filter(t => t.length > 2)
      : query.toLowerCase().split(/\s+/).filter(t => t.length > 2);

    // Phase 1: Primary search — render results IMMEDIATELY
    const expandPromise = preserveFilters
      ? Promise.resolve(lastExpandedTerms)
      : expandQuery(query);

    const primarySearch = await pagefindSearch(searchQuery, activeFilters);
    allScoredResults = await loadAndScoreSearch(primarySearch, searchQuery, 1.0);

    // OR fallback
    if (meaningfulTerms.length > 1 && primarySearch.results.length < 5) {
      console.log('[scolta:search] AND returned', primarySearch.results.length, 'results — running OR fallback');
      const orQueries = meaningfulTerms.map(term => ({ term, weight: 0.6 }));
      const orResults = await searchAndLoadParallel(orQueries, activeFilters, searchQuery);
      allScoredResults = mergeResults(allScoredResults, orResults);
    }

    allScoredResults.sort((a, b) => b.score - a.score);
    allScoredResults = deduplicateByTitle(allScoredResults);

    if (!preserveFilters) {
      filterCounts = computeFilterCounts(allScoredResults);
    }

    renderFilters();
    renderResults();

    // Phase 2: Expanded searches — asynchronous merge
    expandPromise.then(expandedTerms => {
      if (!preserveFilters) {
        lastExpandedTerms = expandedTerms;
      }
      renderExpandedTerms(expandedTerms, query);
      mergeExpandedSearchResults(expandedTerms, query, searchQuery, preserveFilters, version);
    });

    // Phase 3: AI summarization
    const earlyExpandedTerms = lastExpandedTerms && !preserveFilters
      ? null
      : lastExpandedTerms;
    const expandedLabel = earlyExpandedTerms
      ? earlyExpandedTerms.filter(t => t.toLowerCase() !== query.toLowerCase())
      : [];
    summarizeResults(query, allScoredResults, expandedLabel);
  }

  function clearSearch() {
    if (abortController) abortController.abort();
    abortController = null;
    els.queryInput.value = '';
    els.searchClear.style.display = "none";
    els.layout.style.display = "none";
    els.expandedTerms.style.display = "none";
    els.aiSummary.style.display = "none";
    els.noResults.style.display = "none";
    allScoredResults = [];
    displayedCount = 0;
    conversationMessages = [];
    followUpCount = 0;
    activeFilters.clear();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    els.queryInput.focus();
  }

  // --- Filter handling ---

  function renderFilters() {
    const container = els.filters;
    const entries = Object.entries(filterCounts).sort((a, b) => b[1] - a[1]);
    if (entries.length === 0) { container.innerHTML = ""; return; }

    let html = "<h3>Site</h3>";
    for (const [site, count] of entries) {
      const isActive = activeFilters.has(site);
      const checked = isActive ? "checked" : "";
      const activeClass = isActive ? " active" : "";
      html += `<label class="scolta-filter-item${activeClass}">
        <input type="checkbox" value="${site}" ${checked}
               onchange="Scolta.toggleFilter('${site.replace(/'/g, "\\'")}')">
        ${site} <span class="scolta-filter-count">(${count})</span>
      </label>`;
    }
    container.innerHTML = html;
  }

  async function toggleFilter(site) {
    if (activeFilters.has(site)) {
      activeFilters.delete(site);
    } else {
      activeFilters.add(site);
    }
    await doSearch(true);
  }

  // --- Result rendering ---

  function highlightTerms(text) {
    if (!text || allHighlightTerms.length === 0) return text;
    let result = text;
    for (const term of allHighlightTerms) {
      const regex = new RegExp(`(${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, "gi");
      result = result.replace(regex, "<mark>$1</mark>");
    }
    return result;
  }

  function truncateExcerpt(text, maxLen) {
    const clean = escapeHtml(stripHtml(text));
    if (clean.length <= maxLen) return clean;
    const truncated = clean.substring(0, maxLen);
    const lastSpace = truncated.lastIndexOf(" ");
    return (lastSpace > maxLen * 0.8 ? truncated.substring(0, lastSpace) : truncated) + "\u2026";
  }

  function renderResults(isExpanded) {
    isExpanded = isExpanded || false;
    const CONFIG = getConfig();
    const container = els.results;
    const header = els.resultsHeader;
    const noResults = els.noResults;
    const loadMore = els.loadMore;

    const filtered = allScoredResults;

    if (filtered.length === 0) {
      container.innerHTML = "";
      header.innerHTML = "";
      noResults.style.display = "block";
      loadMore.style.display = "none";
      return;
    }

    noResults.style.display = "none";
    const showing = Math.min(displayedCount + CONFIG.RESULTS_PER_PAGE, filtered.length);
    const expandLabel = isExpanded ? ' (with expanded terms)' : '';
    const filterLabel = activeFilters.size > 0 ? ` in ${[...activeFilters].join(', ')}` : '';
    header.innerHTML = `<span>${filtered.length.toLocaleString()} results for "${escapeHtml(currentQuery)}"${filterLabel}${expandLabel}</span>
                        <span>Showing ${showing}</span>`;

    let html = "";
    for (let i = displayedCount; i < showing; i++) {
      const { data } = filtered[i];
      const title = data.meta?.title || "Untitled";
      const url = data.meta?.url || "#";
      const site = data.meta?.site || "";
      const date = data.meta?.date || "";
      const excerpt = truncateExcerpt(data.excerpt || "", CONFIG.EXCERPT_LENGTH);
      const highlighted = highlightTerms(excerpt);

      const safeTitle = escapeHtml(stripHtml(title));
      const displayTitle = safeTitle.length > 90 ? safeTitle.substring(0, 87) + "\u2026" : safeTitle;

      html += `<div class="scolta-result-card">
        <a class="scolta-result-title" href="${url}" target="_blank" rel="noopener"
           title="${safeTitle.replace(/"/g, '&quot;')}">${highlightTerms(displayTitle)}</a>
        <div class="scolta-result-meta">
          ${site ? `<span class="scolta-site-badge">${escapeHtml(site)}</span>` : ""}
          ${date ? `<span class="scolta-result-date">${escapeHtml(date)}</span>` : ""}
        </div>
        <a class="scolta-result-url" href="${url}" target="_blank" rel="noopener">${escapeHtml(url)}</a>
        <div class="scolta-result-excerpt">${highlighted}</div>
      </div>`;
    }

    if (displayedCount === 0) {
      container.innerHTML = html;
    } else {
      container.innerHTML += html;
    }
    displayedCount = showing;

    loadMore.style.display = (showing < filtered.length) ? "block" : "none";
  }

  function showMore() {
    renderResults(lastExpandedTerms && lastExpandedTerms.length > 0);
  }

  // ==========================================================================
  // PUBLIC API
  // ==========================================================================

  function init(containerSelector) {
    const root = document.querySelector(containerSelector || '#scolta-search');
    if (!root) {
      console.error('[scolta] Container not found:', containerSelector);
      return;
    }

    // Build the search UI inside the container.
    root.innerHTML = `
      <div class="scolta-search-box">
        <div class="scolta-search-input-wrap">
          <input type="text" id="scolta-query" placeholder="Search..."
                 autofocus autocomplete="off">
          <button class="scolta-search-clear" id="scolta-search-clear"
                  style="display:none;" aria-label="Clear search">&times;</button>
        </div>
        <button class="scolta-search-btn" id="scolta-search-btn">Search</button>
      </div>

      <div id="scolta-expanded-terms" class="scolta-expanded-terms" style="display:none;"></div>

      <div class="scolta-layout" id="scolta-layout" style="display:none;">
        <aside class="scolta-filters" id="scolta-filters"></aside>
        <div>
          <div id="scolta-ai-summary" style="display:none;"></div>
          <div class="scolta-results-header" id="scolta-results-header"></div>
          <div id="scolta-results"></div>
          <button class="scolta-load-more" id="scolta-load-more" style="display:none;">Show more results</button>
        </div>
      </div>

      <div class="scolta-no-results" id="scolta-no-results" style="display:none;">
        <p style="font-size:1.2rem;">No results found.</p>
        <p style="margin-top:0.5rem;">Try different keywords or clear your site filters.</p>
      </div>
    `;

    // Cache DOM references.
    els = {
      queryInput: root.querySelector('#scolta-query'),
      searchClear: root.querySelector('#scolta-search-clear'),
      searchBtn: root.querySelector('#scolta-search-btn'),
      expandedTerms: root.querySelector('#scolta-expanded-terms'),
      layout: root.querySelector('#scolta-layout'),
      filters: root.querySelector('#scolta-filters'),
      aiSummary: root.querySelector('#scolta-ai-summary'),
      resultsHeader: root.querySelector('#scolta-results-header'),
      results: root.querySelector('#scolta-results'),
      loadMore: root.querySelector('#scolta-load-more'),
      noResults: root.querySelector('#scolta-no-results'),
    };

    // Event listeners.
    els.queryInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") doSearch();
    });

    els.queryInput.addEventListener("input", () => {
      els.searchClear.style.display = els.queryInput.value.length > 0 ? "block" : "none";
    });

    els.searchClear.addEventListener("click", clearSearch);
    els.searchBtn.addEventListener("click", () => doSearch());
    els.loadMore.addEventListener("click", showMore);

    // Init Pagefind.
    initPagefind();

    console.log("[scolta] Initialized");
  }

  // Expose public API.
  global.Scolta = {
    init,
    searchTerm,
    submitFollowUp,
    toggleFilter,
    clearSearch,
    doSearch,
    showMore,
  };

})(typeof window !== 'undefined' ? window : this);
