<?php

declare(strict_types=1);

/**
 * Fetch Wikipedia article summaries to build a multilingual corpus.
 *
 * Uses the Wikipedia REST API summary endpoint:
 *   https://{lang}.wikipedia.org/api/rest_v1/page/summary/{title}
 *
 * Usage:
 *   php scripts/fetch-wikipedia-corpus.php
 *   php scripts/fetch-wikipedia-corpus.php --seed-file=scripts/wikipedia-seed-articles.json
 *   php scripts/fetch-wikipedia-corpus.php --output-dir=tests/fixtures/concordance/corpus-wiki
 *
 * Output: one HTML file per article in the output directory.
 */

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------

$seedFile = __DIR__ . '/wikipedia-seed-articles.json';
$outputDir = __DIR__ . '/../tests/fixtures/concordance/corpus-wiki';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--seed-file=')) {
        $seedFile = substr($arg, strlen('--seed-file='));
        if (!str_starts_with($seedFile, '/')) {
            $seedFile = getcwd() . '/' . $seedFile;
        }
    } elseif (str_starts_with($arg, '--output-dir=')) {
        $outputDir = substr($arg, strlen('--output-dir='));
        if (!str_starts_with($outputDir, '/')) {
            $outputDir = getcwd() . '/' . $outputDir;
        }
    }
}

// ---------------------------------------------------------------------------
// Load seed articles
// ---------------------------------------------------------------------------

if (!file_exists($seedFile)) {
    fwrite(STDERR, "Seed file not found: {$seedFile}\n");
    exit(1);
}

$seedData = json_decode((string) file_get_contents($seedFile), true);
if (!is_array($seedData)) {
    fwrite(STDERR, "Invalid JSON in seed file: {$seedFile}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Prepare output directory
// ---------------------------------------------------------------------------

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// ---------------------------------------------------------------------------
// Fetch and write articles
// ---------------------------------------------------------------------------

$date = '2026-04-14';

foreach ($seedData as $lang => $titles) {
    $nn = 1;
    foreach ($titles as $title) {
        $encodedTitle = rawurlencode($title);
        $url = "https://{$lang}.wikipedia.org/api/rest_v1/page/summary/{$encodedTitle}";

        echo "Fetching {$lang}/{$title}... ";
        flush();

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/json',
                    'User-Agent: ScoltaPhpTests/1.0 (https://github.com/tag1consulting/scolta-php; tests@tag1.com)',
                ],
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500 Error';
        preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $statusMatch);
        $statusCode = (int) ($statusMatch[1] ?? 500);

        $paddedNn = str_pad((string) $nn, 2, '0', STR_PAD_LEFT);
        $filename = "{$lang}-{$paddedNn}.html";
        $outputPath = $outputDir . '/' . $filename;

        if ($responseBody === false || $statusCode >= 400) {
            // Write stub HTML.
            $stubBody = "<p>Article content unavailable. Language: {$lang}, Topic: " . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ".</p>";
            $html = buildHtml($lang, $title, $stubBody, $date);
            file_put_contents($outputPath, $html);
            echo "stub (HTTP {$statusCode})\n";
        } else {
            $data = json_decode($responseBody, true);
            if (!is_array($data)) {
                $stubBody = "<p>Article content unavailable. Language: {$lang}, Topic: " . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ".</p>";
                $html = buildHtml($lang, $title, $stubBody, $date);
                file_put_contents($outputPath, $html);
                echo "stub (invalid JSON)\n";
            } else {
                // Prefer extract_html; fall back to plain text extract.
                $extractHtml = $data['extract_html'] ?? null;
                $extract = $data['extract'] ?? null;

                if ($extractHtml !== null && trim($extractHtml) !== '') {
                    $body = $extractHtml;
                } elseif ($extract !== null && trim($extract) !== '') {
                    $body = '<p>' . htmlspecialchars($extract, ENT_QUOTES, 'UTF-8') . '</p>';
                } else {
                    $body = "<p>Article content unavailable. Language: {$lang}, Topic: " . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ".</p>";
                }

                $pageTitle = $data['title'] ?? $title;
                $html = buildHtml($lang, $pageTitle, $body, $date);
                $bytes = strlen($html);
                file_put_contents($outputPath, $html);
                echo "done ({$bytes} bytes)\n";
            }
        }

        $nn++;

        // Rate limiting: 100ms sleep between requests (Wikipedia API etiquette).
        usleep(100000);
    }
}

echo "\nDone. Files written to: {$outputDir}\n";

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function buildHtml(string $lang, string $title, string $bodyContent, string $date): string
{
    $htmlLang = htmlspecialchars($lang, ENT_QUOTES, 'UTF-8');
    $htmlTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $htmlDate = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="{$htmlLang}">
<head>
<meta charset="utf-8">
<title>{$htmlTitle}</title>
</head>
<body data-pagefind-body>
<h1>{$htmlTitle}</h1>
{$bodyContent}
<p data-pagefind-meta="date:{$htmlDate}" hidden></p>
<p data-pagefind-filter="language:{$htmlLang}" hidden></p>
</body>
</html>
HTML;
}
