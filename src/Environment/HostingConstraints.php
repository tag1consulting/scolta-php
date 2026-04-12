<?php

declare(strict_types=1);

namespace Tag1\Scolta\Environment;

/**
 * Constraints of the detected hosting environment.
 */
class HostingConstraints
{
    public function __construct(
        public readonly int $maxExecutionTime = 0,
        public readonly int $memoryLimit = 0,
        public readonly bool $execAvailable = true,
        public readonly bool $ephemeralFilesystem = false,
        public readonly string $note = '',
    ) {
    }
}
