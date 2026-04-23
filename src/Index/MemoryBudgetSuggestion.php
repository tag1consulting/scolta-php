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
