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
    public function exists(string $path): bool;

    public function get(string $path): string;

    public function put(string $path, string $contents): bool;

    public function delete(string $path): bool;

    public function deleteDirectory(string $path): bool;

    public function makeDirectory(string $path): bool;

    public function move(string $from, string $to): bool;

    /** @return string[] */
    public function files(string $directory, string $pattern = '*'): array;
}
