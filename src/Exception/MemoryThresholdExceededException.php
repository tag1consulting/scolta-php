<?php

declare(strict_types=1);

namespace Tag1\Scolta\Exception;

/**
 * Thrown by MemoryTelemetry when RSS crosses the abort percentage of the
 * effective memory limit during an index build.
 *
 * Extends \RuntimeException so existing broad catch blocks keep working;
 * IndexBuildOrchestrator catches this type to classify the failure as a
 * resumable memory abort instead of matching on the message text.
 *
 * @since 1.0.4
 * @stability experimental
 */
class MemoryThresholdExceededException extends \RuntimeException {}
