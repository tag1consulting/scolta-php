#!/usr/bin/env bash
# Check that all five Scolta packages report the same version.
# Run from the scolta-php root. Expects sibling repos at ../scolta-*.
set -euo pipefail

EXPECTED_VERSION=$(jq -r '.version' composer.json)
echo "Expected version: $EXPECTED_VERSION"

ERRORS=0

check_version() {
    local pkg=$1 file=$2 actual=$3
    if [ "$actual" != "$EXPECTED_VERSION" ]; then
        echo "ERROR: $pkg/$file has version '$actual', expected '$EXPECTED_VERSION'"
        ERRORS=$((ERRORS + 1))
    else
        echo "  OK: $pkg/$file = $actual"
    fi
}

# scolta-core
if [ -f ../scolta-core/Cargo.toml ]; then
    CORE_VER=$(grep '^version' ../scolta-core/Cargo.toml | head -1 | sed 's/.*"\(.*\)"/\1/')
    check_version "scolta-core" "Cargo.toml" "$CORE_VER"
else
    echo "  SKIP: ../scolta-core/Cargo.toml not found"
fi

# scolta-php
PHP_VER=$(jq -r '.version' composer.json)
check_version "scolta-php" "composer.json" "$PHP_VER"

# scolta-wp
if [ -f ../scolta-wp/composer.json ]; then
    WP_COMPOSER_VER=$(jq -r '.version' ../scolta-wp/composer.json)
    check_version "scolta-wp" "composer.json" "$WP_COMPOSER_VER"

    WP_HEADER_VER=$(grep -m1 'Version:' ../scolta-wp/scolta.php 2>/dev/null | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]' || echo "NOT FOUND")
    check_version "scolta-wp" "scolta.php Version header" "$WP_HEADER_VER"

    WP_CONST_VER=$(grep "define.*SCOLTA_VERSION" ../scolta-wp/scolta.php 2>/dev/null | sed "s/.*'\([^']*\)'.*/\1/" || echo "NOT FOUND")
    check_version "scolta-wp" "SCOLTA_VERSION constant" "$WP_CONST_VER"
else
    echo "  SKIP: ../scolta-wp/composer.json not found"
fi

# scolta-drupal
if [ -f ../scolta-drupal/composer.json ]; then
    DRUPAL_VER=$(jq -r '.version' ../scolta-drupal/composer.json)
    check_version "scolta-drupal" "composer.json" "$DRUPAL_VER"
else
    echo "  SKIP: ../scolta-drupal/composer.json not found"
fi

# scolta-laravel
if [ -f ../scolta-laravel/composer.json ]; then
    LARAVEL_VER=$(jq -r '.version' ../scolta-laravel/composer.json)
    check_version "scolta-laravel" "composer.json" "$LARAVEL_VER"
else
    echo "  SKIP: ../scolta-laravel/composer.json not found"
fi

if [ $ERRORS -gt 0 ]; then
    echo ""
    echo "FAILED: $ERRORS version mismatch(es) found."
    exit 1
else
    echo ""
    echo "PASSED: All versions match ($EXPECTED_VERSION)."
fi
