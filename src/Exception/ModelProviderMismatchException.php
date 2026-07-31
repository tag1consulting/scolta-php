<?php

declare(strict_types=1);

namespace Tag1\Scolta\Exception;

/**
 * Thrown when a direct provider rejects a model that is not its own.
 *
 * The concrete case this exists for: a site provisioned onto the Amazee.ai
 * LiteLLM gateway stores the gateway's model alias, then switches to a direct
 * Anthropic or OpenAI key. The alias is still configured, the provider does
 * not recognise it, and every AI request fails — previously as a generic
 * error with nothing in it for an operator to search for.
 *
 * The message names both the model and the effective provider. Callers should
 * surface it verbatim rather than collapsing it into a generic AI failure.
 *
 * @since 1.0.6
 * @stability experimental
 */
final class ModelProviderMismatchException extends \RuntimeException
{
    /**
     * @param string           $model    The model identifier that was rejected.
     * @param string           $provider The effective provider that rejected it.
     * @param string           $message  Operator-facing description.
     * @param \Throwable|null  $previous The underlying provider error.
     */
    public function __construct(
        private readonly string $model,
        private readonly string $provider,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The model identifier the provider rejected.
     *
     * @since 1.0.6
     * @stability experimental
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * The effective provider that rejected the model.
     *
     * @since 1.0.6
     * @stability experimental
     */
    public function getProvider(): string
    {
        return $this->provider;
    }
}
