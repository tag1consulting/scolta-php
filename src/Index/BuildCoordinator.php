<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * State-machine enforcement layer over BuildState.
 *
 * Responsibilities:
 * - On fresh/restart: check for live lock, wipe state, initiate build.
 * - On resume: verify resumable state exists, re-acquire lock.
 * - Exposes commitChunk / chunkFiles / release so callers never touch BuildState directly.
 *
 * "Wipe state" means the lock, the manifest and the committed chunk files, and
 * nothing else. The state directory is shared with PageWordCache,
 * TimestampManifest and PageTableLedger, and clearing their files here is what
 * made a build that did not run to completion in one process destroy the state
 * that keeps the next one warm; see {@see BuildState::cleanup()}.
 *
 * Critical bug fix vs. the old PhpIndexer::processChunk() logic:
 * The old code called cleanup() + initiateBuild() on every chunk-0 invocation,
 * wiping any in-progress resume state. prepare() now only fires once per build
 * and only resets state for fresh/restart intents.
 */
final class BuildCoordinator
{
    private readonly BuildState $state;

    public function __construct(
        private readonly string $stateDir,
        private readonly ?string $hmacSecret = null,
    ) {
        $this->state = new BuildState($stateDir, $hmacSecret);
    }

    /**
     * Prepare for a build according to the intent's mode.
     *
     * @return array The active manifest (freshly written for fresh/restart,
     *               existing for resume).
     *
     * @throws \RuntimeException When a live build is already running.
     * @throws \RuntimeException When resume is requested but no resumable state exists.
     * @throws \RuntimeException When lock acquisition fails.
     * @since 1.0.0
     * @stability stable
     */
    public function prepare(BuildIntent $intent): array
    {
        if ($intent->isFresh()) {
            $manifest = array_merge([
                'total_pages' => $intent->totalPages() ?? 0,
                'chunk_size'  => $intent->memoryBudget()->chunkSize(),
                'language'    => $intent->sourceMeta()['language'] ?? 'en',
                'fingerprint' => $intent->sourceMeta()['fingerprint'] ?? '',
            ], $intent->sourceMeta());

            // Written after the merge with sourceMeta, so an adapter cannot
            // overwrite it by accident: a manifest that under-reports the
            // scope is the manifest that lets finalize() delete the pages
            // this build was never asked to look at.
            $manifest['scope'] = $intent->scope();

            // No isRunning() check and no cleanup() before this call. Both
            // used to happen outside the lock: the check could clear a live
            // build (a PID owned by another uid, or in another container,
            // reads as dead) and cleanup() then deleted that build's lock,
            // manifest and chunk files while it was still writing them.
            // initiateBuild() takes the lock first and clears state after.
            if (!$this->state->initiateBuild($manifest)) {
                throw new \RuntimeException(
                    'Another index build is already running against this state directory. '
                    . 'Wait for it to finish, or stop that process and retry. '
                    . $this->lockDescription(),
                );
            }

            return $manifest;
        }

        // Resume mode.
        $manifest = $this->state->shouldResume();
        if ($manifest === null) {
            throw new \RuntimeException(
                'No resumable build found in state directory. '
                . 'Run without --resume to start a fresh build.',
            );
        }

        if (!$this->state->resumeBuild($manifest)) {
            throw new \RuntimeException(
                'Failed to re-acquire build lock for resume — another build is running. '
                . $this->lockDescription(),
            );
        }

        return $manifest;
    }

    /**
     * Describe the current lock holder, so a refused build says who refused it.
     *
     * The production incident could not be explained after the fact because
     * nothing recorded how the liveness question had been answered.
     */
    private function lockDescription(): string
    {
        $lock = $this->state->lockDiagnostics();
        if ($lock === null) {
            return 'No lock record is readable in ' . $this->stateDir . '.';
        }

        return sprintf(
            'Lock held by pid %s on host %s (generation %s, %s).',
            $lock['pid'] ?? 'unknown',
            $lock['host'] ?? 'unknown',
            $lock['generation'] ?? 'unknown',
            $lock['liveness'],
        );
    }

    /**
     * Commit a completed chunk to the state directory.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function commitChunk(int $chunkNumber, array $partial): void
    {
        $this->state->recordChunk($chunkNumber, $partial);
    }

    /**
     * Return paths to all chunk files written so far.
     *
     * @return string[]
     * @since 1.0.0
     * @stability stable
     */
    public function chunkFiles(): array
    {
        return $this->state->getChunkFiles();
    }

    /**
     * Path to the manifest of the build currently in the state directory.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function manifestFile(): string
    {
        return $this->state->manifestFile();
    }

    /**
     * Return total pages recorded in the manifest.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function pagesProcessed(): int
    {
        return $this->state->getPagesProcessed();
    }

    /**
     * Access the underlying BuildState (for progress / status queries).
     *
     * @since 1.0.0
     * @stability stable
     */
    public function buildState(): BuildState
    {
        return $this->state;
    }

    /**
     * Release the lock and clean up all state files.
     *
     * Call this after a successful build. On failure, call releaseLockOnly()
     * to preserve chunk files for potential resume.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function release(): void
    {
        $this->state->releaseLock();
        $this->state->cleanup();
    }

    /**
     * Release only the lock, preserving chunk files for a future --resume.
     *
     * Leaves the manifest status as 'building' so shouldResume() can detect
     * the interrupted build on next invocation.
     *
     * @since 1.0.0
     * @stability stable
     */
    public function releaseLockOnly(): void
    {
        $this->state->releaseLockOnly();
    }

    /**
     * The scope the build in the state directory declared.
     *
     * `drush scolta:finalize` runs in a process that never saw the
     * BuildIntent, and it does the same stale-release and merge that build()
     * does. Reading the scope back off the manifest is what stops the deferred
     * merge from being the hole in the guard.
     *
     * @return string BuildIntent::SCOPE_FULL or BuildIntent::SCOPE_PARTIAL.
     * @since 1.5.0
     * @stability experimental
     */
    public function declaredScope(): string
    {
        return $this->state->declaredScope();
    }
}
