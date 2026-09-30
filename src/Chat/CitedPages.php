<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * Which of a turn's pages an answer cited, in order of first citation.
 *
 * A page counts as cited when the answer carries its [n] marker or links its
 * URL (or its path on the same site). An answer that cites nothing, a
 * decline or small talk, therefore gets no source list at all.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class CitedPages
{
    /**
     * @param list<array{n: int, title: string, url: string}> $pages
     * @return list<array{n: int, title: string, url: string}>
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function find(string $answer, array $pages): array
    {
        $firstAt = [];
        foreach ($pages as $i => $page) {
            $offsets = [];
            $marker = strpos($answer, '[' . $page['n'] . ']');
            if ($marker !== false) {
                $offsets[] = $marker;
            }
            $link = strpos($answer, '](' . $page['url'] . ')');
            if ($link !== false) {
                $offsets[] = $link;
            }
            $path = (string) parse_url($page['url'], PHP_URL_PATH);
            if ($path !== '' && $path !== '/'
                && preg_match('#\]\((?:https?://[^/)\s]+)?' . preg_quote($path, '#') . '\)#', $answer, $m, PREG_OFFSET_CAPTURE)) {
                $offsets[] = $m[0][1];
            }
            if ($offsets !== []) {
                $firstAt[$i] = min($offsets);
            }
        }
        asort($firstAt);
        $cited = [];
        foreach (array_keys($firstAt) as $i) {
            $cited[] = $pages[$i];
        }

        return $cited;
    }
}
