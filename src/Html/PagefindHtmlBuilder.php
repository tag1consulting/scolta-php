<?php

declare(strict_types=1);

namespace Tag1\Scolta\Html;

/**
 * Build minimal HTML documents with Pagefind data attributes for indexing.
 *
 * Ported from scolta-core/src/html.rs — identical output format.
 */
class PagefindHtmlBuilder
{
    /**
     * Build a Pagefind-compatible HTML document.
     */
    public static function build(
        string $id,
        string $title,
        string $body,
        string $url,
        string $date = '',
        string $siteName = '',
        string $language = 'en',
    ): string {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedBody = htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escapedLang = htmlspecialchars($language, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $siteFilter = '';
        if ($siteName !== '') {
            $escapedSite = htmlspecialchars($siteName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $siteFilter = sprintf(' data-pagefind-filter="site:%s"', $escapedSite);
        }

        $dateMeta = '';
        if ($date !== '') {
            $escapedDate = htmlspecialchars($date, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $dateMeta = sprintf('<p data-pagefind-meta="date:%s" hidden></p>' . "\n", $escapedDate);
        }

        $langFilter = sprintf('<span data-pagefind-filter="language:%s" hidden></span>' . "\n", $escapedLang);

        return sprintf(
            '<!DOCTYPE html>
<html lang="%s">
<head>
<meta charset="utf-8">
<title>%s</title>
</head>
<body data-pagefind-body id="%s"%s>
<h1>%s</h1>
<p data-pagefind-meta="url:%s" hidden></p>
%s%s%s
</body>
</html>',
            $escapedLang,
            $escapedTitle,
            $id,
            $siteFilter,
            $escapedTitle,
            $escapedUrl,
            $dateMeta,
            $langFilter,
            $escapedBody
        );
    }
}
