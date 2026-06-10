<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Immutable value object for a single token.
 *
 * Replaces the associative array ['stem' => ..., 'original' => ..., 'position' => ...]
 * used throughout the indexing pipeline. PHP allocates 3-key arrays with 8 buckets
 * (next power of two), leaving 5 slots empty at ~32 B each. A final readonly class
 * uses ~126 B per instance vs ~399 B for the equivalent 3-key array.
 *
 * Must be final and readonly to allow PHP's optimizer to skip vtable lookups
 * and enable the compact memory layout.
 *
 * @see https://github.com/tag1consulting/scolta-php/issues/87
 *
 * @since 1.0.0
 * @stability experimental
 */
final class Token
{
    public function __construct(
        public readonly string $stem,
        public readonly string $original,
        public readonly int $position,
    ) {}
}
