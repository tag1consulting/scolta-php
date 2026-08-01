# The Render Seam

## What it is

Scolta's browser bundle owns the search UI: it builds the search box, the filter
panel and the result list, and it repaints the result list whenever the ranking
changes. That is fine when Scolta's own result card is the card you want. It is
a wall when your platform already knows how to render a result — a thumbnail,
taxonomy badges, a rating, an author, a bookmark control — and you want that
markup instead.

The render seam is how a platform takes over. It has three parts:

1. **Lifecycle events** so you know when the list is about to change and when it
   has changed.
2. **A result renderer, and a suggestion renderer** so you supply the markup for
   each result and each search-as-you-type row in the first place, instead of
   rewriting Scolta's markup after the fact.
3. **A non-destructive mount** so server-rendered markup inside the container
   survives initialisation.

The seam is platform neutral by design. Nothing in `assets/js/scolta.js` knows
about Drupal, WordPress, Laravel or Django, and nothing should be added that
does. `PromptEnricherInterface` is the precedent on the PHP side: a narrow,
documented extension point, no platform names in the core.

## 1. Lifecycle events

Six events fire on the element being written, and they bubble, so a single
listener on the mount point (or on `document`) sees every render.

| Event | Fires | `detail` |
|---|---|---|
| `scolta:before-results-render` | immediately before any write to `#scolta-results` | `{ container, reason }` |
| `scolta:results-rendered` | immediately after that write completes | `{ container, results, rendered, reused, appended, query }` |
| `scolta:before-filters-render` | immediately before the `#scolta-filters` write | `{ container }` |
| `scolta:filters-rendered` | immediately after it | `{ container }` |
| `scolta:before-suggestions-render` | immediately before the `#scolta-sayt` write | `{ container, query }` |
| `scolta:suggestions-rendered` | immediately after it | `{ container, suggestions, query }` |

`container` is the element being written and is always identical to
`event.target`.

`reason` is one of:

| `reason` | The write it describes |
|---|---|
| `loading` | the "Searching..." placeholder, at the top of a search and while an expansion request is still in flight |
| `search` | the first paint of a query's results |
| `expansion` | the repaint after AI query expansion resolves |
| `append` | the additive "show more" write |

On `scolta:results-rendered`:

- `results` — every scored result in the DOM after this write, in DOM order.
- `rendered` — the slice this write produced (on a full paint that is all of
  `results`; on an append it is only the new page).
- `reused` — the results whose DOM node was carried over rather than rebuilt.
  See "Reconciliation" below; on an append this is always empty because nothing
  was rebuilt in the first place.
- `appended` — `true` only on the additive "show more" path.
- `query` — the current query string.

On `scolta:suggestions-rendered` (search as you type, added in 1.1.0 — see
[`SAYT.md`](SAYT.md)):

- `query` — the prefix these suggestions describe, which is **not** the
  committed query and never appears in `#scolta-results`.
- `suggestions` — the rendered model in DOM order. Each entry has `type`
  (`recent` or `title`), `title`, `url`, `safeUrl`, `excerpt` and `meta`.
  `title`, `url` and `excerpt` are raw, for comparing or building a request;
  `safeUrl` is the attribute-escaped, scheme-neutralized value the option's
  `href` carries.
- `meta` is the fragment's whole metadata map — a thumbnail URL, an entity id,
  anything the index carries — the same surface the result renderer sees as
  `data.meta`. It is **raw** index content, so escape any value you put into
  markup. A recent-search suggestion has no fragment behind it and carries
  `meta: {}`, so the field is always present and never needs feature-testing.

The suggest path fires only these two events. It never writes `#scolta-results`
or `#scolta-filters`, so it never emits the four above — a listener that only
cares about committed searches needs no filtering.

The `before` events exist so you can detach your own behaviours before the nodes
they are bound to are destroyed. They are **not cancellable**: a render a
consumer could veto would make every state assumption downstream conditional,
and nothing needs that today.

A listener that throws is caught and logged; it never takes the render down.

```js
const root = document.querySelector('#scolta-search');

root.addEventListener('scolta:before-results-render', (e) => {
  if (e.detail.reason === 'append') return;   // existing nodes survive an append
  teardownMyBehaviours(e.detail.container);
});

root.addEventListener('scolta:results-rendered', (e) => {
  const fresh = e.detail.rendered.filter(r => !e.detail.reused.includes(r));
  enrich(fresh);
});
```

## 2. The result renderer

Events alone are enough to work — you can rewrite the cards after Scolta paints
them — but the user sees the built-in card first and your replacement second.
Registering a renderer means your markup is what gets painted.

```js
Scolta.setResultRenderer(function (data, ctx) {
  return `<article class="my-card" data-entity="${data.meta.entity_id}">
            <div class="my-card__body" data-placeholder></div>
            <div class="my-card__excerpt">${ctx.excerptHtml}</div>
          </article>`;
});
```

`data` is the raw Pagefind fragment object. `ctx` carries:

| Key | What it is |
|---|---|
| `index` | position of this result in the full list |
| `query` | the current query, **raw** |
| `highlightTerms` | array of highlight terms, **raw** |
| `excerptHtml` | the escaped, `<mark>`-wrapped excerpt the built-in card would have shown |
| `titleHtml` | the escaped, truncated, `<mark>`-wrapped title text |
| `titleAttr` | attribute-escaped full title, for `title="…"` |
| `safeUrl` | attribute-escaped URL with non-`http(s)` schemes neutralized |
| `urlText` | html-escaped URL, as link text |
| `siteHtml` | html-escaped site badge value, or `""` |
| `dateHtml` | html-escaped date, or `""` |
| `score` | this result's score |

Return an HTML string, or `null` to fall back to the built-in card **for that
one result** — the right answer when your platform can render some result types
and not others. A renderer that throws also falls back, with a console warning:
one bad result never takes the list down.

There is also a per-instance form, which wins over the global one for that
instance:

```js
const inst = Scolta.createInstance('#my-search', config);
inst.setResultRenderer(fn);
```

Pass `null` to either to restore the built-in card.

### Escaping is yours from here

The string you return is inserted as markup, so from that point your renderer
owns its own escaping. Every `ctx` value whose name ends in `Html`, `Attr` or
`Text`, plus `safeUrl`, is **already escaped exactly as the built-in card
escapes it** — composing from those is both the safe path and the easy one.
`query` and `highlightTerms` are raw, because they exist for building requests
and comparing terms, not for pasting into markup. Escape them yourself if you
put them on the page.

Scolta's own card is unchanged: the default path escapes and strips exactly what
it always did. The renderer path is opt in, and its owner accepts responsibility.

### Delegated handlers keep working

Scolta binds its click and change handlers **once, on the mount point**, and
dispatches on `data-scolta-*` attributes. Platform markup carrying those
attributes therefore keeps working with no re-binding after any render — that is
part of the supported contract, not an accident:

```js
`<span data-scolta-search-term="cardiology">cardiology</span>`
```

### Why a registration function and not a config key

A function cannot survive the PHP → `ScoltaConfig::toBrowserConfig()` →
platform settings → JSON round trip. Adding a browser config key that no PHP
layer emits would also need a `BrowserConfigParityTest::REVERSE_ALLOWLIST` entry
with a written justification, because that test diffs what the bundle reads
against what PHP emits in both directions. Registration keeps the config surface
untouched and the parity test green. **Do not convert this into a config key.**

### Lazily loading server-rendered markup

The pattern this seam was built for: render a placeholder synchronously, fetch
the real markup, swap it in.

```js
Scolta.setResultRenderer((data, ctx) => `
  <article class="my-card" data-src="/render/${encodeURIComponent(data.meta.entity_id)}">
    <div class="my-card__excerpt">${ctx.excerptHtml}</div>
  </article>`);

document.addEventListener('scolta:results-rendered', (e) => {
  for (const node of e.detail.container.querySelectorAll('[data-src]:not([data-loaded])')) {
    fetch(node.dataset.src)
      .then(r => r.text())
      .then(html => {
        const excerpt = node.querySelector('.my-card__excerpt');
        node.innerHTML = html;
        // The excerpt is a Scolta advantage worth keeping: ctx.excerptHtml was
        // already escaped and highlighted, so restoring it costs nothing.
        if (excerpt) node.appendChild(excerpt);
        node.dataset.loaded = '1';
      });
  }
});
```

### The suggestion renderer

The search-as-you-type dropdown has the same seam, for the same reason: a
platform that wants a thumbnail, a content-type badge or a price on each
suggestion row should paint it, rather than wait for Scolta's row and rewrite
it. See [`SAYT.md`](SAYT.md) for the feature itself.

```js
Scolta.setSuggestionRenderer(function (suggestion, ctx) {
  if (suggestion.type === 'recent') return null;          // built-in row is fine
  const img = suggestion.meta.image;                      // raw — escape it
  return `${img ? `<img class="my-sayt__thumb" src="${escapeAttr(img)}" alt="">` : ''}
          <span class="my-sayt__title">${ctx.titleHtml}</span>
          <span class="my-sayt__excerpt">${ctx.excerptHtml}</span>`;
});
```

`suggestion` is the same object `scolta:suggestions-rendered` carries: `type`,
`title`, `url`, `safeUrl`, `excerpt` and `meta`, described above. `ctx` carries:

| Key | What it is | Result-renderer twin |
|---|---|---|
| `index` | position of this suggestion in the dropdown | `index` |
| `query` | the prefix being suggested on, **raw** | `query` |
| `titleHtml` | the escaped title the built-in row would have shown | `titleHtml` |
| `excerptHtml` | the escaped, truncated excerpt, or `""` on a recent search | `excerptHtml` |
| `safeUrl` | attribute-escaped URL with non-`http(s)` schemes neutralized, the same value the option's `href` carries in navigate mode; `""` on a recent search | `safeUrl` |

The names are the result renderer's, and mean the same things. The result `ctx`
keys with no suggestion counterpart are the ones a suggestion row has no
equivalent for: `highlightTerms` (the suggest path does not highlight, and the
terms on the instance belong to the committed search cycle, not to this one),
`score`, and the `titleAttr`, `urlText`, `siteHtml` and `dateHtml` values the
built-in card renders and the built-in row does not.

A recent-search suggestion gets `safeUrl: ""` rather than a URL. It has no
destination: acting on one runs the search in place, so there is no href
anywhere in the bundle for it, and minting one here would hand a renderer a link
that navigates where clicking the row does not.

Return an HTML string, or `null` to fall back to the built-in row **for that one
suggestion** — the example above uses that to keep Scolta's row for recent
searches and own only the index matches. A renderer that throws also falls back,
with a console warning.

There is a per-instance form, and `null` restores the built-in row:

```js
inst.setSuggestionRenderer(fn);
Scolta.setSuggestionRenderer(null);
```

**You own the inside of the row, not the row.** Scolta keeps the option element
itself: `role="option"`, the stable `id` the input's `aria-activedescendant`
points at, `aria-selected`, `data-scolta-sayt-index`, and in `navigate` mode the
anchor and its sanitized `href`. Those are the ARIA combobox and keyboard
contract, and a renderer that forgot one of them would break arrow-key
navigation and screen-reader announcement silently, on a path nobody tests by
hand. Style the row through `.scolta-sayt-option` and the custom properties in
[`SAYT.md`](SAYT.md#theming); build what goes inside it with the renderer.

Escaping works exactly as it does for the result renderer, and the rule is the
same one: every `ctx` value whose name ends in `Html`, `Attr` or `Text`, plus
`safeUrl`, is **already escaped** exactly as the built-in row escapes it, and
everything else is raw. Raw here means `ctx.query` and everything on
`suggestion` — `title`, `url`, `excerpt` and every `meta` value — all of which
is index or visitor content your renderer must escape before it reaches markup.

Like `setResultRenderer`, this is a registration function and **not** a config
key, for the reason given above. Registering nothing changes nothing: the
built-in row is byte-identical to the one Scolta shipped before this API existed.

## 3. Non-destructive mount

`init()` used to run `root.innerHTML = scaffold`, destroying anything already
inside the mount point. `autoInit()` guarded on `!container.hasChildNodes()`, but
a platform bridge calling `Scolta.init()` directly bypassed that guard entirely,
so server-side rendering into the container silently lost its markup.

Now:

- Existing children are **left where they are**, with node identity intact —
  listeners, bindings and swapped-in markup all survive.
- The search UI is inserted **at the top** of the container.
- A previous Scolta scaffold in the same container is removed first, so a
  re-init cannot mint duplicate ids.
- `destroy()` removes only the nodes `init()` inserted.
- `autoInit()` now refuses only a container that already holds a Scolta
  scaffold, not any container with children.

Every node the bundle inserts carries `data-scolta-scaffold`. To get the old
clear-everything behaviour back, put `data-scolta-replace` on the mount element:

```html
<div id="scolta-search" data-scolta-replace></div>
```

That is a DOM attribute rather than a config key on purpose: markup decisions
belong on the platform side of the seam.

## Reconciliation: what a repaint actually costs

A search paints the result list twice — once from the primary query, and again
after AI query expansion resolves one to two seconds later. The second paint
used to replace the whole container unconditionally, which threw away anything a
platform had lazily loaded into the first one and made it do the work again.

The repaint now reconciles by result identity (the result's URL). The container
is walked in place: a node already sitting where it belongs is left completely
alone, a node that belongs elsewhere is **moved** with `insertBefore()` (which,
on a node already in the document, moves rather than clones or re-parses it, so
listeners and swapped-in markup ride along), and only genuinely new results are
built. `detail.reused` on `scolta:results-rendered` lists exactly which results
kept their node.

When the expansion pass returns the same results in the same order — the common
case — the reconcile performs **zero DOM mutations**. Leaving an unmoved node
untouched rather than detaching and re-attaching it is the point: a detach and
re-attach restarts CSS transitions, reloads an iframe, and fires
`disconnectedCallback` / `connectedCallback` on a custom element.

Two limits worth knowing:

- **Built-in cards are only carried over while the highlight terms have not
  moved.** Expansion adds highlight terms, so a built-in card painted before it
  carries `<mark>` spans that are now stale, and is rebuilt. A card from a
  platform renderer is never rewritten by Scolta, so it is always eligible.
- **Reuse is scoped to one search cycle.** Every `doSearch()` opens with the
  "Searching..." placeholder, which is a real teardown — under a new query or a
  new facet set, the list on screen is no longer the list the user asked for.
  You will see `reason: "loading"` and know your nodes are gone.

## Testing

`tests/js/render-seam.test.js` drives the real bundle in JSDOM and pins all of
the above: event order and `reason` values, the `detail` shape, renderer
fallback on `null` and on a throw, `ctx` escaping, delegated handlers inside
renderer markup, mount-point preservation, and node identity across the
expansion repaint. It also pins the suggestion renderer: one call per row,
fallback on a null return and on a throw, global-versus-instance precedence, and
that the ARIA and keyboard attributes survive a renderer that returns markup of
its own. `tests/js/sayt.test.js` pins the two suggestion events the same way —
target, bubbling, non-cancellability, `detail` shape, a listener which throws not
taking the render down — plus `suggestion.meta` reaching a listener intact.
