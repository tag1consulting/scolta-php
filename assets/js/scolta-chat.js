/**
 * Scolta chat: a conversation grounded in the site's own pages.
 *
 * Reads window.scolta (the same object scolta.js reads) and does nothing
 * unless window.scolta.chat.enabled is true. Each turn ranks pages in the
 * browser with Scolta.createRetriever(), the search page's own pipeline,
 * posts them with the message, and streams the answer back from the server,
 * which keeps the thread. Rendering goes through Scolta.formatAnswer(), so
 * the model's Markdown is escaped and links stay on this site.
 *
 * window.scolta.chat (from ScoltaConfig::toBrowserConfig(), paths filled in
 * by the adapter):
 *   endpoints: { plan, turn, fold, thread }
 *   topResults, topChars, broadResults, broadChars  pages sent per turn
 *   pageContext, pageChars   send the relevant parts of the page being read
 *   handoff                  take over the search page's follow up box
 *   deepChatPath             URL of the vendored deep-chat bundle
 *   csrf: { header, tokenUrl }  optional, for signed in users
 *
 * Needs scolta.js loaded first. ES2020, no build step.
 */
(function (global) {
  'use strict';

  const doc = global.document;

  // Every visible string, overridable through window.scolta.labels.
  const LABEL_DEFAULTS = {
    chatLauncher: 'Ask a question',
    chatTitle: 'Ask about this site',
    chatPlaceholder: 'Ask a question',
    chatNewChat: 'New chat',
    chatClose: 'Close',
    chatSources: 'Sources',
    chatWorking: 'Working on it',
    chatReading: 'Reading the pages',
    chatError: "That didn't go through. Your message is back in the box, so you can send it again.",
    chatBusy: 'Lots of questions at once. Wait a moment, then send your message again.',
    chatUnavailable: "The chat didn't load. Try again in a moment.",
  };

  // A message made only of these words is a greeting or thanks: it names no
  // subject and needs no lookup. Answers like "yes", "ok" or "sure" are left
  // out on purpose, since they reply to the assistant and only the planner
  // can read them against the thread.
  const SMALL_TALK = new Set([
    'hi', 'hello', 'hey', 'hiya', 'there', 'thanks', 'thank', 'thx', 'cheers',
    'bye', 'goodbye', 'great', 'cool', 'nice', 'awesome', 'morning',
    'afternoon', 'evening', 'good', 'welcome', 'sorry', 'lol', 'perfect',
    'wonderful', 'appreciate', 'appreciated', 'you', 'so', 'much', 'very',
    'a', 'lot', 'that', 's', 'it', 'all', 'for', 'the', 'again',
  ]);

  // Page text that is never the page's content.
  const PAGE_IGNORE = 'nav, header, footer, form, aside, script, style, noscript, template, '
    + '[hidden], [aria-hidden="true"], [data-pagefind-ignore], [data-scolta-chat-ignore]';

  const PAGE_BLOCKS = 'p, div, section, article, li, dt, dd, h1, h2, h3, h4, h5, h6, td, th, blockquote, pre, br';

  // deep-chat renders inside a shadow root the page's CSS cannot reach.
  const SHADOW_STYLE = `
    .scolta-cite { font-size: 0.75em; line-height: 0; margin-left: 1px; }
    .scolta-cite a { text-decoration: none; padding: 0 0.2em; border-radius: 3px; background: rgba(0,0,0,0.07); }
    .scolta-chat-sources { margin-top: 0.6em; font-size: 0.9em; }
    .scolta-chat-sources summary { cursor: pointer; opacity: 0.8; }
    .scolta-chat-sources ul { margin: 0.4em 0 0; padding-left: 1.2em; }
    p { margin: 0 0 0.6em; }
    p:last-child { margin-bottom: 0; }
  `;

  function chatConfig() {
    return (global.scolta && global.scolta.chat) || null;
  }

  function labels() {
    const given = (global.scolta && global.scolta.labels) || {};
    const out = {};
    for (const key of Object.keys(LABEL_DEFAULTS)) {
      out[key] = (typeof given[key] === 'string' && given[key] !== '') ? given[key] : LABEL_DEFAULTS[key];
    }
    return out;
  }

  // Links in answers go only to this site.
  function ownHosts() {
    return [String(global.location.hostname || '').replace(/^www\./, '')];
  }

  // --- Server-sent events ---------------------------------------------------

  // Parse a text/event-stream body, calling onEvent(name, data) per event,
  // until the body ends or stop() says the rest is not wanted.
  async function readEvents(response, onEvent, stop) {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
      const { value, done } = await reader.read();
      if (stop()) {
        reader.cancel().catch(() => {});
        return;
      }
      buffer += done ? decoder.decode() : decoder.decode(value, { stream: true });
      let cut;
      while ((cut = buffer.indexOf('\n\n')) !== -1) {
        const block = buffer.slice(0, cut);
        buffer = buffer.slice(cut + 2);
        let name = 'message';
        let data = '';
        for (const line of block.split('\n')) {
          if (line.startsWith('event:')) name = line.slice(6).trim();
          else if (line.startsWith('data:')) data += line.slice(5).trim();
        }
        if (data !== '') onEvent(name, JSON.parse(data));
      }
      if (done) return;
    }
  }

  // --- The page being read --------------------------------------------------

  // The page's main content, without navigation, forms or anything marked to
  // be ignored. Null on a page with no main content, and on the search page,
  // whose content is the results the chat already has.
  function pageText() {
    if (doc.querySelector('#scolta-results')) return null;
    const root = doc.querySelector('[data-pagefind-body]') || doc.querySelector('main') || doc.querySelector('article');
    if (!root) return null;
    const copy = root.cloneNode(true);
    for (const el of copy.querySelectorAll(PAGE_IGNORE)) el.remove();
    // textContent runs blocks together ("DeadlinesFile within"); space them.
    for (const el of copy.querySelectorAll(PAGE_BLOCKS)) el.append(' ');
    const text = (copy.textContent || '').replace(/\s+/g, ' ').trim();
    return text || null;
  }

  // The page being read, as the planning call needs it: URL and title.
  function pageRef() {
    const canonical = doc.querySelector('link[rel="canonical"]');
    const url = new URL((canonical && canonical.href) || global.location.href);
    url.hash = '';
    return { url: url.href, title: doc.title || '' };
  }

  function pageContext(retriever, query, cfg) {
    if (!cfg.pageContext) return null;
    const text = pageText();
    if (text === null) return null;
    const description = doc.querySelector('meta[name="description"]');
    return Object.assign(pageRef(), {
      description: (description && description.getAttribute('content')) || '',
      text: retriever.extractContext(text, query, cfg.pageChars || 3000),
    });
  }

  // --- The widget -----------------------------------------------------------

  function createChat(cfg) {
    const L = labels();
    const state = {
      threadId: null,
      seed: null,
      loading: null,
      retriever: null,
      element: null,
      csrf: null,
      restored: false,
      running: null,
      // New chat bumps it; a turn that began under an older one is dropped.
      chat: 0,
    };

    // Launcher, panel and a polite live region; all ignored by page context.
    const launcher = doc.createElement('button');
    launcher.type = 'button';
    launcher.className = 'scolta-chat-launcher';
    launcher.textContent = L.chatLauncher;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.setAttribute('aria-controls', 'scolta-chat-panel');
    launcher.setAttribute('data-scolta-chat-ignore', '');

    const panel = doc.createElement('div');
    panel.id = 'scolta-chat-panel';
    panel.className = 'scolta-chat-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', L.chatTitle);
    panel.setAttribute('data-scolta-chat-ignore', '');

    const header = doc.createElement('div');
    header.className = 'scolta-chat-header';
    const title = doc.createElement('span');
    title.className = 'scolta-chat-title';
    title.textContent = L.chatTitle;
    const newChat = doc.createElement('button');
    newChat.type = 'button';
    newChat.className = 'scolta-chat-new';
    newChat.textContent = L.chatNewChat;
    const close = doc.createElement('button');
    close.type = 'button';
    close.className = 'scolta-chat-close';
    close.setAttribute('aria-label', L.chatClose);
    close.textContent = '×';
    header.append(title, newChat, close);

    const status = doc.createElement('div');
    status.className = 'scolta-chat-status';
    status.hidden = true;

    const body = doc.createElement('div');
    body.className = 'scolta-chat-body';

    const live = doc.createElement('div');
    live.className = 'scolta-chat-live';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');

    panel.append(header, status, body, live);
    doc.body.append(launcher, panel);

    function setStatus(text) {
      status.textContent = text || '';
      status.hidden = !text;
    }

    // --- Loading: deep-chat, Pagefind and WASM, off the send path ----------

    function load() {
      if (!state.loading) {
        state.retriever = global.Scolta.createRetriever(global.scolta);
        state.loading = Promise.all([
          // A custom element can be defined once per page, and a page may
          // already carry deep-chat (Drupal AI's chatbot ships a 2.x copy).
          // Its API is the same, so that one is used.
          global.customElements.get('deep-chat') ? null : import(cfg.deepChatPath),
          state.retriever.ready(),
        ]).catch(err => {
          // The next open tries again.
          state.loading = null;
          throw err;
        });
      }
      return state.loading;
    }

    // The platform's CSRF token for a signed in visitor, fetched once.
    function csrfToken() {
      if (!state.csrf) {
        state.csrf = fetch(cfg.csrf.tokenUrl, { credentials: 'same-origin' })
          .then(resp => {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.text();
          })
          .then(text => text.trim())
          .catch(() => {
            // Not kept, so the next request asks again.
            state.csrf = null;
            return '';
          });
      }
      return state.csrf;
    }

    const warm = () => {
      load().catch(err => console.warn('[scolta:chat] loading failed', err));
      if (cfg.csrf && cfg.csrf.header && cfg.csrf.tokenUrl) csrfToken();
    };
    if (typeof global.requestIdleCallback === 'function') {
      global.requestIdleCallback(warm, { timeout: 4000 });
    } else {
      global.setTimeout(warm, 1500);
    }
    for (const type of ['pointerenter', 'focus', 'touchstart']) {
      launcher.addEventListener(type, warm, { once: true, passive: true });
    }

    // --- Requests -----------------------------------------------------------

    async function headers(extra) {
      const out = Object.assign({ 'X-Scolta-Chat': '1' }, extra || {});
      if (cfg.csrf && cfg.csrf.header && cfg.csrf.tokenUrl) {
        const token = await csrfToken();
        if (token) out[cfg.csrf.header] = token;
      }
      return out;
    }

    async function send(method, url, payload, accept) {
      const init = {
        method: method,
        credentials: 'same-origin',
        headers: await headers(Object.assign(
          { Accept: accept || 'application/json' },
          payload ? { 'Content-Type': 'application/json' } : {}
        )),
      };
      if (payload) init.body = JSON.stringify(payload);
      const resp = await fetch(url, init);
      if (!resp.ok) {
        const err = new Error('HTTP ' + resp.status);
        err.status = resp.status;
        throw err;
      }
      return resp;
    }

    // --- One turn -----------------------------------------------------------

    function answerHtml(text, sources) {
      let html = global.Scolta.formatAnswer(text, { allowedLinkDomains: ownHosts() });
      if (sources && sources.length > 0) {
        const details = doc.createElement('details');
        details.className = 'scolta-chat-sources';
        const summary = doc.createElement('summary');
        summary.textContent = L.chatSources;
        const list = doc.createElement('div');
        // A Markdown list through formatAnswer(): titles escaped, URLs through
        // the same scheme and host check as every link in an answer.
        list.innerHTML = global.Scolta.formatAnswer(
          sources.map(p => '- [' + String(p.title).replace(/[[\]]/g, '') + '](' + p.url + ')').join('\n'),
          { allowedLinkDomains: ownHosts() }
        );
        details.append(summary, list);
        html += details.outerHTML;
      }
      return html;
    }

    function plainText(html) {
      const holder = doc.createElement('div');
      holder.innerHTML = html;
      return (holder.textContent || '').replace(/\s+/g, ' ').trim();
    }

    // Put the message back in the input so the visitor can send it again.
    function restoreInput(message) {
      try {
        const input = state.element.shadowRoot.getElementById('text-input');
        input.textContent = message;
        input.dispatchEvent(new global.Event('input', { bubbles: true }));
      } catch (e) {
        // deep-chat's markup changed; the message is still in the thread.
      }
    }

    async function turn(message, signals) {
      const chat = state.chat;
      const stale = () => chat !== state.chat;
      // A turn New chat left behind ends quietly, and whatever it had
      // already drawn leaves the cleared panel with it.
      const drop = () => {
        setStatus('');
        signals.onClose();
        state.element.clearMessages(true);
      };
      // deep-chat's stop button keeps the answer drawn so far and ends the
      // bubble itself; reading the rest would only save it unseen.
      let stopped = false;
      if (signals.stopClicked) signals.stopClicked.listener = () => { stopped = true; };
      const seed = state.seed;
      let query = message;
      let needsSearch = true;
      let expandedTerms;

      setStatus(L.chatWorking);
      await load();
      // Content words outside the same list decide whether an opening message
      // searches, and keep a follow up searching whatever the plan says.
      const hasContent = state.retriever.terms(message).some(t => !SMALL_TALK.has(t));
      // "Thanks!" or "hi there": only greetings, so nothing to plan. Read on
      // every word typed, stop words too, so "Great, tell me more" still goes
      // to the planner, and a question ("That's it?") always does.
      const typed = message.toLowerCase().match(/[\p{L}\p{N}]+/gu) || [];
      const pleasantry = !message.includes('?') && typed.length > 0 && typed.every(w => SMALL_TALK.has(w));

      if (state.threadId === null && seed === null) {
        // First turn: nothing to rewrite, so retrieve() calls expand-query
        // itself, in parallel with the primary search.
        needsSearch = hasContent;
      } else if (pleasantry) {
        needsSearch = false;
      } else {
        const planResp = await send('POST', cfg.endpoints.plan, {
          thread_id: state.threadId,
          message: message,
          seed: seed,
          page: cfg.pageContext && pageText() !== null ? pageRef() : undefined,
        });
        const plan = await planResp.json();
        if (stale()) return drop();
        query = plan.query || message;
        expandedTerms = Array.isArray(plan.terms) ? plan.terms : [];
        // A message with content words always searches, whatever the plan said.
        needsSearch = plan.needs_search !== false || hasContent;
      }

      let pages = [];
      let page = null;
      if (needsSearch) {
        setStatus(L.chatReading);
        const found = await state.retriever.retrieve(query, expandedTerms ? { expandedTerms } : {});
        pages = state.retriever.buildContext(found.results, {
          query: query,
          topN: cfg.topResults,
          topChars: cfg.topChars,
          broadN: cfg.broadResults,
          broadChars: cfg.broadChars,
        });
        page = pageContext(state.retriever, query, cfg);
      }

      if (stale()) return drop();
      const resp = await send('POST', cfg.endpoints.turn, {
        thread_id: state.threadId,
        message: message,
        needs_search: needsSearch,
        pages: pages,
        page: page,
        seed: seed,
      }, 'text/event-stream');

      let text = '';
      let sources = [];
      let fold = false;
      let done = false;
      let failed = null;
      let opened = false;
      let frame = 0;
      const render = () => {
        frame = 0;
        signals.onResponse({ html: answerHtml(text, sources), overwrite: true });
      };

      await readEvents(resp, (name, data) => {
        if (name === 'thread') {
          state.threadId = data.thread_id;
        } else if (name === 'delta') {
          if (!opened) {
            opened = true;
            setStatus('');
            signals.onOpen();
          }
          text += data.text;
          if (!frame) frame = global.requestAnimationFrame(render);
        } else if (name === 'sources') {
          sources = Array.isArray(data.pages) ? data.pages : [];
        } else if (name === 'done') {
          done = true;
          fold = !!data.fold;
        } else if (name === 'error') {
          failed = data;
        }
      }, () => stale() || stopped);

      if (frame) global.cancelAnimationFrame(frame);
      if (stale()) return drop();
      setStatus('');
      if (stopped) {
        live.textContent = plainText(answerHtml(text, sources));
        return;
      }
      if (failed || !done) {
        // No done event means the connection closed part way, and the server
        // kept nothing of this answer.
        const err = new Error(failed ? failed.message : 'stream ended early');
        err.status = failed ? failed.status : 0;
        throw err;
      }
      state.seed = null;
      if (!opened) signals.onOpen();
      const html = answerHtml(text, sources);
      signals.onResponse({ html: html, overwrite: true });
      signals.onClose();
      live.textContent = plainText(html);

      if (fold && state.threadId) {
        // After the reply is on screen, as its own request.
        send('POST', cfg.endpoints.fold, { thread_id: state.threadId }).catch(() => {});
      }
    }

    function handler(requestBody, signals) {
      const messages = (requestBody && requestBody.messages) || [];
      const last = messages[messages.length - 1] || {};
      const message = String(last.text || '').trim();
      const chat = state.chat;
      state.running = turn(message, signals).catch(err => {
        console.warn('[scolta:chat] turn failed', err && err.status ? 'HTTP ' + err.status : err);
        setStatus('');
        if (chat !== state.chat) {
          signals.onClose();
          state.element.clearMessages(true);
          return;
        }
        const busy = err && (err.status === 429 || err instanceof TypeError);
        signals.onResponse({ error: busy ? L.chatBusy : L.chatError });
        restoreInput(message);
      });
    }

    // --- Opening, closing, history -------------------------------------------

    async function restoreHistory() {
      try {
        const resp = await send('GET', cfg.endpoints.thread);
        const data = await resp.json();
        state.threadId = data.thread_id || null;
        return (data.messages || []).map(m => m.role === 'user'
          ? { role: 'user', text: m.content }
          : { role: 'ai', html: answerHtml(m.content, m.sources) });
      } catch (e) {
        return [];
      }
    }

    async function build() {
      const [history] = await Promise.all([state.restored ? [] : restoreHistory(), load()]);
      state.restored = true;
      const el = doc.createElement('deep-chat');
      el.className = 'scolta-chat-deep-chat';
      el.connect = { handler: handler, stream: true };
      el.history = history;
      el.textInput = { placeholder: { text: L.chatPlaceholder }, characterLimit: 2000 };
      el.auxiliaryStyle = SHADOW_STYLE;
      el.displayLoadingBubble = true;
      el.style.width = '100%';
      el.style.height = '100%';
      el.style.border = 'none';
      // deep-chat refuses submitUserMessage() and friends until it has
      // rendered; the fallback covers a copy that never reports it.
      const rendered = new Promise(resolve => {
        const fallback = global.setTimeout(resolve, 3000);
        el.onComponentRender = () => {
          global.clearTimeout(fallback);
          resolve();
        };
      });
      body.replaceChildren(el);
      state.element = el;
      await rendered;
      return el;
    }

    async function open() {
      panel.hidden = false;
      launcher.setAttribute('aria-expanded', 'true');
      const el = state.element || await build();
      el.focusInput();
      return el;
    }

    function hide() {
      panel.hidden = true;
      launcher.setAttribute('aria-expanded', 'false');
      launcher.focus();
    }

    launcher.addEventListener('click', () => {
      if (panel.hidden) {
        open().catch(err => {
          console.warn('[scolta:chat] opening failed', err);
          setStatus(L.chatUnavailable);
        });
      } else {
        hide();
      }
    });
    close.addEventListener('click', hide);
    panel.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        e.preventDefault();
        hide();
      }
    });
    newChat.addEventListener('click', () => {
      // The server starts the next thread on the first turn.
      send('DELETE', cfg.endpoints.thread).catch(() => {});
      state.chat++;
      state.threadId = null;
      state.seed = null;
      if (state.element) state.element.clearMessages(true);
      if (state.element) state.element.focusInput();
    });

    // --- Hand off from the search page's follow up box ----------------------

    if (cfg.handoff) {
      doc.addEventListener('scolta:followup-submit', e => {
        const detail = e.detail || {};
        if (!detail.question || !detail.summary) return;
        e.preventDefault();
        // Like New chat: a turn still running is left behind.
        state.chat++;
        state.threadId = null;
        state.seed = {
          query: detail.query || '',
          summary: detail.summary,
          pages: Array.isArray(detail.pages) ? detail.pages : [],
        };
        state.restored = true;
        // The search page has dropped its own follow up, so show the chat
        // taking the question at once, before deep-chat is ready.
        panel.hidden = false;
        launcher.setAttribute('aria-expanded', 'true');
        setStatus(L.chatWorking);
        const start = state.element ? Promise.resolve(state.element) : build();
        // deep-chat takes a new message only once the running turn closed.
        Promise.all([start, state.running]).then(([el]) => {
          el.clearMessages(true);
          el.submitUserMessage({ text: detail.question });
        }).catch(err => {
          console.warn('[scolta:chat] hand off failed', err);
          setStatus(L.chatUnavailable);
        });
      });
    }

    return { open: open, close: hide, launcher: launcher, panel: panel };
  }

  function start() {
    const cfg = chatConfig();
    if (!cfg || !cfg.enabled || !cfg.endpoints || global.Scolta?.chat) return;
    if (!global.Scolta || typeof global.Scolta.createRetriever !== 'function') {
      console.warn('[scolta:chat] scolta.js must load before scolta-chat.js');
      return;
    }
    global.Scolta.chat = createChat(cfg);
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})(typeof window !== 'undefined' ? window : this);
