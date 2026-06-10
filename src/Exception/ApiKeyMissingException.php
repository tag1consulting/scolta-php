<?php

declare(strict_types=1);

namespace Tag1\Scolta\Exception;

/**
 * Thrown when an AI operation is attempted without an API key configured.
 *
 * Callers that catch this exception should degrade gracefully — returning
 * an empty/null response rather than a server error — because the missing
 * key is an expected configuration state rather than a transient failure.
 *
 * @since 1.0.0
 * @stability experimental
 */
final class ApiKeyMissingException extends \RuntimeException {}
