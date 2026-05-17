<?php

declare(strict_types=1);

namespace Tag1\Scolta\Exception;

/**
 * Thrown when the AI provider responds with HTTP 429 (rate limited).
 *
 * Callers should return a 429 response and include the Retry-After value
 * as a header so clients know when to retry.
 *
 * @since 1.0.0
 * @stability experimental
 */
final class RateLimitException extends \RuntimeException
{
    /**
     * @param string|null $retryAfter Value of the Retry-After header from the provider (seconds or HTTP-date), or null if absent.
     */
    public function __construct(
        string $message = '',
        public readonly ?string $retryAfter = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
