<?php

declare(strict_types=1);

namespace Tag1\Scolta\Export;

use Tag1\Scolta\Html\HtmlCleaner;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\TimestampManifest;

/**
 * Filters content items down to the ones worth indexing.
 *
 * Platform adapters query their CMS, extract fields and construct
 * ContentItem objects; this class decides which of them carry enough text to
 * reach IndexBuildOrchestrator. It writes nothing to disk.
 */
class ContentExporter
{
    public function __construct(
        private readonly int $minContentLength = 50,
    ) {}

    /**
     * Filter content items lazily by minimum content length.
     *
     * Yields ContentItem objects one at a time without pre-loading the entire
     * result set into RAM. Use this in framework adapters where the input
     * comes from a paginated generator so that peak RSS stays bounded
     * regardless of corpus size.
     *
     * CachedContentReference objects (cache-hit markers for unchanged posts)
     * pass through without inspection — they carry no bodyHtml and are handled
     * downstream by IndexBuildOrchestrator.
     *
     * Pass the build's TimestampManifest to have every dropped item's content
     * hash recorded as known-empty. The gatherer has already written those
     * entities into the manifest by the time they get here — it filters on
     * changed timestamps, not on body length — so without the record they come
     * back on the next build as cached references whose token lookup can only
     * miss, and each miss re-gathers an entity that will be dropped again. This
     * is the only place the drop decision is made against a body in memory,
     * which is why the record belongs here and not at the gatherer.
     *
     * @param iterable<ContentItem|CachedContentReference> $items    Items to filter (array or generator).
     * @param TimestampManifest|null                       $manifest Build manifest to record dropped hashes in.
     * @return \Generator<ContentItem|CachedContentReference>      Items that pass the minimum length check, plus all cached references.
     *
     * @since 0.3.2
     * @stability experimental
     */
    public function filterItems(iterable $items, ?TimestampManifest $manifest = null): \Generator
    {
        foreach ($items as $item) {
            if ($item instanceof CachedContentReference) {
                yield $item;
                continue;
            }
            if ($this->hasIndexableText($item)) {
                yield $item;
                continue;
            }
            $manifest?->markEmpty(PhpIndexer::contentHash($item));
        }
    }

    /**
     * Whether an item's body survives cleaning with enough text to index.
     *
     * The single definition of "too short to index" for the streaming path.
     * filterItems() drops what this rejects and records it as known-empty, so
     * the two can never disagree about which items exist downstream.
     *
     * @since 1.3.0
     * @stability experimental
     */
    public function hasIndexableText(ContentItem $item): bool
    {
        // Attachment text counts toward the threshold: a page whose real
        // content is its attachment — a stub linking a worksheet, say — is
        // indexable on the strength of that text alone.
        $length = mb_strlen(HtmlCleaner::clean($item->bodyHtml));
        if ($item->attachmentText !== '') {
            $length += mb_strlen(HtmlCleaner::clean($item->attachmentText));
        }

        return $length >= $this->minContentLength;
    }
}
