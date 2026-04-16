# Upgrade Guide

This document describes breaking changes and migration steps between versions of scolta-php.

## Pre-1.0 Stability Notice

Scolta is pre-1.0 software. The API is not yet stable and breaking changes may occur in minor version bumps (e.g., 0.2.0 to 0.3.0). Once 1.0.0 is released, semantic versioning guarantees will apply:

- **Patch releases** (1.0.x): Bug fixes only. No breaking changes.
- **Minor releases** (1.x.0): New features, deprecations. No breaking changes to stable APIs.
- **Major releases** (x.0.0): Breaking changes. Coordinated across all Scolta packages.

### Stability Annotations

All public methods carry `@stability` annotations:

- `@stability experimental` -- May change in any release. Use at your own risk.
- `@stability stable` -- Will not break within a major version. Safe for production.
- `@stability deprecated` -- Scheduled for removal. The annotation includes the replacement method and the version it will be removed.

Check `@stability` before depending on any method in production.

## Upgrading to 0.2.0 (from 0.1.x)

_This section will be populated when 0.2.0 is released._

### Breaking Changes

(None yet -- this is the initial development series.)

### Deprecations

(None yet.)

### New Requirements

(None beyond PHP 8.1+.)

## Upgrade Checklist Template

When upgrading between versions, follow this checklist:

1. Read the CHANGELOG.md entry for the target version.
2. Search your codebase for any deprecated methods listed in the upgrade notes.
3. Update `composer.json` constraint: `"tag1/scolta-php": "^X.Y"`.
4. Run `composer update tag1/scolta-php`.
5. Run `php artisan scolta:check-setup` (Laravel), `drush scolta:check-setup` (Drupal), or `wp scolta check-setup` (WordPress) to verify the environment.
6. Run your test suite.
7. Verify the WASM binary asset is served correctly — `scolta.wasm` must be publicly accessible from your platform's static asset path.

## WASM Asset Compatibility

Scolta's scoring engine (`scolta-core`) compiles to `wasm32-unknown-unknown` via `wasm-pack --target web` and runs in the browser. The `scolta.wasm` binary ships as a static asset alongside `pagefind.js`. When upgrading scolta-php, a new platform adapter release ships the updated WASM asset. No server-side WASM runtime or PHP extension is required.
