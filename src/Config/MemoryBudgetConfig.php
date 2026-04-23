<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\MemoryBudgetSuggestion;

/**
 * Persisted memory-budget configuration.
 *
 * Wraps a named profile (plus optional raw byte override) and bridges
 * platform-specific config storage to the runtime MemoryBudget instance.
 */
final class MemoryBudgetConfig
{
    private const VALID_PROFILES = ['conservative', 'balanced', 'aggressive'];

    public function __construct(
        private readonly string $profile,
        private readonly ?int $customBytes = null,
    ) {
    }

    public static function defaults(): self
    {
        return new self('conservative');
    }

    /**
     * Hydrate from a raw config array (as stored by platform adapters).
     *
     * @param array{profile?: string, custom_bytes?: int|null} $data
     */
    public static function load(array $data): self
    {
        $profile     = $data['profile'] ?? 'conservative';
        $customBytes = isset($data['custom_bytes']) ? (int) $data['custom_bytes'] : null;

        if (!in_array($profile, self::VALID_PROFILES, true)) {
            $profile = 'conservative';
        }

        return new self($profile, $customBytes ?: null);
    }

    /**
     * Convert to the runtime MemoryBudget used by IndexBuildOrchestrator.
     */
    public function toMemoryBudget(): MemoryBudget
    {
        if ($this->customBytes !== null) {
            return MemoryBudget::fromBytes($this->customBytes);
        }

        return MemoryBudget::fromString($this->profile);
    }

    /**
     * Validate the config; returns an array of error messages (empty = valid).
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];

        if (!in_array($this->profile, self::VALID_PROFILES, true)) {
            $errors[] = sprintf(
                'Invalid memory_budget profile "%s". Must be one of: %s.',
                $this->profile,
                implode(', ', self::VALID_PROFILES),
            );
        }

        if ($this->customBytes !== null && $this->customBytes < 0) {
            $errors[] = 'custom_bytes must be a non-negative integer.';
        }

        return $errors;
    }

    /**
     * Return the advisory suggestion from MemoryBudgetSuggestion.
     *
     * @return array{profile: string, reason: string, detected_limit_bytes: int|null, confidence: string}
     */
    public function suggest(): array
    {
        return MemoryBudgetSuggestion::suggest();
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function customBytes(): ?int
    {
        return $this->customBytes;
    }

    /**
     * Serialize to array for platform config storage.
     *
     * @return array{profile: string, custom_bytes: int|null}
     */
    public function toArray(): array
    {
        return [
            'profile'      => $this->profile,
            'custom_bytes' => $this->customBytes,
        ];
    }
}
