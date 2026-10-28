<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Another process holds the build lock, so this one did not start.
 *
 * Thrown by {@see BuildCoordinator::prepare()} in place of the generic
 * RuntimeException it used to throw, so the orchestrator can tell "refused at
 * the door" apart from "failed inside". The distinction matters for a resumed
 * build: a refusal records nothing in the state directory, because the build
 * it was refused from is still resumable once the lock is free — whereas a
 * recorded failure is what {@see ResumeChainPolicy::resumable()} reads as
 * "start over".
 *
 * Still a RuntimeException, so every caller that catches the old type keeps
 * working.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class BuildLockHeldException extends \RuntimeException {}
