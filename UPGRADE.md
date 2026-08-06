# Upgrade Guide

This document describes breaking changes and migration steps between versions of scolta-php.

## Unreleased

## 1.2.0

### `AmazeeCredentials` no longer takes `operatorChosen`

`AmazeeCredentials` is `@stability experimental`, so this signature change lands
inside the 1.2 line rather than waiting for a major. The `operatorChosen` flag
is gone from all three entry points, replaced by an
`AmazeeConnectionSource|null` recorded at connect time rather than a boolean
supplied by the caller.

Before (1.1.0):

```php
public function __construct(
    public readonly string $token,
    public readonly string $baseUrl = '',
    public readonly bool $operatorChosen = false,
    public readonly bool $modelResolved = true,
) {}

public static function fromStorage(ConfigStorageInterface $storage, bool $operatorChosen = false, bool $modelResolved = true): ?self
public static function fromArray(?array $stored, bool $operatorChosen = false, bool $modelResolved = true): ?self
```

After (1.2.0):

```php
public function __construct(
    public readonly string $token,
    public readonly string $baseUrl = '',
    public readonly bool $modelResolved = true,
    public readonly ?AmazeeConnectionSource $connectionSource = null,
) {}

public static function fromStorage(ConfigStorageInterface $storage, bool $modelResolved = true): ?self
public static function fromArray(?array $stored, bool $modelResolved = true, ?AmazeeConnectionSource $connectionSource = null): ?self
```

**Who is affected:** anything constructing `AmazeeCredentials` directly, or
calling `fromStorage()` / `fromArray()`. The class is `final`, so there is no
subclass to update.

**What to do:**

- A named argument `operatorChosen:` is now an unknown named parameter and
  throws. Drop it. If you need the distinction back, pass
  `connectionSource:` an `AmazeeConnectionSource` case instead, or let
  `fromStorage()` read it from a store implementing
  `ProvenanceAwareConfigStorageInterface`.
- `fromStorage()` no longer accepts a connection-source argument at all. It
  derives the value from the store, so a caller that was passing one drops it.
- **Positional callers must recount.** `modelResolved` moved from third to
  second on `fromStorage()` and `fromArray()`, and from fourth to third on the
  constructor. A three-argument positional call such as
  `new AmazeeCredentials($token, $url, true)` previously set `operatorChosen`
  and now sets `modelResolved`, with no error at any layer. Audit positional
  call sites rather than relying on a fatal to find them.
- Passing a boolean into the new fourth constructor slot raises a `TypeError`,
  since `connectionSource` is typed `?AmazeeConnectionSource`.

Credentials stored before 1.2.0 have no recorded source and report
`connectionSource: null`, which is reported as unknown rather than assumed.

### There is no default AI provider

`ScoltaConfig::$aiProvider` now defaults to `''` instead of `'anthropic'`, and
nothing coalesces an empty value back to a provider. An empty provider means AI
features are off: `AiServiceAdapter` will not build an `AiClient`, `AiClient`
rejects construction with "No AI provider selected", and `HealthChecker` reports
`ai_provider: ''`, `ai_provider_selected: false` and `ai_usable: false`.

**Existing installs are unaffected.** A provider already persisted in a site's
configuration is read as-is; nothing rewrites, clears or re-defaults it. Only
the shipped default and the empty-to-`anthropic` coalescing change.

**What to check:**

- Code constructing `AiClient` directly without a `provider` key now throws
  `InvalidArgumentException`. Pass the provider explicitly.
- `ApiKeyResolver::resolve()`'s `$configuredProvider` parameter now defaults to
  `''`. Callers that relied on the old `'anthropic'` default must pass the
  provider they mean.
- `HealthChecker`'s payload gains `ai_provider_selected` (bool), and
  `ai_provider` can now be `''`. A consumer that rendered `ai_provider`
  unconditionally should handle the empty case.
- `ResolvedApiKey` gains `providerSelected()` and `aiEnabled()`; `severity()`
  now returns `'warning'` when a key is present but no provider is selected.

### Amazee.ai connections record how they were established

`ApiKeySource` gains `AmazeeDemo` (`'amazee:demo'`) and `AmazeeAccount`
(`'amazee:account'`) alongside the existing `Amazee` (`'amazee'`). Which one is
reported comes from a fact the credential store records at connect time, not
from a derivation.

To report the distinction, a storage backend implements the new
`ProvenanceAwareConfigStorageInterface` (`storeConnectionSource()` /
`loadConnectionSource()`), and must drop the recorded source in `clear()`.
`ConfigStorageInterface` is unchanged, so **no existing implementation breaks**:
a store that does not implement the sub-interface keeps working and reports the
origin-free `Amazee` source, which is also what pre-existing credentials report.

**What to check**, if you consume the source rather than the enum:

- A comparison against the literal `'amazee'` no longer matches every Amazee
  state. Use `ApiKeySource::isAmazee()`, or match all three values.
- `ResolvedApiKey::describe()` now says "using the free demo" or "with your
  account" when the origin was recorded.

### The Amazee.ai API key source is `amazee` again, not `amazee:operator` / `amazee:auto`

1.1.0 introduced two Amazee source cases to separate a licensed connection from
an auto-provisioned free trial. Nothing records which one produced a token —
`AmazeeTrialProvisioner` and `AmazeeAccountUpgrader` both write the same three
fields through `ConfigStorageInterface::store()` — so neither adapter could
derive it, and each got stuck reporting a single case: Drupal always
`amazee:operator`, WordPress always `amazee:auto`. They are collapsed back into
one `ApiKeySource::Amazee` with the backing value `'amazee'`.

**Routing is unchanged.** Precedence, Amazee eligibility, and which key is sent
to which provider all behave exactly as before. Only the reported source string
and its human-readable label change.

**What to check**, if you consume the source rather than the enum:

- Anything comparing against the literals `'amazee:operator'` or
  `'amazee:auto'` — from `getApiKeySource()` (Drupal), `get_api_key_source()`
  (WordPress), or the `ai_key_source` field of the `/health` payload — should
  compare against `'amazee'`, or better, call `ResolvedApiKey::isAmazee()`.
- The label and `ResolvedApiKey::describe()` no longer emit
  "(auto-provisioned free trial)". A surface that matched on that wording needs
  updating; a surface that renders the string needs nothing.

Both classes are `@stability experimental`, so this lands inside the 1.2 line
rather than waiting for a major. If you want the trial-versus-licensed
distinction back, the credential store has to record it first.

## 1.1.0

### Search as you type is ON by default

Typing in the search box now opens a suggestions dropdown under the input. The
full search pipeline is unchanged and still runs only on Enter, on the search
button, or on selecting a suggestion — but the widget gains a dropdown element,
ARIA combobox roles on the input, a `localStorage` key for recent searches
(`scolta:recent-searches`), and a small number of extra Pagefind searches and
fragment loads while a visitor types.

**Existing indexes need no rebuild.** Suggestions read the same fragments the
result list already reads.

**The off switch is one line:**

```php
'sayt_enabled' => false,
```

That restores the pre-1.1.0 widget exactly: no dropdown node in the scaffold, no
combobox roles on the input, no storage access on any path, and an `input`
listener that does what it always did.

Two things to check before leaving it on:

- **Theme CSS.** The dropdown is absolutely positioned inside the search input
  wrapper and styled through `--scolta-sayt-*` custom properties. A theme that
  overrides `.scolta-search-input-wrap` positioning, or that sets a stacking
  context around the search box, may need `--scolta-sayt-z-index` adjusted.
- **AI budget.** With `sayt_expand` on, SAYT makes at most `sayt_expand_per_minute`
  (default 6) query-expansion calls per minute, and those share the platform's AI
  flood budget with committed searches. Set `sayt_expand: false` to keep the
  dropdown keyword-only.

All ten `sayt_*` settings are documented in
[`docs/SAYT.md`](docs/SAYT.md) and [`docs/CONFIG_REFERENCE.md`](docs/CONFIG_REFERENCE.md).

### Indexes must be rebuilt (modern Snowball stemmer backend)

wamania/php-stemmer is replaced with a vendored modern Snowball backend that matches Pagefind's query-time stemming (`pagefind_stem` 1.0.0). Stored stems change on stemmer-divergent words, so **indexes built by earlier scolta-php versions must be rebuilt** — until rebuilt, those words keep missing from results. Rebuild your index after upgrading (re-run your platform's Scolta build/index command).

### Unknown AI providers now fail closed

`AiClient` previously treated any `provider` value other than `'openai'` as Anthropic. An unrecognized provider string (e.g. `'claude'`, or a typo) now throws `InvalidArgumentException` at construction instead of sending requests to the wrong endpoint. Set `provider` to `'anthropic'` or `'openai'`.

## Upgrading to 1.0.0 (from 0.3.x)

### Breaking Changes

No breaking API changes from 0.3.x to 1.0.0. All 0.3.x public APIs are preserved in 1.0.0.

### Config Defaults Changed

The following defaults were updated to improve out-of-the-box search quality. If you relied on the old defaults without explicitly setting them, your search behavior will change:

| Property | Old Default (0.3.x) | New Default (1.0.0) | Notes |
|----------|---------------------|---------------------|-------|
| `ai_summary_top_n` | `5` | `10` | AI summaries now consider more results, improving quality |
| `ai_summary_max_chars` | `2000` | `4000` | AI summaries receive more context per result |
| `expand_primary_weight` | `0.7` | `0.5` | Expanded results now have equal weight with original results |

If you want to preserve the old behavior, set these values explicitly in your platform config.

### WASM Asset Changes

The WASM binary filename is `scolta_core_bg.wasm` (not `scolta.wasm`). Platform adapters handle this automatically. If you have custom asset serving rules, update the filename.

### Stability Annotations

All public methods now carry `@stability stable` annotations. Going forward, semantic versioning guarantees apply:

- **Patch releases** (1.0.x): Bug fixes only.
- **Minor releases** (1.x.0): New features, deprecations. No breaking changes to stable APIs.
- **Major releases** (x.0.0): Breaking changes. Coordinated across all Scolta packages. This is the only release that requires all packages to bump together; minor and patch versions are released independently per package.

## Upgrade Checklist

1. Read the CHANGELOG.md entry for the target version.
2. Search your codebase for any deprecated methods.
3. Update `composer.json` constraint: `"tag1/scolta-php": "^1.0"`.
4. Run `composer update tag1/scolta-php`.
5. Run `php artisan scolta:check-setup` (Laravel), `drush scolta:check-setup` (Drupal), or `wp scolta check-setup` (WordPress) to verify the environment.
6. Run your test suite.
7. Verify the WASM binary asset is served correctly — `scolta_core_bg.wasm` must be publicly accessible from your platform's static asset path.
