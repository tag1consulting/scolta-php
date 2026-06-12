# stemmer-golden — Pagefind query-stemmer parity oracle

`scolta-php` builds a Pagefind index at publish time. Pagefind stems **queries**
at search time with its bundled WASM, which is the Rust crate
[`pagefind_stem`](https://crates.io/crates/pagefind_stem). If the indexer's
build-time stems differ from Pagefind's runtime query stems, the index silently
misses those queries. So the PHP stemmer must match `pagefind_stem`, not its
own backend's idea of "Snowball".

This tool generates the golden stems straight from `pagefind_stem`, pinned to the
exact version the targeted Pagefind release locks. The
`tests/fixtures/stemmer-corpus/<lang>/expected-stems.txt` fixtures are its output,
and the stemmer parity tests assert the PHP stemmer reproduces them exactly —
for every language in `Stemmer::LANGUAGE_MAP`.

## Version mapping (the part that must be kept honest)

| Targeted Pagefind | `Cargo.lock` pins | Algorithm revision |
| --- | --- | --- |
| **1.5.0** | `pagefind_stem` **1.0.0** (checksum `8dfa810b…`) | modern Snowball (post-3.0 / 2024): `added`→`add` |

`pagefind_stem` 0.2.0 (2022) was the pre-3.0 algorithm (`added`→`ad`); the 1.0.0
release (2026-03-23) moved to the revised algorithm. The vendored PHP stemmers
in `src/Index/Snowball/` (generated from the exact snowball commit the crate
was built from — see the PROVENANCE.md there) reproduce 1.0.0 byte-for-byte;
wamania/php-stemmer (any version) does not, and neither do snowball's v3.0.0
or v3.1.1 release tags.

## Regenerating

Requires a Rust toolchain. To re-target a new Pagefind release:

1. Read that Pagefind tag's `Cargo.lock`, find the `pagefind_stem` version.
2. Update the pin in `Cargo.toml` and the table above + `PROVENANCE.md`.
3. Run, for each language:

   ```sh
   cargo run --release -- en ../../tests/fixtures/stemmer-corpus/en/words.txt \
       ../../tests/fixtures/stemmer-corpus/en/expected-stems.txt
   ```

4. Update the sha256 manifest in `PROVENANCE.md`; the provenance test will fail
   until it matches, forcing a conscious re-baseline.
5. Regenerate the vendored PHP stemmers from the matching snowball revision
   (`scripts/generate-stemmers.sh`) if the algorithm revision moved.

This crate is `publish = false` and is not shipped with the package; it only
exists to keep the fixtures reproducible from Pagefind's own stemmer.
