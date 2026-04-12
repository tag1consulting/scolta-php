<?php

declare(strict_types=1);

namespace Tag1\Scolta\Environment;

/**
 * Detect managed hosting environments and their constraints.
 *
 * Used by CLI commands, admin status displays, and the build pipeline
 * to adjust behavior (chunk size, timeout warnings, indexer selection).
 */
class HostingDetector
{
    public static function detect(): HostingEnvironment
    {
        // WordPress managed hosts.
        if (defined('IS_WPE') || getenv('WPE_APIKEY')) {
            return HostingEnvironment::WP_ENGINE;
        }
        if (getenv('KINSTA_CACHE_ZONE')) {
            return HostingEnvironment::KINSTA;
        }
        if (defined('IS_FLYWHEEL')) {
            return HostingEnvironment::FLYWHEEL;
        }
        if (defined('IS_PRESSABLE')) {
            return HostingEnvironment::PRESSABLE;
        }

        // Drupal managed hosts.
        if (getenv('PANTHEON_ENVIRONMENT')) {
            return HostingEnvironment::PANTHEON;
        }
        if (getenv('AH_SITE_ENVIRONMENT')) {
            return HostingEnvironment::ACQUIA;
        }
        if (getenv('PLATFORM_ENVIRONMENT')) {
            return HostingEnvironment::PLATFORM_SH;
        }

        // Laravel serverless.
        if (getenv('VAPOR_SSM_PATH') || getenv('AWS_LAMBDA_FUNCTION_NAME')) {
            return HostingEnvironment::VAPOR;
        }

        // Generic: exec() restricted.
        if (!function_exists('exec') || !is_callable('exec')) {
            return HostingEnvironment::RESTRICTED_EXEC;
        }

        return HostingEnvironment::STANDARD;
    }

    public static function constraints(): HostingConstraints
    {
        return match (self::detect()) {
            HostingEnvironment::PANTHEON => new HostingConstraints(
                maxExecutionTime: 120,
                execAvailable: true,
                note: 'Pantheon has a 120-second hard limit. Use Terminus for large sites.',
            ),
            HostingEnvironment::ACQUIA => new HostingConstraints(
                maxExecutionTime: 300,
                memoryLimit: 128 * 1024 * 1024,
                execAvailable: true,
                note: 'Acquia default memory is 128MB. Use --indexer=php for large sites.',
            ),
            HostingEnvironment::WP_ENGINE,
            HostingEnvironment::KINSTA,
            HostingEnvironment::FLYWHEEL,
            HostingEnvironment::PRESSABLE,
            HostingEnvironment::RESTRICTED_EXEC => new HostingConstraints(
                execAvailable: false,
                note: 'exec() disabled. PHP indexer used automatically.',
            ),
            HostingEnvironment::VAPOR => new HostingConstraints(
                maxExecutionTime: 900,
                ephemeralFilesystem: true,
                note: 'Lambda filesystem is ephemeral. Configure SCOLTA_STATE_DISK for persistent state.',
            ),
            default => new HostingConstraints(),
        };
    }

    /**
     * Get a human-readable description of the detected environment.
     */
    public static function describe(): string
    {
        $env = self::detect();
        $constraints = self::constraints();

        $desc = match ($env) {
            HostingEnvironment::STANDARD => 'Standard hosting',
            default => ucwords(str_replace('_', ' ', $env->value)),
        };

        if ($constraints->note !== '') {
            $desc .= ' — ' . $constraints->note;
        }

        return $desc;
    }
}
