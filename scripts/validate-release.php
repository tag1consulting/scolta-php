<?php
/**
 * Validate scolta-php is ready for release.
 */
$composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
$version = $composer['version'] ?? 'MISSING';

echo "Version: {$version}\n";

$fail = false;

if ($version === 'MISSING') {
    echo "FAIL: No version field in composer.json\n";
    $fail = true;
}

if (str_ends_with($version, '-dev')) {
    echo "FAIL: Version ends in -dev\n";
    $fail = true;
}

// Check WASM binary exists.
if (!file_exists(__DIR__ . '/../assets/wasm/scolta_core_bg.wasm')) {
    echo "FAIL: WASM binary not found at assets/wasm/scolta_core_bg.wasm.\n";
    $fail = true;
}

if (!$fail) {
    echo "PASS: Ready to release {$version}\n";
} else {
    exit(1);
}
