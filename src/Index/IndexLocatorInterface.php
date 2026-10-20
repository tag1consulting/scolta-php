<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Says whether a search index is being served, and where from.
 *
 * The site that renders search is not always the one that builds the index:
 * a platform can point its search UI at an index another host builds and
 * serves. The question "is there an index to search?" then needs a different
 * answer per case: a file check for a local build output directory, an HTTP
 * request for a remote origin. This is that question, so code that asks it,
 * such as HealthChecker, works the same whichever one the site is
 * configured with.
 *
 * Adapters pick the implementation from their own configuration:
 * LocalIndexLocator for an index this site builds, RemoteIndexLocator for one
 * another host serves.
 *
 * @since 2.0.0
 * @stability experimental
 */
interface IndexLocatorInterface
{
    /**
     * Whether an index is being served where this locator looks.
     *
     * For a remote origin this can make a network request; see the
     * implementation for how it is bounded and cached. Do not call it on a
     * page-render path.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function exists(): bool;

    /**
     * Whether this locator looks on another host rather than on local disk.
     *
     * A missing remote index is a different fault from a missing local one,
     * and the fix is on a different host, so callers report the two apart.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function isRemote(): bool;

    /**
     * Where this locator looks: a filesystem path, or a remote base URL.
     *
     * For reporting only. It is the directory the pagefind/ output sits
     * under, without a trailing slash.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function origin(): string;
}
