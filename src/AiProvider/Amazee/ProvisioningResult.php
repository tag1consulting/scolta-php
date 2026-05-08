<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Result from a trial provisioning attempt.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class ProvisioningResult
{
    public const STATUS_PROVISIONED = 'provisioned';
    public const STATUS_SKIPPED_EXISTING_PROVIDER = 'skipped_existing_provider';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly bool $success,
        public readonly string $litellmToken,
        public readonly string $litellmApiUrl,
        public readonly string $region,
        public readonly ?string $error = null,
        public readonly string $status = self::STATUS_PROVISIONED,
        public readonly ?string $aiModel = null,
        public readonly ?string $aiExpansionModel = null,
    ) {
    }

    public static function success(
        string $litellmToken,
        string $litellmApiUrl,
        string $region,
        ?string $aiModel = null,
        ?string $aiExpansionModel = null,
    ): self {
        return new self(
            success: true,
            litellmToken: $litellmToken,
            litellmApiUrl: $litellmApiUrl,
            region: $region,
            status: self::STATUS_PROVISIONED,
            aiModel: $aiModel,
            aiExpansionModel: $aiExpansionModel,
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            litellmToken: '',
            litellmApiUrl: '',
            region: '',
            error: $error,
            status: self::STATUS_FAILED,
        );
    }

    public static function skippedExistingProvider(): self
    {
        return new self(
            success: true,
            litellmToken: '',
            litellmApiUrl: '',
            region: '',
            status: self::STATUS_SKIPPED_EXISTING_PROVIDER,
        );
    }
}
