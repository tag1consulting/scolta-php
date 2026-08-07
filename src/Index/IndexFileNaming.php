<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * The filenames of the index files whose contents change between builds.
 *
 * A published index is a directory of static files fetched by URL from a
 * browser. Two of the four file types were named for what they hold rather
 * than what is in them: a fragment for its ordinal and url, an index chunk for
 * its word list. Neither input changes when the file's contents do, so a
 * rebuild rewrote the same names with different bytes, and any cache in front
 * of the directory — the browser's, a CDN's — kept serving the previous
 * build's copy under a name the new `pf_meta` still points at. The reader has
 * no way to tell: it asks for the name the index gave it and gets a stale file
 * back with a 200.
 *
 * `pf_filter` and `pf_meta` were already named for their contents, so both
 * were already immune. This puts the other two on the same footing: a file's
 * name changes whenever its bytes do, which makes every name in the directory
 * safe to cache forever and makes a stale copy unreachable rather than
 * merely unlucky — nothing requests the old name once the new `pf_meta` is
 * live.
 *
 * The identity inputs stay in the hash alongside the contents. They are what
 * keep two files that happen to hold the same bytes apart: every tombstone
 * fragment is byte-identical, and collapsing them onto one name would make
 * one ordinal's cleanup delete another ordinal's file.
 *
 * @since 1.1.1
 * @stability experimental
 */
final class IndexFileNaming
{
    /**
     * The filename a fragment holding this encoded body must have.
     *
     * @param int    $ordinal  The page's ordinal, which keeps tombstones distinct.
     * @param string $url      The page url, part of the pre-existing identity.
     * @param string $fragment The encoded fragment JSON, before compression.
     *
     * @since 1.1.1
     * @stability experimental
     */
    public static function fragmentHash(int $ordinal, string $url, string $fragment): string
    {
        return 'en_' . substr(hash('sha256', $ordinal . "\0" . $url . "\0" . $fragment), 0, 10);
    }

    /**
     * The filename an index chunk holding these words and this body must have.
     *
     * @param list<string> $words The chunk's word list, in the order it was written.
     * @param string       $body  The encoded chunk body, before compression.
     *
     * @since 1.1.1
     * @stability experimental
     */
    public static function chunkHash(array $words, string $body): string
    {
        return 'en_' . substr(hash('sha256', implode(',', $words) . "\0" . $body), 0, 10);
    }
}
