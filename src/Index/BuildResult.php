<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Result of a PHP indexing build operation.
 */
class BuildResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly int $pageCount,
        public readonly int $fileCount,
        public readonly float $elapsedSeconds,
        public readonly ?string $error = null,
    ) {
    }
}
