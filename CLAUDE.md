# Claude Rules for scolta-php

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

This package follows the Scolta versioning policy: each package versions independently, from its own git tags. Compatibility is expressed by the constraint an adapter declares for this package, not by matching version numbers with it, and adapters pin the resolved version in `composer.lock` within that constraint. There is no synchronized major version, and no check compares one package's version number with another's.

### Adding a new public method

- MUST add `@since` and `@stability` PHPDoc annotations.
- New methods MUST start as `@stability experimental` unless explicitly promoted.
- If the method wraps a WASM function, the WASM function must exist in scolta-core.

### Modifying a stable method's signature

- **NEVER** change the signature of a `@stability stable` method within a major version.
- If behavior must change: deprecate the old method, add a new one.

### Deprecating a method

- MUST add `@deprecated X.Y.Z Use newMethod() instead. Removal: NEXT_MAJOR.0.0.`
- MUST change `@stability` to `deprecated`.
- MUST call `trigger_deprecation('tag1/scolta-php', 'X.Y.Z', '...')` in the method body (or PHP's `trigger_error()` with `E_USER_DEPRECATED`).
- Deprecation warnings MUST tell the user what to use instead and when it will be removed.

### Removing a method

- **NEVER** remove a `@stability stable` method without a deprecation phase.
- Removal MUST only happen in a major version bump.

### WASM asset versioning

- The `scolta.wasm` binary is a browser-side asset compiled via `wasm-pack --target web`.
- Platform adapters serve the WASM binary as a static file: scolta-wp commits it, while scolta-drupal
  and scolta-laravel deploy or publish it out of `vendor/tag1/scolta-php/assets` (see the canonical
  source rule below).
- WASM runs in the browser — no server-side PHP extension (FFI, Extism, etc.) is involved.

### Dependency constraint

- `composer.json` MUST use caret constraints for scolta-php dependencies from platform adapters: `"tag1/scolta-php": "^X.Y"`.
- For development with path repos, use `@dev`.

### Version management and -dev workflow

The `version` field in `composer.json` is always either a tagged release (`0.2.0`) or a dev pre-release (`0.3.0-dev`). See scolta-core/VERSIONING.md for the full workflow. In Composer, `-dev` maps to a stability level that prevents accidental installation in production without an explicit `@dev` flag or `minimum-stability: dev`.

**When committing code:**

- If the current version already has `-dev`, **do not change it**. Multiple commits accumulate on the same `-dev` version.
- If the current version is a bare release and you are making the first change after that release, **bump to the next target with `-dev`** in `composer.json`.
  - Bug fix only → `0.1.1-dev`
  - New feature or deprecation → `0.2.0-dev`
  - Breaking change → `1.0.0-dev` (coordinated across all packages)

**WARNING:** Never commit a bare version bump without tagging it as a release.

## Testing

- Run: `./vendor/bin/phpunit`
- Tests run with `./vendor/bin/phpunit`. All tests should pass in CI without any native runtime.
- All new public methods MUST have unit tests.

## Architecture

- The scoring engine (scolta-core) runs as browser-side WASM via wasm-bindgen. PHP does not invoke WASM directly.
- PHP classes are thin wrappers — don't reimplement algorithms that belong in scolta-core.
- DTOs (ContentItem, AiResponse, TrackerRecord) are immutable readonly classes.

## scolta.js Canonical Source Rule

`assets/` in this repo is the **single canonical source** for the browser bundle (`js/scolta.js`,
`css/scolta.css`, `wasm/scolta_core.js`, `wasm/scolta_core_bg.wasm`). **Never edit the bundle anywhere
but here** — every change lands in this repo first. How each adapter then gets it differs, and the split
is set by how that platform installs, not by preference.

**Adapters that commit a copy** — scolta-wp (`assets/js/`, `assets/css/`, `assets/wasm/`), scolta-node
(`assets/`) and scolta-python (`src/scolta/assets/`). WordPress.org installs are zip drops with no
Composer at the install site, and the published npm and pip artifacts have to contain the files. After
changing the bundle here, re-vendor in each of those repos with its own command — `composer copy-assets`
in scolta-wp, `node scripts/vendor-assets.mjs` in scolta-node, `python scripts/vendor_assets.py` in
scolta-python — and open a PR there. scolta-wp's `assets-in-sync` CI job byte-compares its committed copy
against this repo resolved from `dev-main`, so on a coordinated change it stays red until this side
merges; that red is correct signal, and running `composer copy-assets` to green it overwrites the new
bundle with the old one. scolta-node and scolta-python have no parity check, so their copies fall behind
silently.

**Adapters that read the bundle out of `vendor/`** — scolta-drupal and scolta-laravel commit no copy.
scolta-drupal stopped committing one in its PR #227: `Drupal\scolta\Service\AssetDeployer` copies the
bundle from the installed `tag1/scolta-php` into `public://scolta-assets` at module install, in
`scolta_update_10005()`, and on every cache rebuild via `hook_rebuild()`, comparing size then hash so a
damaged files directory self-heals. A bundle change reaches a Drupal site through `composer update` plus
`drush cr` — no module release and no re-vendor commit. scolta-laravel has never committed a copy: it
publishes from `vendor/tag1/scolta-php/assets` and its `AssetStatus` verifies the published copy against
`assets/js/scolta.js.sha256`.

- `assets/js/scolta.js.sha256` contains the checksum of the canonical file (a bare hash that
  scolta-laravel reads at runtime). It is **derived** from `assets/ASSETS.sha256`, not hashed
  independently — regenerate both whenever scolta.js changes with `composer update-js-checksum` (which
  refreshes the manifest, then extracts the scolta.js line into the standalone file). Never re-hash
  scolta.js into that file by hand; the manifest is the single source of every asset SHA-256.
- Both vendor-reading adapters decide freshness by comparing hashes rather than trusting a version
  number, so a wrong manifest now misleads two adapters at runtime. It matters more than it did when
  every adapter carried a byte-compared copy, not less.

## Documentation Rules

Documentation follows code. When a PR changes behavior, the same PR must update the relevant docs.

- **CHANGELOG.md**: Every PR that changes code (not docs-only) MUST add an entry under `## [Unreleased]`. CI enforces this.
- **README.md**: Update if the change affects installation, usage examples, or the module structure.
- **docs/CONFIG_REFERENCE.md**: MUST be updated when any `ScoltaConfig` property is added, removed, renamed, or has its default changed. CI checks freshness.
- **UPGRADE.md**: MUST be updated when introducing breaking changes or deprecations.
- **PHPDoc**: All public methods MUST have complete PHPDoc including `@since` and `@stability`.
