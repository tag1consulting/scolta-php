# Claude Rules for scolta-php

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

This package follows the Scolta versioning policy. Major versions are synchronized across all Scolta packages. **Violations are blocking errors.**

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
- Platform adapters (scolta-wp, scolta-drupal, scolta-laravel) ship the WASM binary as a static file.
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

## Documentation Rules

Documentation follows code. When a PR changes behavior, the same PR must update the relevant docs.

- **CHANGELOG.md**: Every PR that changes code (not docs-only) MUST add an entry under `## [Unreleased]`. CI enforces this.
- **README.md**: Update if the change affects installation, usage examples, or the module structure.
- **docs/CONFIG_REFERENCE.md**: MUST be updated when any `ScoltaConfig` property is added, removed, renamed, or has its default changed. CI checks freshness.
- **UPGRADE.md**: MUST be updated when introducing breaking changes or deprecations.
- **PHPDoc**: All public methods MUST have complete PHPDoc including `@since` and `@stability`.
