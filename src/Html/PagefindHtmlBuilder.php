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
        array $filters = [],
        array $metadata = [],
        array $sortable = [],
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

        $extraFilters = '';
        foreach ($filters as $key => $value) {
            $escapedKey = htmlspecialchars((string) $key, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                $escapedValue = htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $extraFilters .= sprintf('<span data-pagefind-filter="%s:%s" hidden></span>' . "\n", $escapedKey, $escapedValue);
            }
        }

        $extraMeta = '';
        foreach ($metadata as $key => $value) {
            $escapedKey = htmlspecialchars((string) $key, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $extraMeta .= sprintf('<p data-pagefind-meta="%s:%s" hidden></p>' . "\n", $escapedKey, $escapedValue);
        }

        $sortAttrs = '';
        foreach ($sortable as $key => $value) {
            $escapedKey = htmlspecialchars((string) $key, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $sortAttrs .= sprintf('<p data-pagefind-sort="%s:%s" hidden></p>' . "\n", $escapedKey, $escapedValue);
        }
        if ($date !== '' && !isset($sortable['date'])) {
            $sortAttrs .= sprintf(
                '<p data-pagefind-sort="date:%s" hidden></p>' . "\n",
                htmlspecialchars($date, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }

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
%s%s%s%s%s%s
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
            $extraFilters,
            $extraMeta,
            $sortAttrs,
            $escapedBody
        );
    }
}
