# Upgrade Guide

Breaking changes and migration steps between versions of scolta-php.

## Unreleased

### The results header carries a visitor-facing expansion switch

Nothing breaks and no code needs to change, but the control is on by default, so a site running query expansion will see a new link inside the result-count sentence after upgrading the bundle — `8 results for "search" (with expanded terms - disable)`, and `8 results for "search" - expand terms` once turned off. A visitor's choice lives in their own browser (`localStorage`, key `scolta:expansion-disabled`); nothing server-side reads it, so it starts no session and is invisible to page and edge caches.

Set `expansionToggle` to `false` to keep expansion running with no visitor-facing control over it:

```php
$config->expansionToggle = false;
// or: ScoltaConfig::fromArray(['expansion_toggle' => false])
```

The switch can only narrow. Where `aiExpandQuery` is false — or a platform's own access rule refused this account, which reaches the browser the same way — no control is rendered and no browser-held value can turn expansion back on.

## 1.2.0

### `AmazeeCredentials` no longer takes `operatorChosen`

`operatorChosen` is gone from the constructor, `fromStorage()` and `fromArray()`, replaced by an `AmazeeConnectionSource|null` the credential store records at connect time. The class is `@stability experimental`, so this lands in a minor.

```php
// 1.1.0
__construct(string $token, string $baseUrl = '', bool $operatorChosen = false, bool $modelResolved = true)
fromStorage(ConfigStorageInterface $storage, bool $operatorChosen = false, bool $modelResolved = true): ?self
fromArray(?array $stored, bool $operatorChosen = false, bool $modelResolved = true): ?self

// 1.2.0
__construct(string $token, string $baseUrl = '', bool $modelResolved = true, ?AmazeeConnectionSource $connectionSource = null)
fromStorage(ConfigStorageInterface $storage, bool $modelResolved = true): ?self
fromArray(?array $stored, bool $modelResolved = true, ?AmazeeConnectionSource $connectionSource = null): ?self
```

- Drop the `operatorChosen:` named argument; it now throws. Pass `connectionSource:` instead, or let `fromStorage()` read it from a store implementing `ProvenanceAwareConfigStorageInterface`.
- `fromStorage()` takes no connection-source argument at all.
- **Positional callers must recount.** `modelResolved` shifted one slot left everywhere. `new AmazeeCredentials($token, $url, true)` used to set `operatorChosen` and now sets `modelResolved`, with no error at any layer.
- The class is `final`, so there is no subclass to update.

Credentials stored before 1.2.0 report `connectionSource: null`, meaning unknown rather than assumed.

### There is no default AI provider

`ScoltaConfig::$aiProvider` defaults to `''` instead of `'anthropic'`, and an empty value is no longer coalesced back to a provider. Empty means AI is off: `AiServiceAdapter` builds no `AiClient`, `AiClient` rejects construction with "No AI provider selected", and `HealthChecker` reports `ai_usable: false`.

Installs with a provider already persisted are unaffected. Only the shipped default and the coalescing change.

- `AiClient` constructed without a `provider` key now throws `InvalidArgumentException`. Pass it explicitly.
- `ApiKeyResolver::resolve()`'s `$configuredProvider` now defaults to `''`.
- `HealthChecker` gains `ai_provider_selected` (bool), and `ai_provider` can be `''`.
- `ResolvedApiKey` gains `providerSelected()` and `aiEnabled()`; `severity()` returns `'warning'` when a key is present but no provider is selected.

### Amazee.ai connections record how they were established

`ApiKeySource` gains `AmazeeDemo` (`'amazee:demo'`) and `AmazeeAccount` (`'amazee:account'`) alongside `Amazee` (`'amazee'`), set from what the store recorded at connect time.

`ConfigStorageInterface` is unchanged, so no existing implementation breaks. To report the distinction, a backend implements `ProvenanceAwareConfigStorageInterface` (`storeConnectionSource()` / `loadConnectionSource()`) and drops the recorded source in `clear()`. A store that does not implement it reports plain `Amazee`, as do pre-existing credentials.

- Comparing against the literal `'amazee'` no longer matches every Amazee state. Use `ApiKeySource::isAmazee()`, or match all three values.
- `ResolvedApiKey::describe()` now says "using the free demo" or "with your account" when the origin was recorded.

### The Amazee.ai API key source is `amazee` again, not `amazee:operator` / `amazee:auto`

The two cases 1.1.0 introduced are collapsed back into one `ApiKeySource::Amazee` (`'amazee'`). Nothing recorded which one produced a token, so each adapter was stuck reporting a fixed value: Drupal always `amazee:operator`, WordPress always `amazee:auto`. Both classes are experimental, so this lands in a minor.

Routing, precedence and Amazee eligibility are unchanged. Only the reported source string and its label change.

- Anything comparing against `'amazee:operator'` or `'amazee:auto'`, whether from `getApiKeySource()` (Drupal), `get_api_key_source()` (WordPress), or the `ai_key_source` field of `/health`, should compare against `'amazee'`, or call `ResolvedApiKey::isAmazee()`.
- `describe()` no longer emits "(auto-provisioned free trial)". A surface matching on that wording needs updating; one that renders the string needs nothing.

## 1.1.0

### Search as you type is ON by default

Typing opens a suggestions dropdown under the input. The search pipeline is unchanged and still runs only on Enter, the search button, or selecting a suggestion. The widget gains a dropdown element, ARIA combobox roles on the input, a `localStorage` key (`scolta:recent-searches`), and some extra Pagefind searches and fragment loads while a visitor types.

Existing indexes need no rebuild. The off switch is `'sayt_enabled' => false`, which restores the pre-1.1.0 widget exactly: no dropdown node, no combobox roles, no storage access on any path.

Two things to check before leaving it on:

- **Theme CSS.** The dropdown is absolutely positioned inside `.scolta-search-input-wrap` and styled through `--scolta-sayt-*` custom properties. A theme that overrides that positioning, or sets a stacking context around the search box, may need `--scolta-sayt-z-index` adjusted.
- **AI budget.** With `sayt_expand` on, SAYT makes at most `sayt_expand_per_minute` (default 6) query-expansion calls per minute, sharing the platform's AI flood budget with committed searches. Set `sayt_expand: false` to keep the dropdown keyword-only.

All ten `sayt_*` settings are documented in [`docs/SAYT.md`](docs/SAYT.md) and [`docs/CONFIG_REFERENCE.md`](docs/CONFIG_REFERENCE.md).

### Indexes must be rebuilt (modern Snowball stemmer backend)

wamania/php-stemmer is replaced with a vendored modern Snowball backend matching Pagefind's query-time stemming (`pagefind_stem` 1.0.0). Stored stems change on stemmer-divergent words, so **indexes built by earlier versions must be rebuilt**; until then those words keep missing from results. Re-run your platform's Scolta build command.

### Unknown AI providers now fail closed

`AiClient` previously treated any `provider` value other than `'openai'` as Anthropic. An unrecognized string (a typo, or `'claude'`) now throws `InvalidArgumentException` at construction instead of sending requests to the wrong endpoint. Set `provider` to `'anthropic'` or `'openai'`.

## Upgrading to 1.0.0 (from 0.3.x)

No breaking API changes. All 0.3.x public APIs are preserved.

### Config defaults changed

If you relied on these without setting them explicitly, search behavior changes:

| Property | 0.3.x | 1.0.0 | Effect |
|---|---|---|---|
| `ai_summary_top_n` | `5` | `10` | Summaries consider more results |
| `ai_summary_max_chars` | `2000` | `4000` | More context per result |
| `expand_primary_weight` | `0.7` | `0.5` | Expanded results weighted equally with original |

### WASM asset

The binary is `scolta_core_bg.wasm`, not `scolta.wasm`. Platform adapters handle this; update any custom asset serving rules.

### Stability annotations

All public methods now carry `@stability stable`. Patch releases are bug fixes only; minor releases add features and deprecations without breaking stable APIs; major releases carry breaking changes and are coordinated across all Scolta packages. Minor and patch versions release independently per package.

## Upgrade checklist

1. Read the CHANGELOG.md entry for the target version.
2. Search your codebase for deprecated methods.
3. Update the constraint: `"tag1/scolta-php": "^1.0"`, then `composer update tag1/scolta-php`.
4. Run `php artisan scolta:check-setup` (Laravel), `drush scolta:check-setup` (Drupal), or `wp scolta check-setup` (WordPress).
5. Run your test suite.
6. Verify `scolta_core_bg.wasm` is served from your platform's static asset path.
