# AI Summary Layout

## What it is

The AI summary sits above the result list, and it arrives late: the summarize
call is deliberately deferred until query expansion settles, so the model ranks
what the user sees. By then the results are painted. Inserting the summary
above them pushes the whole list down, and every pixel of that push is
cumulative layout shift.

It pushed twice, not once:

| Insertion | Result list moved | CLS |
|---|---|---|
| `display:none` → loading skeleton | +177px | 0.120 |
| Skeleton → resolved summary | +342px | 0.317 |

That is 0.437 against a "good" threshold of 0.1, measured on a rich-card page.

The first of those is invisible to any test harness that stubs query expansion
faster than 500ms: the browser credits a shift within 500ms of a click to the
user and excludes it. A real expansion is an LLM round trip and is not that
fast, so in production both insertions count. Any harness measuring this must
use realistic latency or it will flatter the widget.

## How it is fixed

The slot takes a fixed height in the same frame the result list paints in, and
holds it through loading, resolution and error alike. Nothing below it moves,
so the shift is zero by construction rather than by arithmetic that has to be
kept in sync with the markup.

The panel is a flex column with a fixed height and `overflow: hidden`. The
text region is the only flexible child, so it absorbs whatever the chrome
around it does or does not occupy — the loading skeleton has no follow-up row,
the error affordance has neither that nor a disclaimer, and the panel is still
exactly the same height in all of them.

A summary taller than the box is clipped and offers a **Show more** control.
The full text is always in the DOM, clipped rather than truncated, so
find-in-page and assistive tech reach all of it in either state. Expanding is
user-initiated, so the resulting shift is excluded from the metric by
definition and the whole summary stays reachable for free.

### States

| State | Slot |
|---|---|
| Summary disabled | Not reserved at all; byte-identical layout to before this existed |
| Loading | Reserved, skeleton fills the box |
| Resolved, fits | Reserved, no control |
| Resolved, overflows | Reserved, clipped, **Show more** |
| Expanded by the user | Auto height, **Show less** |
| Follow-up asked | Auto height (the answer would otherwise render inside a clipped box) |
| Error | Reserved; sized inside the box, because collapsing is an upward shift and counts too |
| Empty (model returned nothing) | Collapsed, matching the disabled case — see the trade-off below |

The empty case is the one state that does not reach zero. Collapsing an
already-reserved box is an upward shift that no user input can be credited
for: measured 0.215, against 0.240 for the same case before the reservation
existed. The alternative is a labelled empty box that never collapses — zero
shift, permanent dead space whenever a summary comes back empty. Collapsing is
the chosen trade.

There is no streaming summary path in the bundle today; the summarize response
is a single JSON fetch. If one is added, tokens filling the fixed box shift
nothing, for free.

## Theming

Tune the reservation by overriding the line count. No config key, no schema, no
rebuild — it is CSS, like the `--scolta-sayt-*` properties.

| Property | Default | What it does |
|---|---|---|
| `--scolta-summary-collapsed-lines` | `6` | Text lines the collapsed slot budgets for |
| `--scolta-summary-line-height` | `1.7` | Panel line height; also the line unit for the budget |
| `--scolta-summary-font-size` | `0.95rem` | Panel font size; also the line unit for the budget |
| `--scolta-summary-chrome` | `6.5rem` | Allowance for the label, follow-up row and panel padding |
| `--scolta-summary-collapsed-height` | derived | The reserved height itself |

```css
/* Six lines is too tight for our summaries. */
#scolta-search { --scolta-summary-collapsed-lines: 9; }
```

The text region takes whatever the chrome leaves, so the visible line count
tracks the budget closely rather than exactly.

`--scolta-summary-collapsed-height` is derived **on the panel**, not on
`:root`. A custom property resolves its `var()` references against the element
it is declared on, so deriving it at `:root` would bake in `:root`'s line count
and an override further down the tree would change nothing. Declared on the
panel, it picks up an override from any ancestor.

The font size comes from the property rather than from `1em` on purpose: the
error state restyles the panel to a smaller font, and an em-based reservation
would therefore reserve a different height for an error than for a summary,
which is itself a shift.

### Stable classes

| Class | What it is |
|---|---|
| `.scolta-ai-summary--reserved` | On the panel whenever the slot holds its fixed height |
| `.scolta-ai-summary--clamped` | On the panel only when the summary actually overflows; carries the fade mask |
| `.scolta-ai-summary-toggle` | The **Show more** / **Show less** button |
| `.scolta-ai-shimmer` | One skeleton bar, sized to one text line inside a reserved slot |

The control is a real `<button type="button">` with `aria-expanded` and
`aria-controls` pointing at the summary text region, keyboard operable, with a
label that updates.

### Opting out

```css
.scolta-ai-summary { --scolta-summary-collapsed-height: auto; }
```

The slot then sizes to its content as it did before. This reintroduces the
layout shift documented at the top of this file; it is your call.

## Do not

- Do not add a config key for the collapsed height. It is a theming decision,
  it belongs in CSS, and bridging it would drag a schema, a form field and a
  `toBrowserConfig()` parity change onto every adapter for no gain.
- Do not set an inline `display` on `#scolta-ai-summary` to show it. The
  stylesheet makes the panel a flex column; an inline `display: block` outranks
  that and silently un-reserves the slot. Clear the inline display instead.
- Do not entangle the reservation with the results write path. The slot is a
  sibling of `#scolta-results` and the keyed reconcile must stay untouched.

## Tests

- `tests/js/summary-cls-reservation.test.js` — the contract in JSDOM: which
  state reserves, the clamp and its ARIA, the full text present in both states,
  the follow-up release.
- `tests/E2E/summary-cls.spec.js` — the pixel truth in real Chromium, reading
  the browser's own layout-shift entries with realistic expansion latency.
  JSDOM has no layout and cannot see any of this.
