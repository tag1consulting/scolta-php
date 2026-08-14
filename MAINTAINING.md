# MAINTAINING — scolta-php

The reference PHP library: a Pagefind index builder plus an AI proxy. It also owns the canonical browser
bundle. Publishes to Packagist.

Everything true of more than one Scolta repo lives in
[scolta-core/MAINTAINING.md](https://github.com/tag1consulting/scolta-core/blob/main/MAINTAINING.md):
the package list, the version rules, the release order, the fleet checks. How the bundle is copied and
checked is in
[scolta-core/ASSETS.md](https://github.com/tag1consulting/scolta-core/blob/main/ASSETS.md).

**What it is.** The reference implementation, and the source the other bindings port from. It depends on
`scolta-core` (the vendored WASM). The three PHP adapters depend on this.

**Where the version lives.** `composer.json` `version`. This package declares one (unlike scolta-drupal
and scolta-wp, which must not), and `extra.branch-alias.dev-main` beside it names the same line.

**Where it publishes.** Packagist, as `tag1/scolta-php`. To confirm:
`composer clear-cache && composer show tag1/scolta-php -a | grep versions`.

**CI checks.** phpunit (`test`, plus `coverage`), `wasm-validation`, `JS tests (Jest)`, `docs-check`
(CHANGELOG when code changes, and CONFIG_REFERENCE.md when `ScoltaConfig` or `SetupCheck` changes),
`analyse` (PHPStan, with a baseline that must not grow past its ratchet), `version-check` (format only),
`antipatterns`, `Concordance fixture version check` (the fixture must name the same Pagefind version as
`BUNDLED_VERSION` in `src/Index/SupportedVersions.php`), `Dist archive excludes dev files`,
`Validate Composer dist archive`, and `Version coherence`. The asset manifest is checked by
`tests/AssetManifestTest.php` inside phpunit, not by a job of its own.

**The second workflow is the one that catches cross-repo breakage.** `Release Validation` runs on every
pull request here and installs each PHP adapter against the scolta-php this run is about, then runs that
adapter's own suite. It is the only place adapter `main` meets library `main` before a merge, so a change
here that breaks scolta-drupal, scolta-laravel or scolta-wp goes red on the branch that made it. Read a
red `Adapter install` job as a real finding about your change, not as someone else's repo being broken.
It also carries the E2E, concordance, smoke and benchmark jobs.

**On release day.** This tags first in the PHP group. If you're opening a cycle, move
`extra.branch-alias` in the same commit as the bump. Tag, then wait for Packagist: stop at 15 minutes if
it hasn't updated, before any adapter tags.

**Watch out for.**

- The four files under `assets/` are canonical and other packages copy them. Never edit a copy in
  scolta-drupal, scolta-wp, scolta-node or scolta-python: change it here, then re-vendor there.
- `assets/js/scolta.js.sha256` is extracted from `assets/ASSETS.sha256`, never hashed separately.
  Regenerate both with `composer update-js-checksum`. scolta-laravel reads that bare hash at run time.
- There is no workflow here that propagates a bundle change to the carriers. Each carrier is re-vendored
  by a person running that repo's own command, and each adapter's `assets-in-sync` job stays red until
  this repo's side merges. That red is correct signal.
- `src/prompts.rs` in scolta-core is the source of the prompt text; the templates here are a mirror of
  it, and scolta-node and scolta-python fail CI when their mirrors drift. A prompt change lands in
  scolta-core first.
- Sweep `@since` annotations in `src/` when you rename the dev line. No check compares them to the
  version file.
