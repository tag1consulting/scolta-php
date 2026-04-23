<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Thrown when a chunk file uses the pre-0.2.5 serialized format.
 *
 * The streaming merge path requires v2 chunks. Callers that catch this
 * exception should clear state and restart the build.
 */
class OldChunkFormatException extends \RuntimeException
{
}
