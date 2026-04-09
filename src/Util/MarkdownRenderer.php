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
        // Escape all HTML entities first for XSS safety.
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Bold: **text** -> <strong>text</strong>
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        // Links: [text](url) -> <a href="url" target="_blank" rel="noopener">text</a>
        $text = preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            '<a href="$2" target="_blank" rel="noopener">$1</a>',
            $text,
        );

        return $text;
    }
}
