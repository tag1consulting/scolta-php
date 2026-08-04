/**
 * The AI summary slot reserves its layout up front.
 *
 * The summarize call is deferred until query expansion settles, so the result
 * list is already painted when the summary lands above it. Inserting it there
 * used to push the list down twice — once for the loading skeleton, once when
 * the resolved summary replaced it — and both pushes are cumulative layout
 * shift (measured 0.120 and 0.317 on a rich-card page; "good" is under 0.1).
 *
 * The slot now takes a fixed height in the frame the results paint in and
 * holds it through every outcome. What is pinned here is the contract that
 * makes that true, and the accessibility of the control that lets a clamped
 * summary be read in full:
 *
 *   - loading reserves; disabled reserves nothing;
 *   - the resolved summary stays reserved, so the swap moves nothing;
 *   - overflow gets the clamp class and a real button with the right ARIA,
 *     and toggling it flips both;
 *   - the complete text is in the DOM collapsed AND expanded — clipped, never
 *     truncated — so find-in-page and assistive tech reach all of it;
 *   - a summary that fits gets no control;
 *   - an empty summary collapses; an error stays inside the reserved box;
 *   - asking a follow-up releases the clamp, or the answer would render
 *     inside a clipped box and be invisible.
 *
 * The pixel truth — that the box really is the same height in each of these
 * states — needs layout and lives in tests/E2E/summary-cls.spec.js. JSDOM has
 * no layout at all, which is why the overflow measurement is stubbed here.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const scoltaSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

const patchedSource = scoltaSource.replace(
    /pagefind\s*=\s*await\s+import\s*\([^)]+\)/,
    'pagefind = { init: function() { return Promise.resolve(); }, search: function() { return Promise.resolve({ results: [] }); } }'
);

// Same exposure pattern as summary-weak-match-signal.test.js: reach the
// private summarize entry point and the clamp helpers without exporting them.
const exposedSource = patchedSource.replace(
    '// SHARED SEARCH HELPERS',
    '// SHARED SEARCH HELPERS\n'
    + '  window.__summarizeResults = summarizeResults;\n'
    + '  window.__reserveSummarySlot = reserveSummarySlot;\n'
    + '  window.__updateSummaryClamp = updateSummaryClamp;\n'
    + '  window.__observeSummaryClamp = observeSummaryClamp;\n'
    + '  window.__releaseSummarySlot = releaseSummarySlot;\n'
    + '  window.__submitFollowUp = submitFollowUp;'
);

const RESERVED = 'scolta-ai-summary--reserved';
const CLAMPED = 'scolta-ai-summary--clamped';

const LONG_SUMMARY = 'A very long summary. '.repeat(200);

/**
 * A stand-in for the real ResizeObserver, which JSDOM does not implement.
 *
 * Installed on the window BEFORE the bundle is evaluated, so the bundle's
 * feature detection sees it. Recording the instances lets a test assert the
 * wiring — what is observed, and that it is disconnected — and firing a
 * recorded callback simulates the width change itself, which is the whole
 * behaviour under test and is otherwise unreachable without layout.
 */
function installFakeResizeObserver(win) {
    const instances = [];
    win.ResizeObserver = class {
        constructor(callback) {
            this.callback = callback;
            this.targets = [];
            this.disconnected = false;
            instances.push(this);
        }
        observe(target) { this.targets.push(target); }
        disconnect() { this.disconnected = true; }
    };
    return instances;
}

/** The observer still connected, if any. */
const liveObserver = (instances) => instances.filter(o => !o.disconnected).pop() || null;

function createWin({
    summarize = true,
    response = { summary: 'A useful summary.' },
    ok = true,
    disclaimer = '',
    resizeObserver = false,
} = {}) {
    const dom = new JSDOM(
        '<!DOCTYPE html><html><body><div id="scolta-search"></div></body></html>',
        { url: 'https://example.com', runScripts: 'dangerously' }
    );
    const win = dom.window;
    // Default off, mirroring JSDOM and older engines: every pre-existing test
    // in this file runs with no ResizeObserver at all, which is itself the
    // feature-detect coverage.
    win.__resizeObservers = resizeObserver ? installFakeResizeObserver(win) : null;
    win.fetch = jest.fn().mockResolvedValue({
        ok,
        status: ok ? 200 : 500,
        json: () => Promise.resolve(response),
        text: () => Promise.resolve('{}'),
    });
    win.console = { log: jest.fn(), error: jest.fn(), warn: jest.fn() };
    win.scrollTo = () => {};
    // Not implemented in JSDOM; the follow-up path scrolls its new turn
    // into view.
    win.Element.prototype.scrollIntoView = () => {};
    win.eval(exposedSource);
    win.scolta = {
        scoring: { AI_SUMMARIZE: summarize },
        endpoints: { expand: '/e', summarize: '/s', followup: '/f' },
        pagefindPath: '/pf.js',
        siteName: 'Test',
        container: '#scolta-search',
        allowedLinkDomains: [],
        disclaimer,
    };
    win.Scolta.init('#scolta-search');
    return win;
}

const panel = (win) => win.document.querySelector('#scolta-ai-summary');
const toggle = (win) => win.document.querySelector('[data-scolta-summary-toggle]');

function makeResults() {
    return [{
        score: 1,
        data: { url: '/a', content: 'Body text.', meta: { title: 'A', url: '/a' } },
    }];
}

/**
 * JSDOM reports 0 for both scrollHeight and clientHeight, so nothing ever
 * "overflows" on its own. Force the measurement the browser would have made,
 * then re-run the check the resolved render runs.
 */
function forceOverflow(win, overflows) {
    const textEl = panel(win).querySelector('.scolta-ai-summary-text');
    Object.defineProperty(textEl, 'scrollHeight', { value: overflows ? 900 : 100, configurable: true });
    Object.defineProperty(textEl, 'clientHeight', { value: 100, configurable: true });
    win.__updateSummaryClamp();
    return textEl;
}

describe('AI summary layout reservation', () => {
    test('the loading slot is reserved, and visible', () => {
        const win = createWin();
        win.__reserveSummarySlot();

        expect(panel(win).classList.contains(RESERVED)).toBe(true);
        expect(panel(win).className).toContain('loading');
        expect(panel(win).style.display).toBe('');
        // The skeleton fills the box rather than showing three token bars.
        expect(panel(win).querySelectorAll('.scolta-ai-shimmer').length).toBeGreaterThan(3);
    });

    test('reserving is idempotent, so a repaint never flashes the skeleton back', async () => {
        const win = createWin();
        await win.__summarizeResults('q', makeResults(), []);
        expect(panel(win).className).not.toContain('loading');

        // A load-more or facet repaint calls this again.
        win.__reserveSummarySlot();
        expect(panel(win).className).not.toContain('loading');
        expect(panel(win).querySelector('.scolta-ai-summary-text').textContent)
            .toContain('A useful summary.');
    });

    test('the summary feature off reserves nothing at all', () => {
        const win = createWin({ summarize: false });
        win.__reserveSummarySlot();

        expect(panel(win).style.display).toBe('none');
        expect(panel(win).className).toBe('');
        expect(panel(win).innerHTML).toBe('');
    });

    test('the resolved summary stays reserved, so the swap moves nothing', async () => {
        const win = createWin();
        win.__reserveSummarySlot();
        await win.__summarizeResults('q', makeResults(), []);

        expect(panel(win).classList.contains(RESERVED)).toBe(true);
        expect(panel(win).style.display).toBe('');
    });

    test('an overflowing summary is clamped and offers an accessible control', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY } });
        await win.__summarizeResults('q', makeResults(), []);
        const textEl = forceOverflow(win, true);

        expect(panel(win).classList.contains(CLAMPED)).toBe(true);

        const btn = toggle(win);
        expect(btn).not.toBeNull();
        expect(btn.tagName).toBe('BUTTON');
        expect(btn.getAttribute('type')).toBe('button');
        expect(btn.hidden).toBe(false);
        expect(btn.getAttribute('aria-expanded')).toBe('false');
        expect(btn.getAttribute('aria-controls')).toBe(textEl.id);
        expect(win.document.getElementById(textEl.id)).toBe(textEl);
        expect(btn.textContent.trim()).toBe('Show more');
    });

    test('clicking the control expands and collapses, updating ARIA and label', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY } });
        await win.__summarizeResults('q', makeResults(), []);
        forceOverflow(win, true);

        const btn = toggle(win);
        btn.dispatchEvent(new win.Event('click', { bubbles: true }));

        expect(panel(win).classList.contains(RESERVED)).toBe(false);
        expect(panel(win).classList.contains(CLAMPED)).toBe(false);
        expect(btn.getAttribute('aria-expanded')).toBe('true');
        expect(btn.textContent.trim()).toBe('Show less');

        btn.dispatchEvent(new win.Event('click', { bubbles: true }));

        expect(panel(win).classList.contains(RESERVED)).toBe(true);
        expect(btn.getAttribute('aria-expanded')).toBe('false');
        expect(btn.textContent.trim()).toBe('Show more');
    });

    test('the whole summary is in the DOM collapsed and expanded alike', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY } });
        await win.__summarizeResults('q', makeResults(), []);
        const textEl = forceOverflow(win, true);

        const collapsedText = textEl.textContent;
        expect(collapsedText.length).toBeGreaterThan(LONG_SUMMARY.length / 2);

        toggle(win).dispatchEvent(new win.Event('click', { bubbles: true }));

        // Clipped by the box, never truncated in the markup: identical text.
        expect(panel(win).querySelector('.scolta-ai-summary-text').textContent)
            .toBe(collapsedText);
    });

    test('a summary that fits offers no control', async () => {
        const win = createWin();
        await win.__summarizeResults('q', makeResults(), []);
        forceOverflow(win, false);

        expect(panel(win).classList.contains(CLAMPED)).toBe(false);
        expect(toggle(win).hidden).toBe(true);
    });

    test('an empty summary collapses the slot to the disabled shape', async () => {
        const win = createWin({ response: {} });
        win.__reserveSummarySlot();
        await win.__summarizeResults('q', makeResults(), []);

        expect(panel(win).style.display).toBe('none');
        expect(panel(win).className).toBe('');
        expect(panel(win).innerHTML).toBe('');
    });

    test('an error stays inside the reserved box instead of collapsing', async () => {
        const win = createWin({ ok: false });
        win.__reserveSummarySlot();
        await win.__summarizeResults('q', makeResults(), []);

        expect(panel(win).classList.contains(RESERVED)).toBe(true);
        expect(panel(win).className).toContain('error');
        expect(panel(win).style.display).toBe('');
        expect(panel(win).textContent).toContain('Summary unavailable');
        // Collapsing here would be an upward shift, which counts exactly as
        // the downward one did.
        expect(toggle(win)).toBeNull();
    });

    test('asking a follow-up releases the clamp so the answer is visible', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY } });
        await win.__summarizeResults('q', makeResults(), []);
        forceOverflow(win, true);
        expect(panel(win).classList.contains(RESERVED)).toBe(true);

        win.document.getElementById('scolta-followup-field').value = 'why?';
        win.__submitFollowUp();

        expect(panel(win).classList.contains(RESERVED)).toBe(false);
        expect(panel(win).classList.contains(CLAMPED)).toBe(false);
        expect(toggle(win).getAttribute('aria-expanded')).toBe('true');
    });

    test('the clamp is recomputed when the text region is resized', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY }, resizeObserver: true });
        await win.__summarizeResults('q', makeResults(), []);

        // Resolved at a width where it fits: no clamp, no control.
        forceOverflow(win, false);
        expect(panel(win).classList.contains(CLAMPED)).toBe(false);
        expect(toggle(win).hidden).toBe(true);

        const observer = liveObserver(win.__resizeObservers);
        expect(observer).not.toBeNull();
        expect(observer.targets).toContain(panel(win).querySelector('.scolta-ai-summary-text'));

        // Narrow the column: the same text now reflows past the reserved box.
        const textEl = panel(win).querySelector('.scolta-ai-summary-text');
        Object.defineProperty(textEl, 'scrollHeight', { value: 900, configurable: true });
        observer.callback();

        // Without this the text would be clipped with no fade and no way to
        // open it — the defect this fixes.
        expect(panel(win).classList.contains(CLAMPED)).toBe(true);
        expect(toggle(win).hidden).toBe(false);
    });

    test('widening again drops the clamp and re-hides the control', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY }, resizeObserver: true });
        await win.__summarizeResults('q', makeResults(), []);
        forceOverflow(win, true);
        expect(panel(win).classList.contains(CLAMPED)).toBe(true);

        const textEl = panel(win).querySelector('.scolta-ai-summary-text');
        Object.defineProperty(textEl, 'scrollHeight', { value: 100, configurable: true });
        liveObserver(win.__resizeObservers).callback();

        expect(panel(win).classList.contains(CLAMPED)).toBe(false);
        expect(toggle(win).hidden).toBe(true);
    });

    test('the panel stays reserved across a resize recompute, so nothing moves', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY }, resizeObserver: true });
        await win.__summarizeResults('q', makeResults(), []);
        forceOverflow(win, false);

        const textEl = panel(win).querySelector('.scolta-ai-summary-text');
        Object.defineProperty(textEl, 'scrollHeight', { value: 900, configurable: true });
        liveObserver(win.__resizeObservers).callback();

        // The recompute toggles a mask class and a hidden flag, both inside a
        // fixed-height overflow-hidden panel. The reservation itself is
        // untouched, which is what keeps the recompute free of layout shift.
        expect(panel(win).classList.contains(RESERVED)).toBe(true);
        expect(panel(win).style.display).toBe('');
    });

    test('only one observer is live, and it is replaced rather than stacked', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY }, resizeObserver: true });
        await win.__summarizeResults('q', makeResults(), []);
        await win.__summarizeResults('q', makeResults(), []);

        expect(win.__resizeObservers.length).toBeGreaterThan(1);
        expect(win.__resizeObservers.filter(o => !o.disconnected)).toHaveLength(1);
    });

    test('expanding disconnects the observer, collapsing re-establishes it', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY }, resizeObserver: true });
        await win.__summarizeResults('q', makeResults(), []);
        forceOverflow(win, true);
        expect(liveObserver(win.__resizeObservers)).not.toBeNull();

        toggle(win).dispatchEvent(new win.Event('click', { bubbles: true }));
        expect(liveObserver(win.__resizeObservers)).toBeNull();

        toggle(win).dispatchEvent(new win.Event('click', { bubbles: true }));
        expect(liveObserver(win.__resizeObservers)).not.toBeNull();
    });

    test('collapsing the slot disconnects the observer', async () => {
        const win = createWin({ response: { summary: LONG_SUMMARY }, resizeObserver: true });
        await win.__summarizeResults('q', makeResults(), []);
        expect(liveObserver(win.__resizeObservers)).not.toBeNull();

        win.__releaseSummarySlot();
        expect(liveObserver(win.__resizeObservers)).toBeNull();
    });

    test('no observer is installed when the summary feature is off', () => {
        const win = createWin({ summarize: false, resizeObserver: true });
        win.__observeSummaryClamp();

        expect(win.__resizeObservers).toHaveLength(0);
    });

    test('resolving without ResizeObserver does not throw and still reserves', async () => {
        // The default for every test in this file, stated explicitly here:
        // JSDOM has no ResizeObserver, and neither do older engines.
        const win = createWin({ response: { summary: LONG_SUMMARY } });
        expect(win.ResizeObserver).toBeUndefined();

        await expect(win.__summarizeResults('q', makeResults(), [])).resolves.not.toThrow();

        expect(panel(win).classList.contains(RESERVED)).toBe(true);
        expect(panel(win).querySelectorAll('.scolta-ai-shimmer')).toHaveLength(0);
    });

    test('a disclaimer still renders, inside the reserved panel', async () => {
        const win = createWin({ disclaimer: 'AI generated.' });
        await win.__summarizeResults('q', makeResults(), []);

        expect(panel(win).querySelector('.scolta-ai-summary-disclaimer').textContent)
            .toBe('AI generated.');
        expect(panel(win).classList.contains(RESERVED)).toBe(true);
    });
});
