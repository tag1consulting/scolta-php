<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Result from an account upgrade attempt.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class UpgradeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $litellmToken,
        public readonly string $litellmApiUrl,
        public readonly string $region,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @since 1.0.0
     * @stability stable
     */
    public static function success(string $litellmToken, string $litellmApiUrl, string $region): self
    {
        return new self(
            success: true,
            litellmToken: $litellmToken,
            litellmApiUrl: $litellmApiUrl,
            region: $region,
        );
    }

    /**
     * @since 1.0.0
     * @stability stable
     */
    public static function failure(string $error): self
    {
        return new self(
            success: false,
            litellmToken: '',
            litellmApiUrl: '',
            region: '',
            error: $error,
        );
    }
}
