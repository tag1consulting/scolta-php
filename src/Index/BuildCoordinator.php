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
     */
    public function prepare(BuildIntent $intent): array
    {
        if ($intent->isFresh()) {
            if ($this->state->isRunning()) {
                throw new \RuntimeException(
                    'Another index build is already running. '
                    . 'Wait for it to complete, or kill the process and retry with --restart.'
                );
            }

            $this->state->cleanup();

            $manifest = array_merge([
                'total_pages' => $intent->totalPages() ?? 0,
                'chunk_size'  => $intent->memoryBudget()->chunkSize(),
                'language'    => $intent->sourceMeta()['language'] ?? 'en',
                'fingerprint' => $intent->sourceMeta()['fingerprint'] ?? '',
            ], $intent->sourceMeta());

            if (!$this->state->initiateBuild($manifest)) {
                throw new \RuntimeException(
                    'Failed to acquire build lock — another process may have just started.'
                );
            }

            return $manifest;
        }

        // Resume mode.
        $manifest = $this->state->shouldResume();
        if ($manifest === null) {
            throw new \RuntimeException(
                'No resumable build found in state directory. '
                . 'Run without --resume to start a fresh build.'
            );
        }

        if (!$this->state->initiateBuild($manifest)) {
            throw new \RuntimeException('Failed to re-acquire build lock for resume.');
        }

        return $manifest;
    }

    /**
     * Commit a completed chunk to the state directory.
     */
    public function commitChunk(int $chunkNumber, array $partial): void
    {
        $this->state->recordChunk($chunkNumber, $partial);
    }

    /**
     * Return paths to all chunk files written so far.
     *
     * @return string[]
     */
    public function chunkFiles(): array
    {
        return $this->state->getChunkFiles();
    }

    /**
     * Return total pages recorded in the manifest.
     */
    public function pagesProcessed(): int
    {
        return $this->state->getPagesProcessed();
    }

    /**
     * Access the underlying BuildState (for progress / status queries).
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
     */
    public function releaseLockOnly(): void
    {
        $this->state->releaseLockOnly();
    }
}
