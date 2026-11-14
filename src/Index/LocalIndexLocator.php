<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Finds an index in a local build output directory.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class LocalIndexLocator implements IndexLocatorInterface
{
    /**
     * @param string $outputDir The build output directory, the one the
     *   pagefind/ directory is written into, without a trailing slash.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function __construct(
        private readonly string $outputDir,
    ) {}

    /**
     * {@inheritdoc}
     *
     * PhpIndexer writes into a pagefind/ subdirectory of the output directory
     * (an atomic swap from .scolta-building to pagefind/). Indexes built before
     * 2.0.0 by the Pagefind binary may sit at the root, so both count.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function exists(): bool
    {
        return file_exists($this->outputDir . '/pagefind/pagefind.js')
            || file_exists($this->outputDir . '/pagefind.js');
    }

    /**
     * {@inheritdoc}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function isRemote(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function origin(): string
    {
        return $this->outputDir;
    }
}
