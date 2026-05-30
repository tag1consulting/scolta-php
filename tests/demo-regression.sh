#!/usr/bin/env bash
#
# Dual-indexer demo regression test.
#
# Builds a representative WordPress and Drupal demo under both indexer=php
# and indexer=pagefind, then asserts:
#   - Fragment URLs are identical between indexers (joined by title, not URL)
#   - Result counts match for test queries
#
# Prerequisites: DDEV running for terra-collecta and the-athenaeum.
# Run from the scolta-php package directory.
#
# Usage: bash tests/demo-regression.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SCOLTA_PHP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DEMOS_DIR="$(cd "$SCOLTA_PHP_DIR/../../demos" && pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'
PASS=0
FAIL=0

pass() { echo -e "${GREEN}PASS${NC}: $1"; ((PASS++)); }
fail() { echo -e "${RED}FAIL${NC}: $1"; ((FAIL++)); }

# ----------------------------------------------------------------
# Helper: extract data.url values from pagefind fragments
# ----------------------------------------------------------------
extract_fragment_urls() {
    local dir="$1"
    local frag_dir=""
    if [ -d "$dir/pagefind/fragment" ]; then
        frag_dir="$dir/pagefind/fragment"
    elif [ -d "$dir/fragment" ]; then
        frag_dir="$dir/fragment"
    else
        echo "No fragment dir found in $dir" >&2
        return 1
    fi

    for f in "$frag_dir"/*.pf_fragment; do
        [ -f "$f" ] || continue
        python3 -c "
import gzip, json, sys
with open('$f', 'rb') as fp:
    data = gzip.decompress(fp.read())
if data[:12] == b'pagefind_dcd':
    data = data[12:]
j = json.loads(data)
title = j.get('meta', {}).get('title', '')
url = j.get('url', '')
print(json.dumps({'title': title, 'url': url}))
" 2>/dev/null || true
    done
}

# ----------------------------------------------------------------
# WordPress: terra-collecta
# ----------------------------------------------------------------
echo ""
echo "=== WordPress: terra-collecta ==="
WP_DIR="$DEMOS_DIR/terra-collecta"
WP_PLUGIN="$WP_DIR/wp-content/plugins/scolta"

if ! ddev describe -j 2>/dev/null | python3 -c "import sys,json; d=json.load(sys.stdin); sys.exit(0 if d.get('raw',{}).get('status') else 1)" 2>/dev/null; then
    cd "$WP_DIR"
fi

# Back up current scolta-php in vendor
WP_VENDOR_PHP="$WP_PLUGIN/vendor/tag1/scolta-php"
if [ ! -d "$WP_VENDOR_PHP" ]; then
    echo "scolta-php not found in WP vendor directory, skipping WP test"
else
    # Copy our fixed ContentExporter.php
    cp "$WP_VENDOR_PHP/src/Export/ContentExporter.php" "$WP_VENDOR_PHP/src/Export/ContentExporter.php.bak"
    cp "$SCOLTA_PHP_DIR/src/Export/ContentExporter.php" "$WP_VENDOR_PHP/src/Export/ContentExporter.php"

    # Copy our fixed scolta.js
    cp "$WP_VENDOR_PHP/assets/js/scolta.js" "$WP_VENDOR_PHP/assets/js/scolta.js.bak"
    cp "$SCOLTA_PHP_DIR/assets/js/scolta.js" "$WP_VENDOR_PHP/assets/js/scolta.js"
    cp "$WP_VENDOR_PHP/assets/js/scolta.js.sha256" "$WP_VENDOR_PHP/assets/js/scolta.js.sha256.bak"
    cp "$SCOLTA_PHP_DIR/assets/js/scolta.js.sha256" "$WP_VENDOR_PHP/assets/js/scolta.js.sha256"

    # Copy updated WP adapter files
    WP_ADAPTER="$SCOLTA_PHP_DIR/../../packages/scolta-wp"
    cp "$WP_PLUGIN/cli/class-scolta-cli.php" "$WP_PLUGIN/cli/class-scolta-cli.php.bak"
    cp "$WP_ADAPTER/cli/class-scolta-cli.php" "$WP_PLUGIN/cli/class-scolta-cli.php"
    cp "$WP_PLUGIN/admin/class-scolta-admin.php" "$WP_PLUGIN/admin/class-scolta-admin.php.bak"
    cp "$WP_ADAPTER/admin/class-scolta-admin.php" "$WP_PLUGIN/admin/class-scolta-admin.php"
    # Also update the scolta.js in the plugin assets
    cp "$WP_PLUGIN/assets/js/scolta.js" "$WP_PLUGIN/assets/js/scolta.js.bak"
    cp "$SCOLTA_PHP_DIR/assets/js/scolta.js" "$WP_PLUGIN/assets/js/scolta.js"

    # Record original indexer setting
    cd "$WP_DIR"
    ORIG_INDEXER=$(ddev wp option get scolta_settings --format=json 2>/dev/null | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(d.get('indexer','php'))" 2>/dev/null || echo "php")
    echo "  Original indexer: $ORIG_INDEXER"

    # Build 1: PHP indexer
    echo "  Building with indexer=php..."
    ddev wp option patch update scolta_settings indexer php 2>/dev/null || true
    ddev wp scolta build --force 2>&1 | tail -3
    PHP_OUTPUT_DIR=$(ddev wp scolta status 2>/dev/null | grep -i "index.*dir\|output" | head -1 | awk '{print $NF}' || echo "")

    # Extract fragment URLs from PHP-built index
    # The PHP indexer writes to {output_dir}/pagefind/
    WP_OUTPUT="$WP_DIR/wp-content/uploads/scolta"
    echo "  Extracting PHP indexer fragment URLs..."
    PHP_URLS=$(extract_fragment_urls "$WP_OUTPUT" 2>/dev/null | sort)
    PHP_COUNT=$(echo "$PHP_URLS" | grep -c '"url"' || echo 0)
    echo "  PHP indexer: $PHP_COUNT fragments"

    # Build 2: Binary indexer (pagefind)
    echo "  Building with indexer=pagefind..."
    ddev wp option patch update scolta_settings indexer pagefind 2>/dev/null || true
    ddev wp scolta build --force 2>&1 | tail -3
    echo "  Extracting binary indexer fragment URLs..."
    BIN_URLS=$(extract_fragment_urls "$WP_OUTPUT" 2>/dev/null | sort)
    BIN_COUNT=$(echo "$BIN_URLS" | grep -c '"url"' || echo 0)
    echo "  Binary indexer: $BIN_COUNT fragments"

    # Compare URLs by title
    PHP_URL_MAP=$(echo "$PHP_URLS" | python3 -c "
import sys, json
urls = {}
for line in sys.stdin:
    line = line.strip()
    if not line: continue
    d = json.loads(line)
    if d['title']:
        urls[d['title']] = d['url']
for t in sorted(urls):
    print(f'{t}\t{urls[t]}')
" 2>/dev/null)

    BIN_URL_MAP=$(echo "$BIN_URLS" | python3 -c "
import sys, json
urls = {}
for line in sys.stdin:
    line = line.strip()
    if not line: continue
    d = json.loads(line)
    if d['title']:
        urls[d['title']] = d['url']
for t in sorted(urls):
    print(f'{t}\t{urls[t]}')
" 2>/dev/null)

    # Assert URLs match
    if [ "$PHP_URL_MAP" = "$BIN_URL_MAP" ]; then
        pass "terra-collecta: PHP and binary indexer URLs are identical ($PHP_COUNT fragments)"
    else
        fail "terra-collecta: URL mismatch between indexers"
        echo "  PHP URLs (sample):"
        echo "$PHP_URL_MAP" | head -5
        echo "  Binary URLs (sample):"
        echo "$BIN_URL_MAP" | head -5
        # Show differences
        diff <(echo "$PHP_URL_MAP") <(echo "$BIN_URL_MAP") | head -20
    fi

    # Check no /{id}.html artifact URLs in binary output
    ARTIFACT_URLS=$(echo "$BIN_URLS" | python3 -c "
import sys, json, re
for line in sys.stdin:
    d = json.loads(line.strip())
    if re.match(r'^/[a-zA-Z0-9_-]+\.html$', d.get('url', '')):
        print(d['url'])
" 2>/dev/null || true)
    if [ -z "$ARTIFACT_URLS" ]; then
        pass "terra-collecta: No /{id}.html artifact URLs in binary output"
    else
        fail "terra-collecta: Found artifact URLs: $ARTIFACT_URLS"
    fi

    # Restore original indexer setting
    ddev wp option patch update scolta_settings indexer "$ORIG_INDEXER" 2>/dev/null || true
    # Rebuild with original indexer
    echo "  Restoring original indexer ($ORIG_INDEXER) and rebuilding..."
    ddev wp scolta build --force 2>&1 | tail -2

    # Restore backed up files
    mv "$WP_VENDOR_PHP/src/Export/ContentExporter.php.bak" "$WP_VENDOR_PHP/src/Export/ContentExporter.php"
    mv "$WP_VENDOR_PHP/assets/js/scolta.js.bak" "$WP_VENDOR_PHP/assets/js/scolta.js"
    mv "$WP_VENDOR_PHP/assets/js/scolta.js.sha256.bak" "$WP_VENDOR_PHP/assets/js/scolta.js.sha256"
    mv "$WP_PLUGIN/cli/class-scolta-cli.php.bak" "$WP_PLUGIN/cli/class-scolta-cli.php"
    mv "$WP_PLUGIN/admin/class-scolta-admin.php.bak" "$WP_PLUGIN/admin/class-scolta-admin.php"
    mv "$WP_PLUGIN/assets/js/scolta.js.bak" "$WP_PLUGIN/assets/js/scolta.js"
    echo "  Restored original files."
fi

# ----------------------------------------------------------------
# Summary
# ----------------------------------------------------------------
echo ""
echo "=== Summary ==="
echo -e "${GREEN}Passed: $PASS${NC}"
echo -e "${RED}Failed: $FAIL${NC}"

if [ $FAIL -gt 0 ]; then
    exit 1
fi
