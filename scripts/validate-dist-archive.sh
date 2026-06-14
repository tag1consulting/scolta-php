#!/usr/bin/env bash
#
# Validate the Composer distribution archive.
#
# Composer installs this package as a `dist` archive. For a GitHub-hosted
# package, Composer downloads GitHub's generated zipball/tarball, which is
# produced by `git archive` and HONORS `.gitattributes export-ignore`. So the
# `export-ignore` list is the live filter that keeps dev cruft (tests, CI
# config, tooling) out of what every Composer consumer downloads — and an
# over-broad line silently DROPS a runtime file and ships a broken package.
#
# Nothing else in CI validates that list against the archive it actually
# produces. A typo'd or missing export-ignore line ships developer cruft to
# every consumer; an over-broad line ships a dead package.
#
# Precedent: the scolta-wp 13 MB zip incident and the WordPress.org dist-cruft
# flags. scolta-drupal already runs this exact gate against its drupal.org
# tarball; this is the scolta-php port.
#
# This script reproduces the shipped archive (`git archive HEAD`) and asserts:
#   1. EXCLUDED   - no export-ignored path is present in the archive.
#   2. RUNTIME    - every committed runtime asset IS present.
#   3. TOP-LEVEL  - every top-level entry is on an explicit allowlist (fail-closed
#                   change-control: a new top-level file/dir fails until it is
#                   either export-ignored or added to the allowlist below).
#   4. SIZE       - the archive is under a documented cap.
#
# Run locally from the repo root:  scripts/validate-dist-archive.sh
#
# The single source of truth for what gets excluded is `.gitattributes`
# (export-ignore lines); the single source of truth for what is allowed at the
# top level is ALLOWED_TOP_LEVEL below. Keep those two in sync with reality.

set -euo pipefail

# Resolve repo root from this script's location so it runs from anywhere.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

# Scratch files live under one mktemp -d dir — never fixed names in the CWD
# (see the scolta-wp zip-contents.txt mistake). A single random dir holds both
# the archive and the extract tree; `mktemp -d` is portable (the alternative,
# `mktemp foo.XXXXXX.tar`, has a suffix after the X's that BSD/macOS mktemp
# rejects). Pass an explicit archive path as $1 to override.
SCRATCH_DIR="$(mktemp -d "${TMPDIR:-/tmp}/scolta-php-dist.XXXXXX")"
ARCHIVE="${1:-$SCRATCH_DIR/dist.tar}"
EXTRACT_DIR="$SCRATCH_DIR/extract"
mkdir -p "$EXTRACT_DIR"

# ---------------------------------------------------------------------------
# Configuration — keep in sync with .gitattributes and the runtime tree.
# ---------------------------------------------------------------------------

# Paths that MUST NOT appear in the archive. Mirror of the `export-ignore`
# lines in .gitattributes (the filter that is supposed to drop them).
# If you add an export-ignore line, add it here too (and vice versa).
EXCLUDED_PATHS=(
  "tests"
  ".github"
  "phpunit.xml"
  ".php-cs-fixer.dist.php"
  "phpstan.neon"
  "phpstan-baseline.neon"
  "benchmarks"
  "scripts"
  "tools"
  "node_modules"
  "package.json"
  "package-lock.json"
  "playwright.config.js"
  "CLAUDE.md"
  ".editorconfig"
  "docs/BENCHMARKS-LATEST.md"
  "docs/LANGUAGE_PARITY.md"
)

# Committed runtime assets that MUST be present in the archive — a broken or
# over-broad export-ignore line that drops one of these ships a dead package.
REQUIRED_PATHS=(
  "composer.json"
  "src"
  "templates"
  "assets/js/scolta.js"
  "assets/css/scolta.css"
  "assets/wasm/scolta_core_bg.wasm"
  "assets/wasm/scolta_core.js"
)

# Fail-closed top-level allowlist, derived from the current clean archive.
# Every top-level entry in the archive must be one of these. A new top-level
# file or directory fails CI until it is either export-ignored (add it to
# .gitattributes + EXCLUDED_PATHS in this script) or deliberately shipped
# (add it here).
ALLOWED_TOP_LEVEL=(
  ".gitattributes"
  ".gitignore"
  "BENCHMARKS.md"
  "CHANGELOG.md"
  "HOSTING.md"
  "LICENSE"
  "README.md"
  "UPGRADE.md"
  "UPGRADING-PAGEFIND.md"
  "assets"
  "composer.json"
  "composer.lock"
  "docs"
  "src"
  "templates"
)

# Size cap. Measured clean archive (git archive HEAD, 2026-06-14) = 2,969,600
# bytes (~2.8 MB, dominated by the ~1.2 MB browser WASM in assets/wasm). Cap set
# at roughly 2x to catch accidental bloat (a stray index, a build artifact, a
# vendored binary) while tolerating normal growth. Bump deliberately with a
# fresh measurement if the package grows.
MAX_BYTES=6000000

cleanup() { rm -rf "$SCRATCH_DIR"; }
trap cleanup EXIT

FAIL=0
fail() { echo "FAIL: $*" >&2; FAIL=1; }

# ---------------------------------------------------------------------------
# Build / locate the archive.
# ---------------------------------------------------------------------------
if [ "${BUILD_ARCHIVE:-1}" = "1" ]; then
  echo "Building archive: git archive HEAD -o $ARCHIVE"
  rm -f "$ARCHIVE"
  git archive HEAD -o "$ARCHIVE"
fi

if [ ! -f "$ARCHIVE" ]; then
  echo "FAIL: archive not found at $ARCHIVE (set BUILD_ARCHIVE=1 to build it)" >&2
  exit 1
fi

echo "Extracting $ARCHIVE -> $EXTRACT_DIR"
tar -xf "$ARCHIVE" -C "$EXTRACT_DIR"

# Sorted, slash-stripped top-level entries actually present in the archive.
# (Read into an array without mapfile so this works on bash 3.2, e.g. macOS.)
ARCHIVE_TOP=()
while IFS= read -r line; do
  ARCHIVE_TOP+=("$line")
done < <(tar -tf "$ARCHIVE" | awk -F/ 'NF { print $1 }' | sort -u)

in_list() {
  local needle="$1"; shift
  local item
  for item in "$@"; do
    [ "$item" = "$needle" ] && return 0
  done
  return 1
}

# ---------------------------------------------------------------------------
# 1. EXCLUDED-FILES ASSERT
# ---------------------------------------------------------------------------
echo
echo "== 1. Excluded paths must be absent =="
for p in "${EXCLUDED_PATHS[@]}"; do
  if tar -tf "$ARCHIVE" | grep -qE "^${p}(/|\$)"; then
    fail "export-ignored path '$p' LEAKED into the dist archive."
    echo "      -> The filter lives in .gitattributes (export-ignore line for /$p). Check for a typo or missing line." >&2
  else
    echo "  ok absent: $p"
  fi
done

# ---------------------------------------------------------------------------
# 2. RUNTIME-PRESENCE ASSERT
# ---------------------------------------------------------------------------
echo
echo "== 2. Runtime assets must be present =="
for p in "${REQUIRED_PATHS[@]}"; do
  if [ -e "$EXTRACT_DIR/$p" ]; then
    echo "  ok present: $p"
  else
    fail "required runtime asset '$p' is MISSING from the dist archive."
    echo "      -> An over-broad export-ignore line in .gitattributes may be dropping it, or it was never committed." >&2
  fi
done

# ---------------------------------------------------------------------------
# 3. FAIL-CLOSED TOP-LEVEL SWEEP
# ---------------------------------------------------------------------------
echo
echo "== 3. Top-level change-control sweep (fail-closed) =="
for entry in "${ARCHIVE_TOP[@]}"; do
  if in_list "$entry" "${ALLOWED_TOP_LEVEL[@]}"; then
    echo "  ok allowed: $entry"
  else
    fail "UNEXPECTED top-level entry '$entry' in the dist archive."
    echo "      -> Either export-ignore it (.gitattributes + EXCLUDED_PATHS in this script)" >&2
    echo "         or, if it is meant to ship, add it to ALLOWED_TOP_LEVEL in scripts/validate-dist-archive.sh." >&2
  fi
done

# ---------------------------------------------------------------------------
# 4. SIZE CAP
# ---------------------------------------------------------------------------
echo
echo "== 4. Size cap =="
BYTES=$(wc -c < "$ARCHIVE" | tr -d ' ')
echo "  archive size: $BYTES bytes (cap: $MAX_BYTES bytes)"
if [ "$BYTES" -gt "$MAX_BYTES" ]; then
  fail "dist archive is $BYTES bytes, over the $MAX_BYTES-byte cap."
  echo "      -> Something large leaked (vendored binary, search index, build artifact?). Inspect: tar -tvf $ARCHIVE | sort -k3 -n | tail" >&2
fi

echo
if [ "$FAIL" -ne 0 ]; then
  echo "DIST ARCHIVE VALIDATION FAILED." >&2
  exit 1
fi
echo "DIST ARCHIVE VALIDATION PASSED."
