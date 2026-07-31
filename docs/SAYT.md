# Search As You Type

## What it is

Typing in the Scolta search box opens a dropdown of suggestions under the input.
Selecting one either goes straight to that result or runs a full search for it.
Typing alone never runs a search.

That distinction is the whole design. Scolta's search pipeline is expensive and
multi-phase — a Pagefind search, an AI query expansion, a merge, a facet-count
pass, an AI summary, and a follow-up conversation — and it exists to answer a
question the user has finished asking. SAYT is a different job: cheap, immediate,
and disposable. So the two are separate machines that share nothing but the
input element and the Pagefind index.

| | Suggest cycle | `doSearch()` |
|---|---|---|
| Trigger | every keystroke, debounced | Enter, the search button, a suggestion |
| Pagefind searches | one (plus per-word on the OR fallback) | primary, OR fallback, facet counts, per-expansion-term |
| Fragments loaded | at most `sayt_max_suggestions` | up to `max_pagefind_results` per term |
| AI calls | at most one expansion, budgeted | expansion, summary, follow-ups |
| Writes to `#scolta-results` | never | always |
| Writes to `history` | never | always |
| Cancellation | a version counter | version counter + `AbortController` |

The suggest path touches the dropdown and nothing else. It never writes
`#scolta-results`, the results header, the facet panel or the URL, and it never
triggers a summary.

## Settings

All ten are top-level browser settings, not scoring keys. In a CMS they are
snake_case (`sayt_min_chars`); in `window.scolta` they are camelCase
(`saytMinChars`). Defaults are listed in
[`CONFIG_REFERENCE.md`](CONFIG_REFERENCE.md) and repeated here with the reason
each one exists.

| Setting | Default | What it is for |
|---|---|---|
| `sayt_enabled` | `true` | The off switch. See below. |
| `sayt_min_chars` | `2` | How much the user must type before anything is fetched. |
| `sayt_debounce_ms` | `150` | Trailing debounce before a suggest cycle fires. |
| `sayt_max_suggestions` | `6` | Rows shown, and the hard cap on fragment loads per pass. |
| `sayt_recent_searches` | `true` | Offer the visitor their own recent searches. |
| `sayt_max_recent` | `3` | How many recent searches are shown. |
| `sayt_expand` | `true` | Enrich the dropdown with AI expansion matches. |
| `sayt_expand_per_minute` | `6` | Client-side budget on those AI calls. |
| `sayt_expansion_delay_ms` | `500` | Idle time before the AI call fires. |
| `sayt_suggestion_action` | `navigate` | What selecting a title suggestion does. |

### The off switch

`sayt_enabled: false` restores the pre-1.1.0 widget exactly. There is no
dropdown element in the scaffold, no `role="combobox"` on the input, no
`localStorage` access on any path, and the `input` listener does what it always
did: toggle the clear button and warm the index chunk. Existing indexes need no
rebuild either way — SAYT reads the same fragments the result list does.

### Minimum characters and CJK

`sayt_min_chars` counts **graphemes**, not UTF-16 code units. `"🇮🇹"` is one
character to the person who typed it, two code points, and four `.length` units;
counting units would fire a search on a single emoji at a floor of two.
`Intl.Segmenter` does the counting where it exists, with a spread (`[...str]`)
fallback for older engines.

Sites in Chinese, Japanese or Korean generally want `sayt_min_chars: 1`. A
single han character is already a meaningful query, and a floor of two means a
CJK visitor gets no suggestions until their query is twice as specific as an
English speaker's needs to be.

### The two suggestion actions

`navigate` (the default) renders each title suggestion as a real anchor pointing
at that result. Middle-click, ctrl-click, the browser's status bar and "copy link
address" all work, because it is a link rather than a JavaScript handler
pretending to be one. Choose it when suggestions are documents the visitor wants
to open — documentation, a knowledge base, a product catalogue.

`search` puts the suggestion's title into the box and runs the full search for
it, AI summary and all. Choose it when the value is in the *result set* rather
than the single document — a news archive, a large catalogue where the top hit
is rarely the whole answer, or any site where the AI overview is the feature
visitors come for.

A **recent search** suggestion always runs the search regardless of this
setting: navigating to a stored query string is meaningless.

An unrecognized value clamps to `navigate` and logs one warning. PHP clamps too,
in `ScoltaConfig::normalizedSaytSuggestionAction()`, so a typo in a settings form
never reaches the page payload.

### The AI expansion budget

When the input has been idle for `sayt_expansion_delay_ms` and the dropdown is
open, SAYT may make one `expandQuery()` call for the settled prefix and merge
the resulting documents into the dropdown.

`sayt_expand_per_minute` is a client-side sliding window over those calls, and it
exists because **SAYT expansions share the platform's AI flood budget with
committed searches**. On Drupal the default is 60 AI requests per IP per minute,
shared across expansion, summarize and follow-up. An unbudgeted suggest path
would spend a visitor's entire allowance on prefixes they never submitted, and
then the search they actually ran would come back with no expansion and no
summary. Suggestions are a convenience; the search is the product. The budget
makes sure the convenience can never starve it.

When the cap is hit the dropdown silently degrades to keyword-only suggestions
until the window rolls. There is one `debugLog` line and no user-visible error,
because there is nothing useful to tell someone mid-keystroke.

Every other enrichment failure degrades the same way: a network error, an
aborted request, a degraded server response, an expansion with no new terms, or
a cycle superseded while the call was in flight all leave the keyword
suggestions that are already on screen exactly as they are.

Expansion is served through the platform endpoints, cached server-side for a
month, and run at temperature 0, so a repeated prefix is a cache hit rather than
a new model call.

## Behaviour

### The suggest cycle

1. The `input` listener toggles the clear button and calls `schedulePreload()`,
   exactly as before, then starts a trailing debounce of `sayt_debounce_ms`.
2. Below `sayt_min_chars` graphemes, the dropdown closes and nothing is fetched.
3. On fire, the cycle takes a version number and runs `pagefind.search(prefix, {})`.
4. If a multi-word prefix matches nothing, it re-runs per word and unions the
   results by fragment id, scoring them at reduced weight — the same AND-to-OR
   shape the result list uses, so a half-typed second word still suggests.
5. It loads at most `sayt_max_suggestions` fragments, scores titles and excerpts
   through the same scoring path the result list uses, and dedupes by title.
6. Matching recent searches go first, then title suggestions, capped in total.
7. It renders, then schedules the enrichment call.

The version number is re-checked after every await. A cycle that has been
superseded — by newer input, by a cleared input, or by a committed search —
returns without performing a single DOM write or loading a further fragment.
Cancelling is just an increment, which is why the suggest path needs no
`AbortController` of its own.

No suggest cycle runs between the start of a `doSearch()` cycle and its primary
paint. The user has committed; a dropdown repainting over a search in flight is
noise.

### Keyboard and pointer

| Input | Result |
|---|---|
| ArrowDown / ArrowUp | move the active option, wrapping at both ends |
| Enter, an option active | act on that option |
| Enter, nothing active | run `doSearch()`, exactly as before SAYT existed |
| Escape | close the dropdown, leaving the input alone |
| Escape again | not consumed; falls through to the page |
| Click an option | act on it |
| Blur | close after a short delay, so a click on an option still lands |

DOM focus never leaves the input. The active option is tracked with
`aria-activedescendant` on the input plus `aria-selected` on the option, which
is the ARIA combobox pattern: a screen reader announces the highlighted row
while typing keeps working.

The input carries `role="combobox"`, `aria-autocomplete="list"`,
`aria-expanded` and `aria-controls`; the dropdown is a `role="listbox"` whose
children are `role="option"` with stable ids.

### Recent searches

Committed queries are stored in `localStorage` under a single key,
`scolta:recent-searches`. `localStorage` is per-origin, so several Scolta
instances on one origin share one history. That is deliberate: a visitor's
recent searches are a property of the visitor, not of the widget.

At most five are stored; at most `sayt_max_recent` that prefix-match or
substring-match the current input are shown, prefix matches first. Stored values
are strings the visitor typed, so they are escaped on render exactly like index
metadata.

Every storage access is wrapped. Safari private browsing throws on write, some
enterprise policies throw on read, and a corrupt or hand-edited value parses to
nothing. None of it may break the search box, so all of it degrades to "no
recent searches".

With `sayt_recent_searches: false` there are no reads and no writes.

## Events

Two events, matching the [render seam](RENDER_SEAM.md) conventions exactly:
dispatched on the dropdown element, bubbling, non-cancellable, and a listener
that throws is caught and logged rather than taking the render down.

| Event | Fires | `detail` |
|---|---|---|
| `scolta:before-suggestions-render` | immediately before the dropdown is written | `{ container, query }` |
| `scolta:suggestions-rendered` | immediately after | `{ container, suggestions, query }` |

`container` is the dropdown element and is always identical to `event.target`.
`suggestions` is the rendered model in DOM order; each entry has `type`
(`recent` or `title`), `title`, `url`, `safeUrl` and `excerpt`. `title`, `url`
and `excerpt` are **raw** — they are there to compare or to build a request, not
to paste into markup.

```js
document.addEventListener('scolta:suggestions-rendered', (e) => {
  analytics.track('suggestions_shown', {
    query: e.detail.query,
    count: e.detail.suggestions.length,
  });
});
```

There is deliberately **no suggestion-renderer registration API** in this
release. The result renderer exists because platforms have rich, entity-specific
result cards; a suggestion row is a title and an excerpt, and nobody has asked
to own that markup yet. Use CSS.

## Theming

The dropdown is styled in `assets/css/scolta.css` through CSS custom properties,
so a platform theme restyles it without editing a vendored file and without a
specificity war — nothing below uses a selector more specific than one class.

| Property | Default |
|---|---|
| `--scolta-sayt-bg` | `var(--scolta-card-bg)` |
| `--scolta-sayt-border` | `var(--scolta-border)` |
| `--scolta-sayt-shadow` | `0 6px 20px rgba(0, 0, 0, 0.12)` |
| `--scolta-sayt-radius` | `8px` |
| `--scolta-sayt-max-height` | `22rem` |
| `--scolta-sayt-active-bg` | `var(--scolta-badge-bg)` |
| `--scolta-sayt-z-index` | `50` |

The classes are `.scolta-sayt` (the listbox), `.scolta-sayt-option`,
`.scolta-sayt-option-recent`, `.scolta-sayt-option-active`, `.scolta-sayt-kind`,
`.scolta-sayt-title` and `.scolta-sayt-excerpt`.

The dropdown is absolutely positioned inside the input wrapper, so opening,
updating and closing it cause zero layout shift.

## Do not

- **Do not pass a filters object to a suggest search.** Naming a dimension makes
  Pagefind fetch that dimension's filter chunk, and once a chunk is loaded every
  later search costs `matched results x loaded postings` — with no unload path
  short of `pagefind.destroy()`. A keystroke-rate path must never be what
  triggers that. Suggestions are query completion, not a filtered result list,
  so they run against the whole index and ignore active facets.
- **Do not make an AI call per keystroke.** Enrichment fires once per settled
  prefix, after an idle delay, under a per-minute budget.
- **Do not write `history` from the suggest path.** Typing is not navigation, and
  a back button full of prefixes is worse than useless.
- **Do not route the suggest path through `pagefindSearch()`.** That memo belongs
  to the `doSearch()` cycle and applies the user's facet selections.
- **Do not skip a staleness check after an await.** Every one of them is load
  bearing; the tests in `tests/js/sayt.test.js` pin each await point separately.

## Tests

- `tests/js/sayt.test.js` — the suggest cycle, staleness at every await point,
  the keyboard and pointer contract, both suggestion actions, recent searches,
  the expansion budget, and the off switch.
- `tests/js/security-render.test.js` — hostile fragment titles, excerpts,
  expansion terms and stored values.
- `tests/js/result-count-baseline.test.js` — a tripwire on fragments loaded per
  suggest cycle.
- `tests/E2E/sayt.spec.js` — the whole feature in a real browser against an index
  built by the PHP indexer.
- `tests/ScoltaConfigTest.php` — the ten properties, and an assertion that each
  PHP default matches the browser bundle's fallback literal.
