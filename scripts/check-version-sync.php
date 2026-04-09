<?php
/**
 * Verify all Scolta packages share the same major version.
 *
 * Run from anywhere in the workspace:
 *   php packages/scolta-php/scripts/check-version-sync.php
 */

$baseDir = dirname(__DIR__, 2);
$packages = ['scolta-core', 'scolta-php', 'scolta-drupal', 'scolta-laravel', 'scolta-wp'];
$versions = [];

foreach ($packages as $pkg) {
    if ($pkg === 'scolta-core') {
        $cargoPath = "{$baseDir}/{$pkg}/Cargo.toml";
        if (!file_exists($cargoPath)) {
            $versions[$pkg] = 'NOT FOUND';
            continue;
        }
        $cargo = file_get_contents($cargoPath);
        preg_match('/^version\s*=\s*"([^"]+)"/m', $cargo, $m);
        $versions[$pkg] = $m[1] ?? 'PARSE ERROR';
    } else {
        $composerPath = "{$baseDir}/{$pkg}/composer.json";
        if (!file_exists($composerPath)) {
            $versions[$pkg] = 'NOT FOUND';
            continue;
        }
        $composer = json_decode(file_get_contents($composerPath), true);
        $versions[$pkg] = $composer['version'] ?? 'MISSING';
    }
}

$majors = [];
foreach ($versions as $pkg => $ver) {
    $clean = str_replace('-dev', '', $ver);
    $parts = explode('.', $clean);
    $major = $parts[0] ?? '?';
    $majors[$pkg] = $major;
    echo sprintf("%-20s %s (major: %s)\n", $pkg, $ver, $major);
}

$unique = array_unique(array_values($majors));
if (count($unique) > 1) {
    echo "\nFAIL: Major versions are not synchronized\n";
    exit(1);
}

echo "\nPASS: All packages on major version {$unique[0]}\n";
