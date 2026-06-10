<?php

declare(strict_types=1);

namespace Tag1\Scolta\Storage;

/**
 * Abstraction for filesystem operations used by the indexer.
 *
 * Defaults to local filesystem (FilesystemDriver). On serverless
 * platforms (Lambda, containers), swap for cloud storage (S3, GCS)
 * to persist state across invocations.
 */
interface StorageDriverInterface
{
    /**
     * @since 1.0.0
     * @stability stable
     */
    public function exists(string $path): bool;

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function get(string $path): string;

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function put(string $path, string $contents): bool;

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function delete(string $path): bool;

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function deleteDirectory(string $path): bool;

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function makeDirectory(string $path): bool;

    /**
     * @since 1.0.0
     * @stability stable
     */
    public function move(string $from, string $to): bool;

    /**
     * @return string[]
     *
     * @since 1.0.0
     * @stability stable
     */
    public function files(string $directory, string $pattern = '*'): array;
}
