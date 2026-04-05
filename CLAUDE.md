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

### WASM interface version

- ScoltaWasm.php MUST check the WASM interface version at load time.
- If the loaded WASM binary reports an interface version this package doesn't support, throw a clear RuntimeException.

### Dependency constraint

- `composer.json` MUST use caret constraints for scolta-php dependencies from platform adapters: `"tag1/scolta-php": "^X.Y"`.
- For development with path repos, use `@dev`.

### Version management and -dev workflow

The `version` field in `composer.json` is always either a tagged release (`0.2.0`) or a dev pre-release (`0.3.0-dev`). See scolta-core/VERSIONING.md for the full workflow.

**When committing code:**

- If the current version already has `-dev`, **do not change it**. Multiple commits accumulate on the same `-dev` version.
- If the current version is a bare release and you are making the first change after that release, **bump to the next target with `-dev`** in `composer.json`.
  - Bug fix only → `0.1.1-dev`
  - New feature or deprecation → `0.2.0-dev`
  - Breaking change → `1.0.0-dev` (coordinated across all packages)

**WARNING:** Never commit a bare version bump without tagging it as a release.

## Testing

- Run: `./vendor/bin/phpunit`
- WASM integration tests are skipped when `libextism` is not installed — this is expected in CI without the native runtime.
- All new public methods MUST have unit tests.

## Architecture

- ScoltaWasm is the bridge to the Rust WASM module. All scoring/HTML/prompt logic lives in WASM.
- PHP classes are thin wrappers — don't reimplement algorithms that belong in scolta-core.
- DTOs (ContentItem, AiResponse, TrackerRecord) are immutable readonly classes.
