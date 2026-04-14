#!/bin/bash
set -euo pipefail

# Generate Pagefind reference fixtures from the Wikipedia test corpus.
# Run manually, commit output. Not run during CI.
#
# Processes 19 languages × 5 pages = 95 Wikipedia HTML files.
#
# Usage: ./scripts/generate-concordance-fixtures-wiki.sh [pagefind-version]
# Example: ./scripts/generate-concordance-fixtures-wiki.sh 1.5.1

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PACKAGE_DIR="$(dirname "$SCRIPT_DIR")"
CORPUS_DIR="${PACKAGE_DIR}/tests/fixtures/concordance/corpus-wiki"
REFERENCE_DIR="${PACKAGE_DIR}/tests/fixtures/concordance/reference-wiki"
PAGEFIND_VERSION="${1:-1.5.0}"

if [ ! -d "${CORPUS_DIR}" ] || [ -z "$(ls -A "${CORPUS_DIR}"/*.html 2>/dev/null)" ]; then
    echo "Wikipedia corpus not found. Run first:"
    echo "  php scripts/fetch-wikipedia-corpus.php"
    exit 1
fi

# Clean previous reference (keep metadata)
rm -rf "${REFERENCE_DIR}"
mkdir -p "${REFERENCE_DIR}"

# Download Pagefind binary if not already available
BIN_DIR="${SCRIPT_DIR}/.pagefind-bin"
PAGEFIND_BIN="${BIN_DIR}/pagefind"
if [ ! -f "${PAGEFIND_BIN}" ] || ! "${PAGEFIND_BIN}" --version 2>/dev/null | grep -q "${PAGEFIND_VERSION}"; then
    echo "Downloading Pagefind ${PAGEFIND_VERSION}..."
    mkdir -p "${BIN_DIR}"

    # Detect platform
    case "$(uname -s)-$(uname -m)" in
        Linux-x86_64)  PLATFORM="x86_64-unknown-linux-musl" ;;
        Linux-aarch64) PLATFORM="aarch64-unknown-linux-musl" ;;
        Darwin-x86_64) PLATFORM="x86_64-apple-darwin" ;;
        Darwin-arm64)  PLATFORM="aarch64-apple-darwin" ;;
        *) echo "Unsupported platform: $(uname -s)-$(uname -m)"; exit 1 ;;
    esac

    DOWNLOAD_URL="https://github.com/Pagefind/pagefind/releases/download/v${PAGEFIND_VERSION}/pagefind-v${PAGEFIND_VERSION}-${PLATFORM}.tar.gz"
    echo "Downloading from: ${DOWNLOAD_URL}"
    curl -fsSL "${DOWNLOAD_URL}" | tar -xz -C "${BIN_DIR}"
    chmod +x "${PAGEFIND_BIN}"
fi

echo "Using Pagefind: $("${PAGEFIND_BIN}" --version)"

# Create a temp directory for Pagefind output
TEMP_OUTPUT=$(mktemp -d)

# Run Pagefind against the Wikipedia corpus
"${PAGEFIND_BIN}" \
    --site "${CORPUS_DIR}" \
    --output-subdir "${TEMP_OUTPUT}/pagefind" \
    2>&1 | tee "${REFERENCE_DIR}/build.log" || true

# Pagefind may output relative to --site, check both locations
if [ -d "${TEMP_OUTPUT}/pagefind" ] && [ -f "${TEMP_OUTPUT}/pagefind/pagefind-entry.json" ]; then
    cp -r "${TEMP_OUTPUT}/pagefind/"* "${REFERENCE_DIR}/"
elif [ -d "${CORPUS_DIR}/pagefind" ]; then
    cp -r "${CORPUS_DIR}/pagefind/"* "${REFERENCE_DIR}/"
    rm -rf "${CORPUS_DIR}/pagefind"
elif [ -f "${CORPUS_DIR}/pagefind-entry.json" ]; then
    cp "${CORPUS_DIR}/pagefind-entry.json" "${REFERENCE_DIR}/"
    cp -r "${CORPUS_DIR}/index" "${REFERENCE_DIR}/" 2>/dev/null || true
    cp -r "${CORPUS_DIR}/fragment" "${REFERENCE_DIR}/" 2>/dev/null || true
    cp -r "${CORPUS_DIR}/filter" "${REFERENCE_DIR}/" 2>/dev/null || true
    cp "${CORPUS_DIR}"/*.pf_meta "${REFERENCE_DIR}/" 2>/dev/null || true
    # Clean up corpus dir
    rm -f "${CORPUS_DIR}/pagefind-entry.json"
    rm -rf "${CORPUS_DIR}/index" "${CORPUS_DIR}/fragment" "${CORPUS_DIR}/filter"
    rm -f "${CORPUS_DIR}"/*.pf_meta
fi

rm -rf "${TEMP_OUTPUT}"

# Remove Pagefind's JS/WASM/CSS — we only need data files
rm -f "${REFERENCE_DIR}/pagefind.js" "${REFERENCE_DIR}/pagefind-ui.js" "${REFERENCE_DIR}/pagefind-ui.css"
rm -f "${REFERENCE_DIR}/pagefind-modular-ui.js" "${REFERENCE_DIR}/pagefind-modular-ui.css"
rm -f "${REFERENCE_DIR}/pagefind-highlight.js"
rm -rf "${REFERENCE_DIR}/wasm"* 2>/dev/null || true
rm -f "${REFERENCE_DIR}"/*.wasm 2>/dev/null || true

# Store metadata
cat > "${REFERENCE_DIR}/reference-metadata.json" <<METADATA
{
    "pagefind_version": "${PAGEFIND_VERSION}",
    "generated_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "corpus_file_count": $(ls -1 "${CORPUS_DIR}"/*.html 2>/dev/null | wc -l | tr -d ' '),
    "languages": 19,
    "pages_per_language": 5,
    "platform": "$(uname -s)-$(uname -m)",
    "generator_script": "scripts/generate-concordance-fixtures-wiki.sh"
}
METADATA

echo ""
echo "Wikipedia reference fixtures generated at: tests/fixtures/concordance/reference-wiki/"
echo "Pagefind version: ${PAGEFIND_VERSION}"
echo "Files:"
find "${REFERENCE_DIR}" -type f | sort | while read -r f; do echo "  $(basename "$f")"; done
echo ""
echo "Next steps:"
echo "  1. git add tests/fixtures/concordance/reference-wiki/"
echo "  2. vendor/bin/phpunit tests/Concordance/WikipediaConcordanceTest.php"
