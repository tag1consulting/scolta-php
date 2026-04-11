<?php

declare(strict_types=1);

/**
 * Generate the concordance test corpus.
 *
 * Run: php tests/fixtures/concordance/corpus/generate.php
 * Produces 25 HTML files in the same directory.
 */

$dir = __DIR__;

$pages = [
    '01-simple-paragraph' => [
        'title' => 'Simple Paragraph',
        'body' => 'This is a simple paragraph about search functionality. Scolta provides AI-powered search with Pagefind integration for content management systems.',
        'meta' => ['date' => '2026-04-01'],
        'filters' => ['category' => 'General'],
    ],
    '02-long-content' => [
        'title' => 'Comprehensive Guide to Search',
        'body' => str_repeat('Search engines index content by crawling pages, extracting text, and building inverted indexes. Each word maps to the pages where it appears, along with position information for relevance scoring. Modern search systems combine keyword matching with semantic understanding to deliver accurate results. ', 20),
        'meta' => ['date' => '2026-04-02', 'author' => 'Jeremy Andrews'],
        'filters' => ['category' => 'Technology'],
    ],
    '03-diacritics-accents' => [
        'title' => 'Café Culture and Résumé Writing',
        'body' => 'The café scene in Paris is world-renowned. Naïve tourists often underestimate the résumé of French dining culture. Über-popular bistros serve crème brûlée alongside traditional pâté. The exposé revealed a piñata-themed soirée at the château.',
        'meta' => ['date' => '2026-04-03'],
        'filters' => ['category' => 'Culture'],
    ],
    '04-special-chars' => [
        'title' => 'Special Characters & Symbols',
        'body' => 'Smart quotes "like these" and \'single quotes\' are common. Em-dashes—like this—separate clauses. Ellipsis… trails off. Arrows → point forward. The price is $19.99 (or €17.50). Copyright © 2026.',
        'meta' => ['date' => '2026-04-04'],
        'filters' => ['category' => 'Typography'],
    ],
    '05-numbers-ranges' => [
        'title' => 'Numbers and Ranges',
        'body' => 'The value of pi is approximately 3.14159. Phone numbers like 555-1234 should be indexed. Ranges 1-10 and dates 2026-04-05 are common. The year 2026 appears multiple times in 2026 content from 2026.',
        'meta' => ['date' => '2026-04-05'],
        'filters' => ['category' => 'Mathematics'],
    ],
    '06-repeated-words' => [
        'title' => 'Search Optimization',
        'body' => 'Search is fundamental to web applications. Good search helps users find content quickly. Advanced search features include query expansion. The search engine processes search queries efficiently. Every search result should be relevant to the search terms.',
        'meta' => ['date' => '2026-04-06'],
        'filters' => ['category' => 'Technology'],
    ],
    '07-multi-filter' => [
        'title' => 'Technology News Article',
        'body' => 'Breaking news in the technology sector. New developments in artificial intelligence and machine learning continue to reshape industries worldwide.',
        'meta' => ['date' => '2026-04-07', 'author' => 'Jane Smith'],
        'filters' => ['category' => 'Technology', 'priority' => 'High'],
    ],
    '08-multi-meta' => [
        'title' => 'Featured Article with Rich Metadata',
        'body' => 'This article demonstrates rich metadata handling with multiple fields including author, date, featured image path, and custom taxonomies.',
        'meta' => ['date' => '2026-04-08', 'author' => 'John Doe', 'image' => '/images/featured.jpg'],
        'filters' => ['category' => 'Content'],
    ],
    '09-empty-content' => [
        'title' => 'Empty Page',
        'body' => '',
        'meta' => ['date' => '2026-04-09'],
        'filters' => [],
    ],
    '10-stopwords-only' => [
        'title' => 'Common Words',
        'body' => 'The and or in is a an the of to for with on at by from as into about',
        'meta' => ['date' => '2026-04-10'],
        'filters' => ['category' => 'Test'],
    ],
    '11-cjk-characters' => [
        'title' => '中文搜索测试',
        'body' => '中文内容搜索测试。人工智能驱动的搜索引擎可以处理多种语言的内容。',
        'meta' => ['date' => '2026-04-11'],
        'filters' => ['category' => 'International'],
    ],
    '12-html-entities' => [
        'title' => 'HTML Entities Test',
        'body' => 'Less than &lt; greater than &gt; ampersand &amp; non-breaking space&nbsp;here. Copyright &copy; trademark &trade; registered &reg;.',
        'meta' => ['date' => '2026-04-12'],
        'filters' => ['category' => 'Test'],
    ],
    '13-whitespace-variations' => [
        'title' => 'Whitespace Handling',
        'body' => "Multiple   spaces   between   words.\n\nNew paragraphs separated by double newlines.\tTabs\tare\talso\thandled.",
        'meta' => ['date' => '2026-04-13'],
        'filters' => ['category' => 'Test'],
    ],
    '14-code-blocks' => [
        'title' => 'Code Examples',
        'body' => 'Here is some code: function search(query) { return results.filter(r => r.matches(query)); } The variable searchResults contains the output.',
        'meta' => ['date' => '2026-04-14'],
        'filters' => ['category' => 'Technology'],
    ],
    '15-table-data' => [
        'title' => 'Data Table',
        'body' => 'Product Apple costs $1.99. Product Banana costs $0.99. Product Cherry costs $2.49. Total items: 3.',
        'meta' => ['date' => '2026-04-15'],
        'filters' => ['category' => 'Commerce'],
    ],
    '16-duplicate-content' => [
        'title' => 'Duplicate Paragraphs',
        'body' => 'This paragraph is intentionally repeated. This paragraph is intentionally repeated. This paragraph is intentionally repeated.',
        'meta' => ['date' => '2026-04-16'],
        'filters' => ['category' => 'Test'],
    ],
    '17-case-sensitivity' => [
        'title' => 'Case Sensitivity Test',
        'body' => 'UPPERCASE lowercase MiXeD CaSe. The word SEARCH appears as search and Search and SEARCH. Normalization should handle these.',
        'meta' => ['date' => '2026-04-17'],
        'filters' => ['category' => 'Test'],
    ],
    '18-contractions' => [
        'title' => 'English Contractions',
        'body' => "Don't worry about it's complexity. We've implemented what they're asking for. You'll find that we'd already considered y'all's suggestions. It's been a long road.",
        'meta' => ['date' => '2026-04-18'],
        'filters' => ['category' => 'Language'],
    ],
    '19-hyphenated-words' => [
        'title' => 'Hyphenated Compounds',
        'body' => 'My mother-in-law uses state-of-the-art technology. She needs to re-enter her password for the well-known website. The up-to-date system handles self-service requests.',
        'meta' => ['date' => '2026-04-19'],
        'filters' => ['category' => 'Language'],
    ],
    '20-long-word' => [
        'title' => 'Extremely Long Word',
        'body' => 'The word ' . str_repeat('supercalifragilistic', 5) . ' is very long. Normal words surround it for context and testing purposes.',
        'meta' => ['date' => '2026-04-20'],
        'filters' => ['category' => 'Test'],
    ],
    '21-multilingual-en-de' => [
        'title' => 'English and German Content',
        'body' => 'This page contains both English and German text. Die Suchmaschine verarbeitet mehrsprachige Inhalte. Search engines handle multilingual content effectively.',
        'meta' => ['date' => '2026-04-21'],
        'filters' => ['category' => 'International'],
    ],
    '22-running-guide' => [
        'title' => 'Running and Walking Guide',
        'body' => 'Running improves cardiovascular health. Experienced runners train with intervals. Walking aids recovery between runs. Many runners alternate between running and walking.',
        'meta' => ['date' => '2026-04-22'],
        'filters' => ['category' => 'Health'],
    ],
    '23-installation' => [
        'title' => 'Installation Guide',
        'body' => 'Install the package via Composer. Run composer require tag1/scolta-php to add the dependency. Configuration requires setting an API key and output directory.',
        'meta' => ['date' => '2026-04-23'],
        'filters' => ['category' => 'Technology'],
    ],
    '24-links-content' => [
        'title' => 'Content with Links',
        'body' => 'Visit our documentation for more information. Contact support for help with installation. The API reference covers all available endpoints.',
        'meta' => ['date' => '2026-04-24'],
        'filters' => ['category' => 'Documentation'],
    ],
    '25-scoring-test' => [
        'title' => 'Scoring Algorithm Test',
        'body' => 'The scoring algorithm uses recency decay, title matching, and content matching. Title matches receive a higher boost than content matches. Recency decay follows an exponential half-life model. Results are sorted by composite score descending.',
        'meta' => ['date' => '2026-04-25'],
        'filters' => ['category' => 'Technology'],
    ],
];

echo 'Generating ' . count($pages) . " corpus files...\n";

foreach ($pages as $filename => $page) {
    $html = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n";
    $html .= '<title>' . htmlspecialchars($page['title']) . "</title>\n";
    $html .= "</head>\n<body data-pagefind-body>\n";
    $html .= '<h1>' . htmlspecialchars($page['title']) . "</h1>\n";

    if ($page['body'] !== '') {
        $html .= '<p>' . htmlspecialchars($page['body']) . "</p>\n";
    }

    foreach ($page['meta'] as $key => $value) {
        $html .= "<p data-pagefind-meta=\"{$key}:{$value}\" hidden></p>\n";
    }

    foreach ($page['filters'] as $key => $value) {
        $html .= "<p data-pagefind-filter=\"{$key}:{$value}\" hidden></p>\n";
    }

    $html .= "</body>\n</html>\n";

    file_put_contents("{$dir}/{$filename}.html", $html);
}

echo "Done.\n";
