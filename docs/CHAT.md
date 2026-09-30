# The Scolta chat: adapter contract

The chat is a conversation grounded in the site's own pages. The browser ranks pages for each turn with `Scolta.createRetriever()` (the search page's own pipeline, since Pagefind and the WASM module only run there) and posts them with the message; the server keeps the thread, calls the model and streams the answer back. Everything that decides what the model sees lives in scolta-php: `Tag1\Scolta\Http\ChatEndpointHandler` and the classes under `Tag1\Scolta\Chat`. An adapter wires routes, access and storage to it, and nothing else. It is experimental in 2.0.0.

## Routes

All under `/api/scolta/v1/chat/` by default (the browser reads the paths from `window.scolta.chat.endpoints`, so an adapter may mount them elsewhere).

| Route | Method | Handler | Body | Reply |
|---|---|---|---|---|
| `plan` | POST | `handlePlan()` | `{thread_id, message, seed?, page?}` | `{query, needs_search, terms}` |
| `turn` | POST | `streamTurn()` or `handleTurn()` | `{thread_id?, message, needs_search, pages, page?, seed?}` | server-sent events, or `{thread_id, answer, sources, fold}` |
| `fold` | POST | `handleFold()` | `{thread_id}` | `{folded}` |
| `thread` | GET | `handleThread()` | optional `thread_id` query parameter | `{thread_id, messages: [{role, content, sources}]}` |
| `thread` | DELETE | `handleReset()` | none | `{thread_id}` |

Every handler returns `{ok, data, status, error}` like `AiEndpointHandler`, and answers 404 while `chat_enabled` is off, so an adapter needs no switch of its own. `plan` goes to the model only after the first turn: the first turn's browser calls `expand-query` instead, which caches by query.

`pages` is what `retriever.buildContext()` returns: `[{n, tier, title, url, excerpt}]`. The handler renumbers them, drops any URL that is not absolute http(s) on one of the allowed hosts, and caps counts and text by the site's `chat_*` limits. `page` is `{url, title, description, text}` for the page the visitor is reading, and `seed` is `{query, summary, pages}` from the search page's follow up box, accepted only on a new thread.

## The stream

With `Accept: text/event-stream`, send what `streamTurn()` yields, one event per pair, formatted with `ServerSentEvent::format()` (the data is JSON, so a newline in model text cannot start a new event):

| Event | Data |
|---|---|
| `thread` | `{thread_id}`, always first |
| `delta` | `{text}`, many |
| `sources` | `{pages: [{n, title, url}]}`, only the pages the answer cited, in order of first citation |
| `done` | `{fold}`, true when the browser should call `fold` |
| `error` | `{message, status, retry_after?}`, instead of the rest |

Read the first event before committing to a stream: a refused request (404, 403, 400) yields a single `error` first, so it can go out as a JSON response with that status.

## What an adapter provides

- **Owner.** `ChatOwner::forUser($uid)` for a signed in user, else `ChatOwner::fromCookie($cookieValue)`. When `newToken()` is not null, set the cookie `cookie($isHttps, $chatThreadTtl)` describes: `scolta_chat`, HttpOnly, Secure on https, SameSite Lax, Path `/api/scolta/v1/chat`, Max-Age the thread lifetime. Only chat responses ever carry it. Never start a session for the chat: a session per anonymous visitor turns the page cache off for them, and resolve the owner before a stream starts.
- **Header.** Pass whether the request carried `X-Scolta-Chat: 1`; without it every handler answers 403. A cross site form cannot send it without a CORS preflight. Add the platform's own CSRF check on top for requests with a session (the browser sends any header named in `window.scolta.chat.csrf: {header, tokenUrl}`).
- **Access and limits.** A permission on every route, and a rate limit. One chat message costs up to three calls (`expand-query` or `plan`, `turn`, `fold`), so budget three per message where search budgets one per query.
- **Storage.** A `ThreadStoreInterface` over whatever expires on the platform (Drupal's `keyvalue.expirable`, a transient, a cache with TTL). Keys come from `ChatOwner` already hashed.
- **Allowed hosts.** The site's own hosts; page URLs on any other host are dropped.
- **Flushing.** Turn off output buffering and compression for the stream, and flush each event as it is yielded (`X-Accel-Buffering: no` for nginx).
- **Assets.** `assets/js/scolta-chat.js`, `assets/css/scolta-chat.css` and `assets/vendor/deep-chat/deepChat.bundle.js` (deep-chat 2.4.2, MIT, `LICENSE` beside it), listed in `assets/ASSETS.sha256`. Load `scolta-chat.js` after `scolta.js`, and set `window.scolta.chat.deepChatPath` to the bundle's URL: the widget imports it when the browser is idle or the launcher is approached, never at page load. A theme can move the launcher with the CSS custom properties `--scolta-chat-right` and `--scolta-chat-bottom`.

`AiControllerTrait::createChatHandler()` builds the handler with the controller's cache and generation, so a reindex invalidates cached opening answers.
