<?php

declare(strict_types=1);

namespace Tag1\Scolta\Content;

use Tag1\Scolta\Export\ContentItem;

/**
 * Interface for platform-specific content sources.
 *
 * Each platform adapter (Drupal, WordPress, Laravel) implements this
 * to enumerate content from its native storage. The indexing pipeline
 * calls these methods; the results are passed to ContentExporter for
 * HTML generation and Pagefind indexing.
 *
 * Drupal's implementation lives in scolta-drupal and delegates to
 * Search API's datasource/tracker system. WordPress and Laravel
 * implement this directly against their native query APIs.
 */
interface ContentSourceInterface
{
    /**
     * Yield all published content items for full reindexing.
     *
     * @param array $options Platform-specific options (content types,
     *   post types, model classes, etc.)
     *
     * @return iterable<ContentItem>
     */
    public function getPublishedContent(array $options = []): iterable;

    /**
     * Yield only content items that have changed since the last index.
     *
     * Uses the platform's native change tracking mechanism:
     *   - Drupal: Search API tracker
     *   - WordPress: scolta_tracker table (populated by save_post hooks)
     *   - Laravel: scolta_tracker table (populated by model observers)
     *
     * @return iterable<ContentItem>
     */
    public function getChangedContent(): iterable;

    /**
     * Get IDs of content that has been deleted since the last index.
     *
     * @return string[] Content IDs to remove from the index.
     */
    public function getDeletedIds(): array;

    /**
     * Mark all tracked changes as processed after a successful build.
     */
    public function clearTracker(): void;

    /**
     * Get the total count of published content items.
     */
    public function getTotalCount(array $options = []): int;

    /**
     * Get the count of items pending reindexing.
     */
    public function getPendingCount(): int;
}
