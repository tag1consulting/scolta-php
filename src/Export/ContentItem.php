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
    public function __construct(
        /** Unique identifier (entity ID, post ID, etc.). Used as filename. */
        public readonly string $id,
        /** Content title. */
        public readonly string $title,
        /** Raw HTML body content. May include page chrome that needs stripping. */
        public readonly string $bodyHtml,
        /** Canonical URL for this content. */
        public readonly string $url,
        /** Last-modified date in Y-m-d format. */
        public readonly string $date,
        /** Site name for Pagefind filtering/faceting. */
        public readonly string $siteName = '',
    ) {}
}
