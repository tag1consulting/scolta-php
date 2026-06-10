<?php

declare(strict_types=1);

namespace Tag1\Scolta\Util;

/**
 * Lightweight markdown-to-HTML renderer for AI responses.
 *
 * Handles: bold, links, bullet lists, paragraphs.
 * All output is HTML-escaped for XSS safety — text is escaped first,
 * then safe structural tags are applied via regex.
 *
 * @since 0.2.0
 * @stability experimental
 */
final class MarkdownRenderer
{
    /**
     * Render markdown text to sanitized HTML.
     *
     * @param string $markdown Raw markdown text from AI response.
     * @return string Sanitized HTML string.
     *
     * @since 0.2.0
     * @stability experimental
     */
    public static function render(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        $lines = explode("\n", $markdown);
        $html = '';
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                continue;
            }

            if (str_starts_with($trimmed, '- ')) {
                if (!$inList) {
                    $html .= '<ul>';
                    $inList = true;
                }
                $html .= '<li>' . self::renderInline(substr($trimmed, 2)) . '</li>';
            } else {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                $html .= '<p>' . self::renderInline($trimmed) . '</p>';
            }
        }

        if ($inList) {
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * Render inline markdown (bold, links) with XSS-safe escaping.
     *
     * Text is HTML-escaped first, then safe inline elements are applied.
     *
     * @param string $text Raw inline text.
     * @return string HTML-safe inline content.
     */
    private static function renderInline(string $text): string
    {
        $text = self::cleanBrokenLinks($text);

        // Escape all HTML entities first for XSS safety.
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Bold+italic: ***text*** -> <strong><em>text</em></strong>
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);

        // Bold: **text** -> <strong>text</strong>
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        // Italic: *text* -> <em>text</em>
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // Links: [text](url) -> <a href="url" target="_blank" rel="noopener">text</a>
        // Only http(s) and scheme-less (relative) URLs become links; any other
        // scheme (javascript:, data:, vbscript:, …) renders as plain text.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            static function (array $m): string {
                if (!self::isSafeLinkUrl($m[2])) {
                    return $m[1];
                }
                return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
            },
            $text,
        );

        return $text;
    }

    /**
     * Whether a markdown link URL is safe to emit as an href.
     *
     * Allows absolute http(s) URLs and relative paths (no scheme). Control
     * characters and whitespace are stripped before scheme detection because
     * browsers ignore them when parsing a scheme ("jav\tascript:" executes).
     */
    private static function isSafeLinkUrl(string $url): bool
    {
        $cleaned = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';
        if (preg_match('#^https?://#i', $cleaned)) {
            return true;
        }
        // Any other scheme is unsafe; scheme-less URLs are relative paths.
        return preg_match('#^[a-z][a-z0-9+.\-]*:#i', $cleaned) !== 1;
    }

    /**
     * Remove or salvage broken markdown link syntax produced by truncated AI output.
     *
     * Converts [text](unclosed-url to **text** and [text] (no url) to **text**.
     * Must run before htmlspecialchars() since it matches raw markdown characters.
     */
    private static function cleanBrokenLinks(string $text): string
    {
        // [text]( with no closing ) — truncated URL, keep the label as bold.
        $text = preg_replace('/\[([^\]]+)\]\([^)]*$/', '**$1**', $text);
        // [text] with no following (url) — orphaned bracket, keep label as bold.
        $text = preg_replace('/\[([^\]]+)\](?!\()/', '**$1**', $text);
        return $text;
    }
}
