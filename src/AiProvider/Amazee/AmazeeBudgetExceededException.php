<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Thrown when the Amazee.ai API rejects a request because the account's
 * AI budget has been exhausted.
 *
 * The API returns HTTP 429 with the body message "Budget has been exceeded!"
 * This exception is thrown by BudgetAwareProviderDecorator so callers can
 * distinguish budget exhaustion from transient rate limiting.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AmazeeBudgetExceededException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Amazee.ai AI budget has been exceeded. Upgrade your plan to continue.', 0, $previous);
    }
}
