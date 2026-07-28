# Upgrade Guide

This document describes breaking changes and migration steps between versions of scolta-php.

## Unreleased

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
