<?php

declare(strict_types=1);

namespace Tag1\Scolta\Content;

/**
 * A single change-tracker record.
 *
 * Platform adapters populate their tracker tables with these records
 * when content is created, updated, or deleted. The indexing pipeline
 * consumes them to perform incremental builds.
 */
class TrackerRecord
{
    public const ACTION_INDEX = 'index';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        /** Platform-specific content ID (post ID, entity ID, model key). */
        public readonly string $contentId,
        /** Content type identifier (post_type, bundle, model class). */
        public readonly string $contentType,
        /** 'index' for create/update, 'delete' for removals. */
        public readonly string $action = self::ACTION_INDEX,
        /** When the change was recorded. */
        public readonly ?\DateTimeImmutable $changedAt = null,
    ) {
    }
}
