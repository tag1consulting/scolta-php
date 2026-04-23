<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Advisory helper for configuration pages.
 *
 * Reads ini_get('memory_limit') and returns a recommendation that framework
 * config UIs can display to the admin. This does NOT change runtime behaviour —
 * it is purely a convenience hint so admins can make an informed choice.
 *
 * Usage (Drupal settings form):
 *   $hint = MemoryBudgetSuggestion::suggest();
 *   // Render $hint['reason'] as helper text above the memory_budget select.
 */
final class MemoryBudgetSuggestion
{
    /**
     * Inspect the PHP memory_limit and return a recommendation.
     *
     * @return array{profile: string, reason: string, detected_limit_bytes: int|null, confidence: string}
     */
    public static function suggest(): array
    {
        $raw   = ini_get('memory_limit');
        $bytes = self::parseBytes($raw);

        if ($bytes === null) {
            return [
                'profile'              => 'conservative',
                'reason'               => 'PHP memory_limit could not be determined. The conservative profile is the safe default.',
                'detected_limit_bytes' => null,
                'confidence'           => 'low',
            ];
        }

        if ($bytes < 0) {
            return [
                'profile'              => 'aggressive',
                'reason'               => 'PHP memory_limit is unlimited (-1). The aggressive profile maximises throughput.',
                'detected_limit_bytes' => null,
                'confidence'           => 'medium',
            ];
        }

        $mb = (int) round($bytes / 1_048_576);

        if ($bytes >= 768 * 1_048_576) {
            return [
                'profile'              => 'aggressive',
                'reason'               => "PHP memory_limit is {$mb}MB. The aggressive profile will maximise indexing throughput.",
                'detected_limit_bytes' => $bytes,
                'confidence'           => 'high',
            ];
        }

        if ($bytes >= 192 * 1_048_576) {
            return [
                'profile'              => 'balanced',
                'reason'               => "PHP memory_limit is {$mb}MB. The balanced profile is recommended.",
                'detected_limit_bytes' => $bytes,
                'confidence'           => 'high',
            ];
        }

        $confidence = $bytes < 64 * 1_048_576 ? 'low' : 'high';

        return [
            'profile'              => 'conservative',
            'reason'               => "PHP memory_limit is {$mb}MB. The conservative profile keeps peak RAM under 96MB.",
            'detected_limit_bytes' => $bytes,
            'confidence'           => $confidence,
        ];
    }

    /**
     * Check whether a named profile fits comfortably within the given PHP memory limit.
     *
     * Returns an array with:
     *   - 'status': 'safe' | 'warn'
     *   - 'warning': null or a human-readable warning string
     *   - 'profile_budget_bytes': the totalBudgetBytes() for the profile
     *   - 'limit_bytes': the detected/passed limit (-1 for unlimited, null for unknown)
     *
     * A profile is "safe" if its totalBudgetBytes is ≤ 70% of the PHP memory limit.
     * When memory_limit is unlimited (-1) or unknown (null), status is always 'safe'.
     *
     * @param string $profile    One of 'conservative', 'balanced', 'aggressive'
     * @param int|null $limitBytes  PHP memory_limit in bytes, or null to read from ini_get.
     *                              Pass -1 to simulate unlimited. Pass null to auto-detect.
     * @return array{status: string, warning: string|null, profile_budget_bytes: int, limit_bytes: int|null}
     */
    public static function checkProfileFit(string $profile, ?int $limitBytes = null): array
    {
        $budget      = MemoryBudget::fromString($profile)->totalBudgetBytes();
        $resolvedLimit = $limitBytes ?? self::parseBytes(ini_get('memory_limit'));

        // Unlimited or unknown: always safe
        if ($resolvedLimit === null || $resolvedLimit < 0) {
            return [
                'status'               => 'safe',
                'warning'              => null,
                'profile_budget_bytes' => $budget,
                'limit_bytes'          => $resolvedLimit,
            ];
        }

        $threshold = 0.70 * $resolvedLimit;

        if ($budget <= $threshold) {
            return [
                'status'               => 'safe',
                'warning'              => null,
                'profile_budget_bytes' => $budget,
                'limit_bytes'          => $resolvedLimit,
            ];
        }

        $budgetMb = (int) round($budget / 1_048_576);
        $limitMb  = (int) round($resolvedLimit / 1_048_576);
        $warning  = "This profile requires approximately {$budgetMb} MB but your PHP memory limit is only {$limitMb} MB."
            . ' Choose a smaller profile or ask your host to raise memory_limit in php.ini.';

        return [
            'status'               => 'warn',
            'warning'              => $warning,
            'profile_budget_bytes' => $budget,
            'limit_bytes'          => $resolvedLimit,
        ];
    }

    /**
     * Return a human-readable description of the PHP memory limit.
     *
     * Examples: "256 MB", "unlimited", "unknown (could not read ini_get)"
     *
     * @param int|null $limitBytes  Pre-resolved limit in bytes, or null to auto-detect.
     */
    public static function getMemoryLimitText(?int $limitBytes = null): string
    {
        $resolved = $limitBytes ?? self::parseBytes(ini_get('memory_limit'));

        if ($resolved === null) {
            return 'unknown (could not read ini_get)';
        }

        if ($resolved < 0) {
            return 'unlimited';
        }

        $mb = (int) round($resolved / 1_048_576);

        return "{$mb} MB";
    }

    private static function parseBytes(string|false $value): ?int
    {
        if ($value === false || $value === '') {
            return null;
        }

        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        $num  = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g'     => $num * 1_073_741_824,
            'm'     => $num * 1_048_576,
            'k'     => $num * 1_024,
            default => is_numeric($value) ? (int) $value : null,
        };
    }
}
