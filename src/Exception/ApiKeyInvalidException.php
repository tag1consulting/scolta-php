<?php

declare(strict_types=1);

namespace Tag1\Scolta\Exception;

/**
 * Thrown when the AI provider rejects the configured API key (HTTP 401).
 *
 * Callers should return a 401 response with an admin-visible message so
 * site administrators can distinguish a bad key from a transient failure.
 *
 * @since 1.0.0
 * @stability experimental
 */
final class ApiKeyInvalidException extends \RuntimeException
{
}
