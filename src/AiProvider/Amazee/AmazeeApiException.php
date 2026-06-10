<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Thrown when the Amazee.ai API returns an error or an unexpected response.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AmazeeApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * HTTP status code from the API response, or 0 for non-HTTP errors.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
