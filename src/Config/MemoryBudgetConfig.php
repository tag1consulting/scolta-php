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
    private const NAMED_PROFILES = ['conservative', 'balanced', 'aggressive'];

    public function __construct(
        private readonly string $profile,
        private readonly ?int $customBytes = null,
        private readonly ?int $chunkSize = null,
    ) {
    }

    public static function defaults(): self
    {
        return new self('conservative');
    }

    /**
     * Hydrate from a raw config array (as stored by platform adapters).
     *
     * @param array{profile?: string, custom_bytes?: int|null, chunk_size?: int|null} $data
     */
    public static function load(array $data): self
    {
        $profile     = $data['profile'] ?? 'conservative';
        $customBytes = isset($data['custom_bytes']) ? (int) $data['custom_bytes'] : null;
        $chunkSize   = isset($data['chunk_size']) && (int) $data['chunk_size'] >= 1
            ? (int) $data['chunk_size']
            : null;

        if (!self::isValidMemoryString($profile)) {
            $profile = 'conservative';
        }

        return new self($profile, $customBytes ?: null, $chunkSize);
    }

    /**
     * Convert to the runtime MemoryBudget used by IndexBuildOrchestrator.
     *
     * Uses MemoryBudget::fromOptions() so chunk_size and the memory string
     * are applied together via the shared, well-tested factory path.
     */
    public function toMemoryBudget(): MemoryBudget
    {
        // Legacy custom_bytes path: convert to byte string so fromOptions() handles it.
        $memoryStr = $this->customBytes !== null
            ? (string) $this->customBytes
            : $this->profile;

        return MemoryBudget::fromOptions($memoryStr, $this->chunkSize);
    }

    /**
     * Validate the config; returns an array of error messages (empty = valid).
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];

        if (!self::isValidMemoryString($this->profile)) {
            $errors[] = sprintf(
                'Invalid memory_budget profile "%s". '
                . 'Must be a named profile (%s) or a byte value like "256M".',
                $this->profile,
                implode(', ', self::NAMED_PROFILES),
            );
        }

        if ($this->customBytes !== null && $this->customBytes < 0) {
            $errors[] = 'custom_bytes must be a non-negative integer.';
        }

        if ($this->chunkSize !== null && $this->chunkSize < 1) {
            $errors[] = 'chunk_size must be a positive integer.';
        }

        return $errors;
    }

    private static function isValidMemoryString(string $value): bool
    {
        return in_array($value, self::NAMED_PROFILES, true)
            || (bool) preg_match('/^\d+[KkMmGg]?$/', $value);
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

    /** Pages-per-chunk override, or null to use the profile default. */
    public function chunkSize(): ?int
    {
        return $this->chunkSize;
    }

    /**
     * Serialize to array for platform config storage.
     *
     * @return array{profile: string, custom_bytes: int|null, chunk_size: int|null}
     */
    public function toArray(): array
    {
        return [
            'profile'      => $this->profile,
            'custom_bytes' => $this->customBytes,
            'chunk_size'   => $this->chunkSize,
        ];
    }
}
