<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Thrown when an incremental update cannot be applied exactly.
 *
 * The caller's correct response is always the same: run a full build. This is
 * a distinct type rather than a boolean return so that "I could not do this"
 * cannot be mistaken for "there was nothing to do", and so the reason reaches
 * the operator instead of being swallowed.
 *
 * @since 1.1.1
 * @stability experimental
 */
final class IncrementalUpdateUnavailable extends \RuntimeException {}
