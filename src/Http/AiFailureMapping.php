<?php

declare(strict_types=1);

namespace Tag1\Scolta\Http;

use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ModelProviderMismatchException;
use Tag1\Scolta\Exception\RateLimitException;

/**
 * How a failed AI call becomes an error result, for the handlers that report
 * failures to the visitor instead of degrading (the search follow up and the
 * chat). The using class provides a PSR-3 `$logger` property.
 *
 * @internal
 */
trait AiFailureMapping
{
    /**
     * @param string $operation   Human-readable operation name for the log.
     * @param string $unavailable The error for a failure with no better name.
     *
     * @return array{ok: false, status: int, error: string, retry_after?: string}
     */
    private function aiFailureResult(\Exception $e, string $operation, string $unavailable): array
    {
        if ($e instanceof ApiKeyInvalidException) {
            $this->logger->error('Scolta ' . $operation . ' failed: invalid API key', ['exception' => $e]);

            return ['ok' => false, 'status' => 401, 'error' => 'AI API key is invalid or expired'];
        }
        if ($e instanceof RateLimitException) {
            $result = ['ok' => false, 'status' => 429, 'error' => 'AI API rate limit reached'];
            if ($e->retryAfter !== null) {
                $result['retry_after'] = $e->retryAfter;
            }

            return $result;
        }
        if ($e instanceof ModelProviderMismatchException) {
            $this->logModelProviderMismatch($e, $operation);

            // An explicit visitor action reports rather than degrades, and the
            // message is the operator-facing one, not a generic failure string.
            return ['ok' => false, 'status' => 503, 'error' => $e->getMessage()];
        }
        $this->logger->error('Scolta ' . $operation . ' failed', ['exception' => $e]);

        return ['ok' => false, 'status' => 503, 'error' => $unavailable];
    }

    /**
     * Log a provider/model mismatch with both values as structured context.
     *
     * The model and provider go in the context array as well as the message:
     * this is the entry an operator greps for, and structured fields survive
     * log aggregation that truncates or reformats messages.
     *
     * @param ModelProviderMismatchException $e         The mismatch to report.
     * @param string                         $operation Human-readable operation name.
     */
    private function logModelProviderMismatch(
        ModelProviderMismatchException $e,
        string $operation,
    ): void {
        $this->logger->error(
            'Scolta ' . $operation . ' failed: ' . $e->getMessage(),
            [
                'exception' => $e,
                'ai_model' => $e->getModel(),
                'ai_provider' => $e->getProvider(),
            ],
        );
    }
}
