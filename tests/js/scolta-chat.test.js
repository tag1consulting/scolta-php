/**
 * The chat widget (assets/js/scolta-chat.js) in JSDOM.
 *
 * scolta.js is loaded for real (Scolta.formatAnswer), with its retriever
 * replaced by a recording double; deep-chat is a small custom element that
 * calls the widget's handler the way deep-chat does, and fetch answers the
 * chat routes, streaming turn bodies in awkward chunks.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const { TextDecoder, TextEncoder } = require('util');

const scoltaSource = fs.readFileSync(path.resolve(__dirname, '../../assets/js/scolta.js'), 'utf-8');
const chatSource = fs.readFileSync(path.resolve(__dirname, '../../assets/js/scolta-chat.js'), 'utf-8')
    .replace('import(cfg.deepChatPath)', 'window.__importDeepChat(cfg.deepChatPath)');

const tick = () => new Promise(r => setTimeout(r, 0));
const settle = async (n = 20) => { for (let i = 0; i < n; i++) await tick(); };

function sse(events) {
    return events.map(([name, data]) => `event: ${name}\ndata: ${JSON.stringify(data)}\n\n`).join('');
}

// A response whose body arrives in chunks of `size` bytes, each after
// `delay` ms when a delay is given.
function streamed(text, size = 7, delay = 0, onCancel = () => {}) {
    const bytes = new TextEncoder().encode(text);
    let at = 0;
    const next = () => (at >= bytes.length
        ? { done: true, value: undefined }
        : { done: false, value: bytes.slice(at, at += size) });
    return {
        ok: true,
        status: 200,
        body: {
            getReader: () => ({
                read: () => (delay ? new Promise(r => setTimeout(() => r(next()), delay)) : Promise.resolve(next())),
                cancel: () => { onCancel(); return Promise.resolve(); },
            }),
        },
    };
}

function json(data, status = 200) {
    return { ok: status < 400, status, json: () => Promise.resolve(data), text: () => Promise.resolve(JSON.stringify(data)) };
}

// Every window a test opened, closed after it so no timer outlives the run.
const windows = [];
afterEach(() => { windows.splice(0).forEach(win => win.close()); });

async function setup({ chat = {}, html = '', turnEvents, plan, idle = false, labels, chunkDelay = 0 } = {}) {
    const dom = new JSDOM(`<!DOCTYPE html><html><head><title>GDPR deadlines</title>
        <meta name="description" content="When to notify."></head><body>${html}</body></html>`,
    { url: 'https://complianceiq.test/guides/deadlines#top', runScripts: 'dangerously' });
    const win = dom.window;
    windows.push(win);
    win.TextDecoder = TextDecoder;
    win.requestAnimationFrame = cb => setTimeout(cb, 0);
    win.cancelAnimationFrame = id => clearTimeout(id);
    win.console = { log: jest.fn(), warn: jest.fn(), error: jest.fn() };
    const idleCallbacks = [];
    win.requestIdleCallback = cb => { idleCallbacks.push(cb); return 1; };

    const calls = { retrieve: [], build: [], extract: [], fetch: [], imports: 0, created: 0, signals: [], cancelled: 0 };

    win.fetch = jest.fn((url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : null;
        calls.fetch.push({ url, method: init.method || 'GET', headers: init.headers || {}, body });
        if (url === '/chat/plan') return Promise.resolve(json(plan || { query: 'planned query', needs_search: true, terms: ['alpha', 'beta'] }));
        if (url === '/chat/turn') {
            const events = turnEvents || [
                ['thread', { thread_id: 'a'.repeat(32) }],
                ['delta', { text: 'GDPR requires notice within 72 hours ' }],
                ['delta', { text: '[[1]](https://complianceiq.test/gdpr).' }],
                ['sources', { pages: [{ n: 1, title: 'GDPR <b>33</b>', url: 'https://complianceiq.test/gdpr' }] }],
                ['done', { fold: true }],
            ];
            if (typeof events === 'number') return Promise.resolve({ ok: false, status: events });
            return Promise.resolve(streamed(sse(events), 7, chunkDelay, () => { calls.cancelled++; }));
        }
        if (url === '/chat/thread') return Promise.resolve(json(init.method === 'DELETE' ? { thread_id: 'b'.repeat(32) } : { thread_id: null, messages: [] }));
        if (url === '/chat/fold') return Promise.resolve(json({ folded: true }));
        if (url === '/session/token') return Promise.resolve(json('token-1'));
        return Promise.resolve(json({}, 404));
    });

    win.__importDeepChat = () => {
        calls.imports++;
        if (!win.customElements.get('deep-chat')) {
            win.customElements.define('deep-chat', class extends win.HTMLElement {
                constructor() {
                    super();
                    this.attachShadow({ mode: 'open' }).innerHTML = '<div id="text-input" contenteditable="true"></div>';
                    this.focused = 0;
                }
                connectedCallback() {
                    // deep-chat reports its first render; until then it
                    // refuses submitUserMessage().
                    setTimeout(() => { this.rendered = true; if (this.onComponentRender) this.onComponentRender(this); }, 0);
                }
                focusInput() { this.focused++; this.shadowRoot.getElementById('text-input').focus(); }
                clearMessages() { this.cleared = (this.cleared || 0) + 1; }
                submitUserMessage({ text }) {
                    if (!this.rendered) throw new Error('submitUserMessage before render');
                    const record = { opened: 0, closed: 0, responses: [] };
                    calls.signals.push(record);
                    const stopClicked = { listener: () => {} };
                    record.stop = () => stopClicked.listener();
                    this.connect.handler({ messages: [{ role: 'user', text }] }, {
                        onOpen: () => { record.opened++; },
                        onClose: () => { record.closed++; },
                        onResponse: r => { record.responses.push(r); return Promise.resolve(); },
                        stopClicked,
                    });
                    return record;
                }
            });
        }
        return Promise.resolve({});
    };

    win.eval(scoltaSource);
    win.Scolta.createRetriever = () => {
        calls.created++;
        return {
            ready: () => Promise.resolve(),
            retrieve: (query, options = {}) => {
                calls.retrieve.push({ query, options });
                return Promise.resolve({ query, expandedTerms: [], results: [{ data: { url: '/gdpr' }, score: 1 }], total: 1 });
            },
            buildContext: (results, options) => {
                calls.build.push(options);
                return [{ n: 1, tier: 1, title: 'GDPR 33', url: 'https://complianceiq.test/gdpr', excerpt: '72 hours.' }];
            },
            extractContext: (text, query, max) => {
                calls.extract.push({ text, query, max });
                return text.slice(0, max);
            },
            terms: text => String(text).toLowerCase().replace(/[^\w\s]/g, '').split(/\s+/)
                // A few of scolta.js's stop words, enough for these messages.
                .filter(w => w.length > 1 && !['what', 'does', 'this', 'the', 'is', 'about', 'say', 'page', 'any', 'for', 'of', 'there', 'you', 'and', 'tell', 'me', 'more'].includes(w)),
        };
    };
    win.scolta = {
        labels: labels || {},
        chat: Object.assign({
            enabled: true,
            deepChatPath: '/vendor/deep-chat.js',
            endpoints: { plan: '/chat/plan', turn: '/chat/turn', fold: '/chat/fold', thread: '/chat/thread' },
            topResults: 5, topChars: 6000, broadResults: 25, broadChars: 2500,
            pageContext: true, pageChars: 3000, handoff: true,
        }, chat),
    };
    win.eval(chatSource);
    if (win.document.readyState === 'loading') {
        await new Promise(r => win.document.addEventListener('DOMContentLoaded', r));
    }
    if (idle) idleCallbacks.forEach(cb => cb());

    const $ = sel => win.document.querySelector(sel);
    async function openChat() {
        $('.scolta-chat-launcher').click();
        await settle();
        return $('deep-chat');
    }
    async function ask(text) {
        const el = $('deep-chat') || await openChat();
        const record = el.submitUserMessage({ text });
        await settle(40);
        return record;
    }
    const turns = () => calls.fetch.filter(f => f.url === '/chat/turn');
    const plans = () => calls.fetch.filter(f => f.url === '/chat/plan');

    return { win, $, calls, openChat, ask, turns, plans, idleCallbacks };
}

describe('scolta-chat.js', () => {
    test('renders nothing unless the chat is enabled', async () => {
        const { $ } = await setup({ chat: { enabled: false } });
        expect($('.scolta-chat-launcher')).toBeNull();
    });

    test('loads nothing heavy until the browser is idle or the launcher is approached', async () => {
        const h = await setup();
        await settle();
        expect(h.calls.imports).toBe(0);
        expect(h.calls.created).toBe(0);

        h.$('.scolta-chat-launcher').dispatchEvent(new h.win.Event('pointerenter'));
        h.$('.scolta-chat-launcher').dispatchEvent(new h.win.Event('focus'));
        await settle();
        expect(h.calls.imports).toBe(1);
        expect(h.calls.created).toBe(1);

        const idle = await setup({ idle: true });
        await settle();
        expect(idle.calls.imports).toBe(1);
        expect(idle.calls.created).toBe(1);
    });

    test('a deep-chat another module already defined is used, not imported again', async () => {
        const h = await setup();
        h.win.customElements.define('deep-chat', class extends h.win.HTMLElement {
            focusInput() {}
        });
        h.$('.scolta-chat-launcher').click();
        await settle();

        expect(h.calls.imports).toBe(0);
        expect(h.$('.scolta-chat-deep-chat')).not.toBeNull();
        expect(h.$('.scolta-chat-status').hidden).toBe(true);
    });

    test('a chat that cannot load says so in its own words', async () => {
        const h = await setup();
        const importDeepChat = h.win.__importDeepChat;
        h.win.__importDeepChat = () => Promise.reject(new Error('404'));
        h.$('.scolta-chat-launcher').click();
        await settle();

        expect(h.$('.scolta-chat-status').textContent).toBe("The chat didn't load. Try again in a moment.");
        expect(h.win.console.warn).toHaveBeenCalledWith('[scolta:chat] opening failed', expect.any(Error));

        // A failed load is not kept: the next open tries again.
        h.win.__importDeepChat = importDeepChat;
        h.$('.scolta-chat-launcher').click();
        h.$('.scolta-chat-launcher').click();
        await settle();
        expect(h.$('.scolta-chat-deep-chat')).not.toBeNull();
    });

    test('opening focuses the input, Escape closes and focus returns to the launcher', async () => {
        const h = await setup();
        const el = await h.openChat();
        const launcher = h.$('.scolta-chat-launcher');

        expect(h.$('#scolta-chat-panel').hidden).toBe(false);
        expect(launcher.getAttribute('aria-expanded')).toBe('true');
        expect(el.focused).toBe(1);
        expect(h.$('#scolta-chat-panel').getAttribute('role')).toBe('dialog');
        expect(h.$('.scolta-chat-close').getAttribute('aria-label')).toBe('Close');

        h.$('#scolta-chat-panel').dispatchEvent(new h.win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(h.$('#scolta-chat-panel').hidden).toBe(true);
        expect(launcher.getAttribute('aria-expanded')).toBe('false');
        expect(h.win.document.activeElement).toBe(launcher);
    });

    test('labels come from window.scolta.labels', async () => {
        const h = await setup({ labels: { chatLauncher: 'Chiedi', chatNewChat: 'Nuova chat' } });
        expect(h.$('.scolta-chat-launcher').textContent).toBe('Chiedi');
        expect(h.$('.scolta-chat-new').textContent).toBe('Nuova chat');
        expect(h.$('.scolta-chat-close').getAttribute('aria-label')).toBe('Close');
    });

    test('the first turn retrieves the message itself and posts pages with the stream headers', async () => {
        const h = await setup({ html: '<main><h1>Deadlines</h1><p>File within 30 days.</p></main>' });
        await h.ask('What does GDPR say about breach notification?');

        expect(h.plans()).toHaveLength(0);
        expect(h.calls.retrieve).toEqual([{ query: 'What does GDPR say about breach notification?', options: {} }]);
        expect(h.calls.build[0]).toEqual({ query: 'What does GDPR say about breach notification?', topN: 5, topChars: 6000, broadN: 25, broadChars: 2500 });

        const turn = h.turns()[0];
        expect(turn.method).toBe('POST');
        expect(turn.headers['X-Scolta-Chat']).toBe('1');
        expect(turn.headers.Accept).toBe('text/event-stream');
        expect(turn.body).toEqual({
            thread_id: null,
            message: 'What does GDPR say about breach notification?',
            needs_search: true,
            pages: [{ n: 1, tier: 1, title: 'GDPR 33', url: 'https://complianceiq.test/gdpr', excerpt: '72 hours.' }],
            page: { url: 'https://complianceiq.test/guides/deadlines', title: 'GDPR deadlines', description: 'When to notify.', text: 'Deadlines File within 30 days.' },
            seed: null,
        });
    });

    test('the stream renders through formatAnswer, with cited sources, then folds', async () => {
        const h = await setup();
        const record = await h.ask('What does GDPR say about breach notification?');

        expect(record.opened).toBe(1);
        expect(record.closed).toBe(1);
        const last = record.responses[record.responses.length - 1];
        expect(last.overwrite).toBe(true);
        const holder = h.win.document.createElement('div');
        holder.innerHTML = last.html;
        expect(holder.querySelector('sup.scolta-cite a').getAttribute('href')).toBe('https://complianceiq.test/gdpr');
        expect(holder.querySelector('details.scolta-chat-sources summary').textContent).toBe('Sources');
        expect(holder.querySelector('details.scolta-chat-sources a').textContent).toBe('GDPR <b>33</b>');
        expect(holder.querySelector('b')).toBeNull();

        const fold = h.calls.fetch.find(f => f.url === '/chat/fold');
        expect(fold.body).toEqual({ thread_id: 'a'.repeat(32) });
        expect(h.$('.scolta-chat-live').textContent).toContain('GDPR requires notice within 72 hours');
        expect(h.$('.scolta-chat-live').getAttribute('aria-live')).toBe('polite');
    });

    test('a link to another host in an answer is not a link', async () => {
        const h = await setup({ turnEvents: [
            ['thread', { thread_id: 'a'.repeat(32) }],
            ['delta', { text: 'See [this](https://evil.example/x) and [[1]](https://evil.example/y).' }],
            ['sources', { pages: [] }],
            ['done', { fold: false }],
        ] });
        const record = await h.ask('Where can I read more?');
        const last = record.responses[record.responses.length - 1];
        expect(last.html).not.toContain('<a');
        expect(h.calls.fetch.find(f => f.url === '/chat/fold')).toBeUndefined();
    });

    test('a later turn plans first and retrieves with the planned terms', async () => {
        const h = await setup();
        await h.ask('What does GDPR say about breach notification?');
        await h.ask('Any real examples of fines?');

        expect(h.plans()[0].body).toEqual({ thread_id: 'a'.repeat(32), message: 'Any real examples of fines?', seed: null });
        expect(h.calls.retrieve[1]).toEqual({ query: 'planned query', options: { expandedTerms: ['alpha', 'beta'] } });
        expect(h.turns()[1].body.thread_id).toBe('a'.repeat(32));
    });

    test('a plan that says no search is ignored when the message has content words', async () => {
        const h = await setup({ plan: { query: '', needs_search: false, terms: [] } });
        await h.ask('Hello there');
        await h.ask('Is Goose open source?');

        expect(h.calls.retrieve.map(r => r.query)).toEqual(['Is Goose open source?']);
        expect(h.turns()[1].body.needs_search).toBe(true);
    });

    test('small talk sends no pages and looks nothing up', async () => {
        const h = await setup({ plan: { query: '', needs_search: false, terms: [] } });
        await h.ask('Thanks!');
        await h.ask('Thank you, cheers');

        expect(h.calls.retrieve).toHaveLength(0);
        expect(h.plans()).toHaveLength(0);
        for (const turn of h.turns()) {
            expect(turn.body.needs_search).toBe(false);
            expect(turn.body.pages).toEqual([]);
            expect(turn.body.page).toBeNull();
        }
    });

    test('a later message with no words of its own still goes to the planner', async () => {
        const h = await setup({ plan: { query: 'GDPR breach notification details', needs_search: true, terms: [] } });
        await h.ask('What does GDPR say about breach notification?');
        await h.ask('Tell me more');
        await h.ask('Great, tell me more');
        await h.ask('Yes please');
        await h.ask("That's it?");

        expect(h.plans()).toHaveLength(4);
        expect(h.calls.retrieve[1].query).toBe('GDPR breach notification details');
        expect(h.turns().slice(1).map(t => t.body.needs_search)).toEqual([true, true, true, true]);
    });

    test('page context skips navigation, forms and anything marked ignore', async () => {
        const h = await setup({ html: `
            <header>Site header</header><nav>Menu</nav>
            <main>Main text that is not the body.</main>
            <div data-pagefind-body>
              <h1>Deadlines</h1><p>File within 30 days.</p>
              <nav>Breadcrumbs</nav><form><label>Email</label></form>
              <div data-pagefind-ignore>Related links</div>
              <div data-scolta-chat-ignore>Promo</div>
              <footer>Page footer</footer><script>var x = 1;</script>
            </div>` });
        await h.ask('What does this page say about deadlines?');

        const page = h.turns()[0].body.page;
        expect(page.text).toBe('Deadlines File within 30 days.');
        expect(h.calls.extract[0]).toEqual({ text: 'Deadlines File within 30 days.', query: 'What does this page say about deadlines?', max: 3000 });
    });

    test('page context is left out when it is off, on a page without content and on the search page', async () => {
        const off = await setup({ chat: { pageContext: false }, html: '<main>Text</main>' });
        await off.ask('What about deadlines?');
        expect(off.turns()[0].body.page).toBeNull();

        const empty = await setup();
        await empty.ask('What about deadlines?');
        expect(empty.turns()[0].body.page).toBeNull();

        const search = await setup({ html: '<main><div id="scolta-results">results</div></main>' });
        await search.ask('What about deadlines?');
        expect(search.turns()[0].body.page).toBeNull();
    });

    test('a 429 shows a short line and puts the message back in the input', async () => {
        const h = await setup({ turnEvents: 429 });
        const record = await h.ask('What about contractors?');

        expect(record.responses).toEqual([{ error: 'Lots of questions at once. Wait a moment, then send your message again.' }]);
        expect(h.$('deep-chat').shadowRoot.getElementById('text-input').textContent).toBe('What about contractors?');
    });

    test('an error event mid stream is reported the same way', async () => {
        const h = await setup({ turnEvents: [['thread', { thread_id: 'a'.repeat(32) }], ['error', { message: 'Chat unavailable', status: 503 }]] });
        const record = await h.ask('What about contractors?');

        expect(record.responses[record.responses.length - 1]).toEqual({ error: expect.stringContaining("didn't go through") });
    });

    test('a stream that stops before done is a failure, not an answer', async () => {
        const h = await setup({ turnEvents: [['thread', { thread_id: 'a'.repeat(32) }], ['delta', { text: 'GDPR requires notice within' }]] });
        const record = await h.ask('What does GDPR say about breach notification?');

        expect(record.responses[record.responses.length - 1]).toEqual({ error: expect.stringContaining("didn't go through") });
        expect(h.$('deep-chat').shadowRoot.getElementById('text-input').textContent).toBe('What does GDPR say about breach notification?');
    });

    test('New chat drops a turn still on its way', async () => {
        const h = await setup();
        const el = await h.openChat();
        const record = el.submitUserMessage({ text: 'What does GDPR say about breach notification?' });
        h.$('.scolta-chat-new').click();
        await settle(40);

        expect(h.turns()).toHaveLength(0);
        expect(record.closed).toBe(1);
        expect(record.responses).toEqual([]);

        await h.ask('Something new about retention');
        expect(h.turns()[0].body.thread_id).toBeNull();
    });

    test('the stop button stops reading and keeps what was drawn', async () => {
        const h = await setup({ chunkDelay: 1 });
        const el = await h.openChat();
        const record = el.submitUserMessage({ text: 'What does GDPR say about breach notification?' });
        await h.win.eval('new Promise(r => setTimeout(r, 30))');
        record.stop();
        await settle(40);

        expect(h.calls.cancelled).toBe(1);
        expect(record.responses.some(r => r.error)).toBe(false);
        expect(el.cleared).toBeUndefined();
        expect(h.calls.fetch.filter(f => f.url === '/chat/fold')).toHaveLength(0);
        expect(h.$('.scolta-chat-live').textContent).toContain('GDPR requires');
    });

    test('New chat part way through a stream stops reading it and clears what it drew', async () => {
        const h = await setup({ chunkDelay: 1 });
        const el = await h.openChat();
        const record = el.submitUserMessage({ text: 'What does GDPR say about breach notification?' });
        await h.win.eval('new Promise(r => setTimeout(r, 30))');
        expect(record.opened).toBe(1);

        h.$('.scolta-chat-new').click();
        await settle(40);

        expect(h.calls.cancelled).toBe(1);
        expect(record.closed).toBe(1);
        expect(el.cleared).toBe(2);
        expect(h.calls.fetch.filter(f => f.url === '/chat/fold')).toHaveLength(0);
    });

    test('a signed in visitor sends the CSRF header the config names', async () => {
        const h = await setup({ chat: { csrf: { header: 'X-CSRF-Token', tokenUrl: '/session/token' } } });
        await h.ask('What about contractors?');
        await h.ask('And processors?');

        expect(h.turns()[0].headers['X-CSRF-Token']).toBe('"token-1"');
        expect(h.calls.fetch.filter(f => f.url === '/session/token')).toHaveLength(1);
    });

    test('the search page hand off opens the chat seeded and cancels its own follow up', async () => {
        const h = await setup();
        const event = new h.win.CustomEvent('scolta:followup-submit', {
            bubbles: true,
            cancelable: true,
            detail: { question: 'What about contractors?', query: 'data retention', summary: 'Keep records six years.', pages: [{ title: 'Retention', url: 'https://complianceiq.test/retention', excerpt: '' }] },
        });
        const notCancelled = h.win.document.body.dispatchEvent(event);
        // Before deep-chat has loaded, the panel is open and working.
        expect(h.$('#scolta-chat-panel').hidden).toBe(false);
        expect(h.$('.scolta-chat-status').textContent).toBe('Working on it');
        await settle(60);

        expect(notCancelled).toBe(false);
        expect(h.$('#scolta-chat-panel').hidden).toBe(false);
        const seed = { query: 'data retention', summary: 'Keep records six years.', pages: [{ title: 'Retention', url: 'https://complianceiq.test/retention', excerpt: '' }] };
        expect(h.plans()[0].body).toEqual({ thread_id: null, message: 'What about contractors?', seed });
        expect(h.turns()[0].body.seed).toEqual(seed);

        await h.ask('And processors?');
        expect(h.turns()[1].body.seed).toBeNull();
    });

    test('a hand off during a running turn starts a new thread once that turn has closed', async () => {
        const h = await setup({ chunkDelay: 1 });
        const el = await h.openChat();
        const running = el.submitUserMessage({ text: 'What does GDPR say about breach notification?' });
        await h.win.eval('new Promise(r => setTimeout(r, 30))');

        h.win.document.body.dispatchEvent(new h.win.CustomEvent('scolta:followup-submit', {
            bubbles: true,
            cancelable: true,
            detail: { question: 'What about contractors?', query: 'data retention', summary: 'Keep records six years.', pages: [] },
        }));
        await h.win.eval('new Promise(r => setTimeout(r, 400))');

        expect(running.closed).toBe(1);
        expect(h.calls.cancelled).toBe(1);
        expect(h.plans()[0].body.thread_id).toBeNull();
        expect(h.turns()[1].body.thread_id).toBeNull();
        expect(h.turns()[1].body.seed.query).toBe('data retention');
    });

    test('a hand off while the chat is still being built uses the one element and a new thread', async () => {
        const h = await setup();
        // The visitor has an earlier thread the opening restores.
        const fetch = h.win.fetch;
        h.win.fetch = jest.fn((url, init = {}) => (url === '/chat/thread' && (init.method || 'GET') === 'GET'
            ? Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ thread_id: 'c'.repeat(32), messages: [] }) })
            : fetch(url, init)));
        h.$('.scolta-chat-launcher').click();
        const handoff = question => h.win.document.body.dispatchEvent(new h.win.CustomEvent('scolta:followup-submit', {
            bubbles: true,
            cancelable: true,
            detail: { question, query: 'data retention', summary: 'Keep records six years.', pages: [] },
        }));
        handoff('What about contractors?');
        handoff('What about processors?');
        await settle(60);

        expect(h.win.document.querySelectorAll('deep-chat')).toHaveLength(1);
        expect(h.plans()).toHaveLength(1);
        expect(h.plans()[0].body.message).toBe('What about processors?');
        expect(h.plans()[0].body.thread_id).toBeNull();
    });

    test('a failed CSRF token fetch is asked for again', async () => {
        const h = await setup({ chat: { csrf: { header: 'X-CSRF-Token', tokenUrl: '/session/token' } } });
        const fetch = h.win.fetch;
        let first = true;
        h.win.fetch = jest.fn((url, init) => {
            if (url === '/session/token' && first) {
                first = false;
                h.calls.fetch.push({ url, method: 'GET', headers: {}, body: null });
                return Promise.resolve({ ok: false, status: 500, text: () => Promise.resolve('') });
            }
            return fetch(url, init);
        });
        await h.ask('What about contractors?');
        await h.ask('And processors?');

        expect(h.calls.fetch.filter(f => f.url === '/session/token')).toHaveLength(2);
        expect(h.turns()[1].headers['X-CSRF-Token']).toBe('"token-1"');
    });

    test('with hand off off the search page keeps its own follow up', async () => {
        const h = await setup({ chat: { handoff: false } });
        const event = new h.win.CustomEvent('scolta:followup-submit', { bubbles: true, cancelable: true, detail: { question: 'q', summary: 's' } });
        expect(h.win.document.body.dispatchEvent(event)).toBe(true);
    });

    test('New chat forgets the thread', async () => {
        const h = await setup();
        await h.ask('What does GDPR say about breach notification?');
        h.$('.scolta-chat-new').click();
        await settle();
        await h.ask('Something new about retention');

        expect(h.calls.fetch.find(f => f.url === '/chat/thread' && f.method === 'DELETE')).toBeDefined();
        expect(h.turns()[1].body.thread_id).toBeNull();
        expect(h.plans()).toHaveLength(0);
    });
});
