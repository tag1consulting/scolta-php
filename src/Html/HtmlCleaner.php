<?php

declare(strict_types=1);

namespace Tag1\Scolta\Html;

/**
 * Clean HTML by removing page chrome and extracting main content as plain text.
 *
 * Processing pipeline:
 * 1. Remove HTML comments
 * 2. Extract main content region (id="main-content", falls back to <body>)
 * 3. Remove footer regions (footer tags, footer IDs/classes)
 * 4. Remove script, style, and nav elements
 * 5. Strip all HTML tags
 * 6. Normalize whitespace
 * 7. Remove duplicate title at beginning
 *
 * Ported from scolta-core/src/html.rs — identical regex patterns and logic.
 */
class HtmlCleaner
{
    /**
     * Clean raw HTML into plain text suitable for search indexing.
     */
    public static function clean(string $html, string $title = ''): string
    {
        // 1. Remove HTML comments (can contain tag-like strings)
        $content = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // 2. Extract main content region
        $content = self::extractMainContent($content);

        // 3. Remove footer regions
        $content = preg_replace('/<footer\b[^>]*>.*?<\/footer\s*>/is', '', $content) ?? $content;
        $content = preg_replace('/<[^>]*\sid\s*=\s*["\'][^"\']*footer[^"\']*["\'][^>]*>.*?<\/[^>]*>/is', '', $content) ?? $content;
        $content = preg_replace('/<[^>]*\sclass\s*=\s*["\'][^"\']*footer[^"\']*["\'][^>]*>.*?<\/[^>]*>/is', '', $content) ?? $content;
        $content = preg_replace('/<[^>]*\sclass\s*=\s*["\'][^"\']*region-footer[^"\']*["\'][^>]*>.*?<\/[^>]*>/is', '', $content) ?? $content;

        // 4. Remove script, style, nav elements
        $content = preg_replace('/<script\b[^>]*>.*?<\/script\s*>/is', '', $content) ?? $content;
        $content = preg_replace('/<style\b[^>]*>.*?<\/style\s*>/is', '', $content) ?? $content;
        $content = preg_replace('/<nav\b[^>]*>.*?<\/nav\s*>/is', '', $content) ?? $content;

        // 5. Strip all HTML tags
        $content = strip_tags($content);

        // 5b. Decode HTML entities (&amp; → &, &nbsp; → space, etc.)
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 6. Normalize whitespace
        $content = trim(preg_replace('/\s+/', ' ', $content) ?? $content);

        // 7. Remove leading title if present
        if ($title !== '' && ($pos = strpos($content, $title)) !== false && $pos < 50) {
            $content = ltrim(substr($content, $pos + strlen($title)));
        }

        return $content;
    }

    /**
     * Extract the main content region from HTML.
     *
     * Looks for id="main-content" (case-insensitive), falls back to <body>,
     * falls back to full input.
     */
    private static function extractMainContent(string $html): string
    {
        // Try id="main-content" (handles div, main, article, section)
        if (preg_match('/<(div|main|article|section)\b[^>]*\sid\s*=\s*["\']main-content["\'][^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $tagName = $match[1][0];
            $tagEnd = $match[0][1] + strlen($match[0][0]);

            $closePos = self::findMatchingClose($html, $tagEnd, $tagName);
            if ($closePos !== null) {
                return substr($html, $tagEnd, $closePos - $tagEnd);
            }
        }

        // Fall back to <body> content
        $lower = strtolower($html);
        $bodyStart = strpos($lower, '<body');
        if ($bodyStart !== false) {
            $bodyTagEnd = strpos($html, '>', $bodyStart);
            if ($bodyTagEnd !== false) {
                $bodyTagEnd++;
                $bodyClose = strpos($lower, '</body>', $bodyTagEnd);
                if ($bodyClose !== false) {
                    return substr($html, $bodyTagEnd, $bodyClose - $bodyTagEnd);
                }
            }
        }

        return $html;
    }

    /**
     * Find the matching closing tag, handling nesting.
     */
    private static function findMatchingClose(string $html, int $startPos, string $tagName): ?int
    {
        $search = substr($html, $startPos);
        $openPattern = '<' . $tagName;
        $closePattern = '</' . $tagName;
        $depth = 1;
        $pos = 0;

        while ($pos < strlen($search)) {
            $remaining = substr($search, $pos);
            $nextOpen = stripos($remaining, $openPattern);
            $nextClose = stripos($remaining, $closePattern);

            // Validate open tag (must be followed by space, >, /, tab, newline)
            if ($nextOpen !== false) {
                $afterOpen = $remaining[$nextOpen + strlen($openPattern)] ?? null;
                if (!in_array($afterOpen, [' ', '>', '/', "\t", "\n"], true)) {
                    $nextOpen = false;
                }
            }

            if ($nextOpen !== false && $nextClose !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos += $nextOpen + strlen($openPattern);
            } elseif ($nextClose !== false) {
                $depth--;
                if ($depth === 0) {
                    return $startPos + $pos + $nextClose;
                }
                $pos += $nextClose + strlen($closePattern);
            } elseif ($nextOpen !== false) {
                $depth++;
                $pos += $nextOpen + strlen($openPattern);
            } else {
                break;
            }
        }

        return null;
    }
}
