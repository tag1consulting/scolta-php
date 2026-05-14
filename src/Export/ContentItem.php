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
        /** Key-value metadata pairs emitted as data-pagefind-meta attributes.
         *  Use for typed fields (numeric, date) that support faceting or display.
         *  e.g. ['price' => '29.99', 'published' => '2024-06-15']. */
        public readonly array $metadata = [],
        /** Key-value pairs emitted as both data-pagefind-meta and data-pagefind-sort.
         *  e.g. ['price' => '29.99', 'rating' => '4.5']. */
        public readonly array $sortable = [],
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

    /**
     * Create a copy of this ContentItem with specific fields overridden.
     *
     * Use this instead of constructing a new ContentItem from scratch when modifying
     * an existing item — direct construction silently drops any fields you forget to
     * pass (including metadata and sortable). cloneWith() carries all fields forward
     * and only replaces what is explicitly provided.
     *
     * Example (WordPress mu-plugin enriching body HTML):
     *   $item = $item->cloneWith(['bodyHtml' => $enrichedHtml]);
     *
     * @param array<string, mixed> $overrides Field values to override (keyed by property name).
     * @return self A new ContentItem with all fields from this item except those overridden.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public function cloneWith(array $overrides = []): self
    {
        return new self(
            id: $overrides['id'] ?? $this->id,
            title: $overrides['title'] ?? $this->title,
            bodyHtml: $overrides['bodyHtml'] ?? $this->bodyHtml,
            url: $overrides['url'] ?? $this->url,
            date: $overrides['date'] ?? $this->date,
            siteName: $overrides['siteName'] ?? $this->siteName,
            language: $overrides['language'] ?? $this->language,
            filters: $overrides['filters'] ?? $this->filters,
            metadata: $overrides['metadata'] ?? $this->metadata,
            sortable: $overrides['sortable'] ?? $this->sortable,
        );
    }
}
