<?php

declare(strict_types=1);

namespace Tag1\Scolta\Environment;

enum HostingEnvironment: string
{
    case WP_ENGINE = 'wp_engine';
    case KINSTA = 'kinsta';
    case FLYWHEEL = 'flywheel';
    case PRESSABLE = 'pressable';
    case PANTHEON = 'pantheon';
    case ACQUIA = 'acquia';
    case PLATFORM_SH = 'platform_sh';
    case VAPOR = 'vapor';
    case RESTRICTED_EXEC = 'restricted_exec';
    case STANDARD = 'standard';
}
