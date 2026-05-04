<?php

declare(strict_types=1);

namespace Tag1\Scolta\Export;

/**
 * A single content item to be exported for Pagefind indexing.
 *
 * Platform adapters (Drupal, WordPress, Laravel) construct these from
 * their native entity/post/model objects. The exporter handles the
 * rest: cleaning, HTML generation, and file writing.
 */
class ContentItem
{
    /** Canonical URL — always stored as a relative path. */
    public readonly string $url;

    public function __construct(
        /** Unique identifier (entity ID, post ID, etc.). Used as filename. */
        public readonly string $id,
        /** Content title. */
        public readonly string $title,
        /** Raw HTML body content. May include page chrome that needs stripping. */
        public readonly string $bodyHtml,
        /** Absolute or relative URL. Absolute URLs are stripped to a path so the
         *  pagefind index is portable across environments (DDEV → production). */
        string $url,
        /** Last-modified date in Y-m-d format. */
        public readonly string $date,
        /** Site name for Pagefind filtering/faceting. */
        public readonly string $siteName = '',
        /** Language code for multi-language Pagefind filtering. */
        public readonly string $language = 'en',
        /** Extra Pagefind filter attributes keyed by filter name.
         *  e.g. ['base_topic' => 'Cardiology']. Values pass directly into
         *  data-pagefind-filter — they bypass HtmlCleaner so HTML is not stripped. */
        public readonly array $filters = [],
    ) {
        // Strip scheme and host so the baked-in URL works on any domain.
        // An index built on DDEV (https://myapp.ddev.site/path) must serve
        // correct links on production (https://myapp.example.com/path).
        if (str_contains($url, '://')) {
            $parsed = parse_url($url);
            $url = ($parsed['path'] ?? '/')
                . (isset($parsed['query']) ? '?' . $parsed['query'] : '')
                . (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '');
        }
        $this->url = $url;
    }
}
