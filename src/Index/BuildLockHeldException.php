<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Another process holds the build lock, so this one did not start.
 *
 * Thrown by {@see BuildCoordinator::prepare()} so the orchestrator can tell a
 * refusal from a failure. A refusal records nothing in the state directory:
 * the build it was refused from is still resumable once the lock is free,
 * and a recorded failure is what {@see ResumeChainPolicy::resumable()} reads
 * as "start over".
 *
 * Still a RuntimeException, so callers catching the old type keep working.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class BuildLockHeldException extends \RuntimeException {}
