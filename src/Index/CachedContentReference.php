<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Marker object yielded by content gatherers for entities unchanged since the last build.
 *
 * A gatherer compares the entity's changed timestamp against TimestampManifest.
 * When the timestamps match, it yields this reference instead of loading the full
 * entity body. IndexBuildOrchestrator looks up pre-computed token data in
 * PageWordCache via contentHash and builds the chunk entry from the fields here,
 * including metadata, so this class has to carry every field the orchestrator's
 * slim proxy reads off a ContentItem. A field absent here is silently lost on the
 * cached path; SlimProxyFieldParityTest exists to catch that.
 *
 * On token cache miss the orchestrator asks TimestampManifest::isKnownEmpty()
 * what the miss means. For a hash the exporter has recorded as producing no
 * indexable page it is the expected outcome: the entry is marked seen and kept,
 * because re-gathering an entity that will be dropped again buys nothing. For
 * any other hash it is an eviction: markSeen() is skipped, pruneAndSave()
 * removes the entry, and the next build treats the entity as changed, re-loads
 * it, re-tokenizes, and re-populates both cache and manifest.
 *
 * @since 0.3.12
 */
final class CachedContentReference
{
    /**
     * @param string  $entityKey   Key used in TimestampManifest (e.g. entity ID).
     * @param string  $contentHash Hash used to look up token data in PageWordCache.
     * @param string  $id          ContentItem-compatible item ID.
     * @param string  $url         Relative URL for the indexed page.
     * @param string  $date        Publication date (Y-m-d).
     * @param string  $siteName    Site name for the index entry.
     * @param string  $language    BCP-47 language code.
     * @param array   $filters     Pagefind filter key/value pairs.
     * @param array   $sortable    Sortable field values (e.g. ['word_count' => 4200]).
     * @param array<string, mixed> $metadata Arbitrary per-item metadata that reaches the
     *                             fragment's meta map (e.g. ['entity_id' => '4321']).
     */
    public function __construct(
        public readonly string $entityKey,
        public readonly string $contentHash,
        public readonly string $id,
        public readonly string $url,
        public readonly string $date,
        public readonly string $siteName,
        public readonly string $language,
        public readonly array $filters,
        public readonly array $sortable = [],
        public readonly array $metadata = [],
    ) {}
}
