# Maintaining scolta-php

The reference PHP library: a Pagefind index builder plus an AI proxy. It also owns the canonical browser
bundle. Publishes to Packagist.

Everything true of more than one Scolta repo lives in
[scolta-core/MAINTAINING.md](https://github.com/tag1consulting/scolta-core/blob/main/MAINTAINING.md):
the package list, the version rules, the release order, the fleet checks. Which packages carry a copy of
the bundle and how each one is checked is in
[scolta-core/ASSETS.md](https://github.com/tag1consulting/scolta-core/blob/main/ASSETS.md) — its
scolta-drupal entries predate that adapter's move to deploying from vendor, so read the bullets below
first.

**What it is.** The reference implementation, and the source the other bindings port from. It depends on
`scolta-core` (the vendored WASM). The three PHP adapters depend on this.

**Where the version lives.** `composer.json` `version`. This package declares one (unlike scolta-drupal
and scolta-wp, which must not). `extra.branch-alias.dev-main` beside it is pinned at `1.x-dev` for
the life of the major and no longer tracks the development line.

**Where it publishes.** Packagist, as `tag1/scolta-php`. To confirm:
`composer clear-cache && composer show tag1/scolta-php -a | grep versions`.

**CI checks.** phpunit (`test`, plus `coverage`), two coding-standard steps in the `test` job's lint row
(`Lint (php-cs-fixer)` running `composer lint-format`, and `Lint (phpcs)` running `composer phpcs` against
`phpcs.xml`) — separate steps so a red check names the tool, and `composer lint` runs both at once locally,
`wasm-validation`, `JS tests (Jest)`, `docs-check`
(CHANGELOG when code changes, and CONFIG_REFERENCE.md when `ScoltaConfig` or `SetupCheck` changes),
`analyse` (PHPStan, with a baseline that must not grow past its ratchet), `version-check` (format only),
`antipatterns`, `Concordance fixture version check` (the fixture must name the same Pagefind version as
`BUNDLED_VERSION` in `src/Index/SupportedVersions.php`), `Dist archive excludes dev files`,
and `Validate Composer dist archive`. The asset manifest is checked by
`tests/AssetManifestTest.php` inside phpunit, not by a job of its own.

**Release Validation.** This second workflow catches cross-repo breakage. It runs on every
pull request here and installs each PHP adapter against the scolta-php this run is about, then runs that
adapter's own suite. It is the only place adapter `main` meets library `main` before a merge, so a change
here that breaks scolta-drupal, scolta-laravel or scolta-wp goes red on the branch that made it. Read a
red `Adapter install` job as a real finding about your change, not as someone else's repo being broken.
It also carries the E2E, concordance, smoke and benchmark jobs.

**On release day.** This tags first in the PHP group. Tag, then wait for Packagist: stop at 15 minutes if
it hasn't updated, before any adapter tags.

**Watch out for.**

- The four files under `assets/` are canonical. Never edit a copy anywhere else: change it here, then
  re-vendor in the three packages that still carry one — scolta-wp, scolta-node and scolta-python.
  scolta-drupal and scolta-laravel carry no copy and need no re-vendor commit: Drupal deploys the bundle
  out of `vendor/tag1/scolta-php` into `public://scolta-assets` at install and on every cache rebuild
  (scolta-drupal PR #227), Laravel publishes it from vendor, so both pick a change up through
  `composer.lock`.
- `assets/js/scolta.js.sha256` is extracted from `assets/ASSETS.sha256`, never hashed separately.
  Regenerate both with `composer update-js-checksum`. scolta-laravel reads that bare hash at run time to
  detect a stale published asset, and the Drupal deployer compares hashes to decide what to re-copy, so
  a wrong manifest now misleads two adapters at run time rather than only failing a CI check here.
- There is no workflow here that propagates a bundle change to the carriers. Each carrier is re-vendored
  by a person running that repo's own command (`composer copy-assets` in scolta-wp,
  `node scripts/vendor-assets.mjs` in scolta-node, `python scripts/vendor_assets.py` in scolta-python),
  and scolta-wp's `assets-in-sync` job stays red until this repo's side merges. That red is correct
  signal. scolta-node and scolta-python have no parity job, so their copies fall behind silently;
  scolta-drupal's job was removed with its committed copy.
- `src/prompts.rs` in scolta-core is the source of the prompt text; the templates here are a mirror of
  it, and scolta-node and scolta-python fail CI when their mirrors drift. A prompt change lands in
  scolta-core first.
- The Snowball stemmers under `src/Index/Snowball/` are generated and vendored, not a dependency, so
  index-time stems match Pagefind's query-time stems byte for byte. The parity target is the
  `pagefind_stem` crate at the version Pagefind's query WASM compiles in, recorded with the snowball
  commit it was generated from in [src/Index/Snowball/PROVENANCE.md](src/Index/Snowball/PROVENANCE.md).
  Regenerate with `scripts/generate-stemmers.sh` and re-baseline the manifest; never hand-edit a stemmer.
  scolta-core has no stemmer, so this pin is the ports' to keep, and it moves in scolta-php, scolta-node
  and scolta-python together.
- Sweep `@since` annotations in `src/` when you rename the dev line. No check compares them to the
  version file.
