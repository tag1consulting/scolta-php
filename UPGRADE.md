# Upgrade Guide

This document describes breaking changes and migration steps between versions of scolta-php.

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
