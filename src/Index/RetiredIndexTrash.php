<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;

/**
 * Retire index directories by rename; delete them on a scheduled sweep.
 *
 * The atomic swap used to delete the retired index inline, file by file,
 * after publishing the new one. On NFS-backed hosting that unlink loop runs
 * at single-digit files per second, so a full-corpus index (~100k fragment
 * files) kept the build process busy for hours after the last progress line
 * — indistinguishable from a hang, even though the new index was already
 * live. A rename on the same filesystem is O(1) regardless of directory
 * size, so retire() moves the directory to a uniquely named
 * `.scolta-trash-*` sibling and returns immediately.
 *
 * sweep() deletes trash after the swap has published — parallel unlinking
 * makes that minutes rather than hours under a CLI process (a build, `drush
 * cron`, `drush scolta:cleanup`), and the notice it logs keeps the wait from
 * reading as a hang. A caller running under a request-serving SAPI — a
 * hook_cron() triggered by the web cron endpoint rather than drush — gets
 * the serial fallback instead; see canFastDelete(). The orchestrator sweeps
 * right after each swap; adapters also run it from scheduled maintenance as
 * the backstop for builds that die before their sweep (scolta-drupal: a
 * time-boxed sweep from hook_cron() and `drush scolta:cleanup` for on-demand
 * runs).
 * $outputDir is the directory that holds the published `pagefind/` directory
 * — the same value the orchestrator's constructor takes, after its
 * `/pagefind`-suffix normalization.
 */
final class RetiredIndexTrash
{
    public const PREFIX = '.scolta-trash-';

    /**
     * Concurrent `xargs -0 rm -f` children the fast path feeds.
     *
     * The serial unlink loop is bound by per-operation NFS latency (~8
     * files/second observed), not throughput, so parallel workers scale
     * nearly linearly until the server saturates. The children are tiny;
     * sixteen is far below where an NFS server pushes back.
     */
    private const PARALLEL_WORKERS = 16;

    public function __construct(
        private readonly StorageDriverInterface $storage,
        private readonly string $outputDir,
    ) {}

    /**
     * Move a directory to a uniquely named trash sibling.
     *
     * O(1): no file inside the directory is touched. Returns false when the
     * rename fails; the directory is then still at its original path.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function retire(string $dir): bool
    {
        return $this->storage->move($dir, $this->outputDir . '/' . self::PREFIX . uniqid('', true));
    }

    /**
     * The trash directories currently awaiting deletion.
     *
     * @return string[]
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function trashDirs(): array
    {
        return $this->storage->files($this->outputDir, self::PREFIX . '*');
    }

    /**
     * Delete trash directories in the output directory.
     *
     * Where the storage is the local filesystem and the platform allows it,
     * files are unlinked by parallel worker processes; otherwise the storage
     * driver deletes serially. $maxSeconds bounds the wall-clock spent —
     * cron callers pass a budget so a sweep never monopolises a cron run —
     * and a sweep that runs out of time simply stops: whatever it deleted
     * stays deleted, whatever remains still matches the trash pattern and is
     * picked up by the next sweep. Returns true when no trash remains.
     *
     * Best-effort by design: a directory that cannot be deleted is logged
     * and left for the next sweep. Nothing here throws, because failing to
     * remove trash must never fail the caller.
     *
     * @since 1.5.0
     * @stability experimental
     */
    public function sweep(LoggerInterface $logger, ?float $maxSeconds = null): bool
    {
        $trashDirs = $this->trashDirs();
        if ($trashDirs === []) {
            return true;
        }

        $deadline = $maxSeconds !== null ? microtime(true) + $maxSeconds : null;

        // notice, not info: drush hides info-level lines at default
        // verbosity, and this deletion is exactly the long silent pause an
        // operator would otherwise read as a hang.
        $logger->notice(
            '[scolta] Deleting {count} retired index director(ies): {dirs}.'
            . ' On network filesystems this can take a long time; the live index is not affected.',
            ['count' => count($trashDirs), 'dirs' => implode(', ', $trashDirs)],
        );

        $complete = true;
        foreach ($trashDirs as $i => $dir) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $logger->notice(
                    '[scolta] Sweep time budget reached with {remaining} retired index director(ies) left; the next sweep resumes where this one stopped.',
                    ['remaining' => count($trashDirs) - $i],
                );

                return false;
            }

            if (!$this->deleteTrashDir($dir, $deadline)) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    // Ran out of time mid-directory rather than failed:
                    // handled by the budget notice on the next iteration
                    // (or here, when this was the last directory).
                    $complete = false;
                    continue;
                }
                $complete = false;
                $logger->warning(
                    '[scolta] Could not delete retired index directory {dir}; deletion will be retried on the next sweep.',
                    ['dir' => $dir],
                );
            }
        }

        if (!$complete && $deadline !== null && microtime(true) >= $deadline) {
            $logger->notice(
                '[scolta] Sweep time budget reached; the next sweep resumes where this one stopped.',
            );
        }

        return $complete;
    }

    /**
     * Delete one trash directory, preferring the parallel fast path.
     */
    private function deleteTrashDir(string $dir, ?float $deadline): bool
    {
        if ($this->canFastDelete() && $this->fastDelete($dir, $deadline)) {
            return true;
        }

        // Serial fallback. Also mops up after a fast delete that failed for
        // a reason other than the deadline (a worker died, xargs missing):
        // the walk sees only what is still on disk, so nothing is retried
        // twice. Not attempted once the budget is spent.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return false;
        }

        return $this->storage->deleteDirectory($dir);
    }

    /**
     * Whether parallel worker deletion is available.
     *
     * Local filesystem only — a cloud storage driver has no local paths for
     * `rm` to act on (and no NFS latency problem either) — on a POSIX
     * platform with process spawning enabled. CLI only, deliberately: a
     * request-serving SAPI (php-fpm, `php -S`, mod_php) owns its worker
     * process's lifecycle in ways a CLI script does not — a web server may
     * recycle or kill the worker mid-request, and some hosts sandbox web
     * workers more tightly than CLI (seccomp/AppArmor profiles that permit
     * `proc_open` for a shell but not for FastCGI). Sixteen child processes
     * spawned from inside such a worker is new, uncommon ground; a CLI
     * script — a build, `drush cron`, `drush scolta:cleanup` — owns its own
     * process and is exactly the context every other caller of sweep()
     * already runs in. A request-triggered `hook_cron()` (the one caller
     * that is not CLI) falls back to serial deletion instead.
     */
    private function canFastDelete(): bool
    {
        return $this->storage instanceof FilesystemDriver
            && \PHP_OS_FAMILY !== 'Windows'
            && \function_exists('proc_open')
            && \PHP_SAPI === 'cli';
    }

    /**
     * Unlink a tree's files with parallel workers, then rmdir bottom-up.
     *
     * Returns false when the tree is not fully gone — deadline hit, a worker
     * failed, or the environment lacks xargs/rm — leaving the remainder for
     * the serial fallback or the next sweep.
     */
    private function fastDelete(string $dir, ?float $deadline): bool
    {
        $workers = $this->spawnWorkers();
        if ($workers === []) {
            return false;
        }
        // Kept separate from $workers[$i]['proc'] because the loop below
        // nulls out $workers[$i]['stdin'] as workers die, and PHPStan cannot
        // tell that the sibling 'proc' key survives that partial update.
        $procs = array_column($workers, 'proc');

        $complete = true;
        $subDirs  = [];
        try {
            $items = new \RecursiveIteratorIterator(
                // Symlinks are never followed: a link (to a file or a
                // directory) is handed to `rm -f`, which removes the link
                // itself and leaves the target alone.
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            // Indices into $workers that are still writable, in round-robin
            // order. A dead worker is spliced out here rather than merely
            // marked null-and-skipped: round-robining a fixed 16-slot modulo
            // while only $alive tries are budgeted can walk straight through
            // that many dead slots in a row without ever reaching a live one
            // elsewhere in the array, abandoning the fast path even though a
            // worker is still up. Indexing into a list that only ever holds
            // live workers can't miss one that way.
            $aliveIndices = array_keys($workers);
            $n = 0;
            foreach ($items as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    // CHILD_FIRST yields children before parents, so this
                    // collects deepest-first — the order rmdir needs.
                    $subDirs[] = $item->getPathname();
                    continue;
                }
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $complete = false;
                    break;
                }

                // Round-robin across the still-alive workers.
                $written = false;
                while ($aliveIndices !== []) {
                    $pos    = $n % count($aliveIndices);
                    $w      = $aliveIndices[$pos];
                    $stdin  = $workers[$w]['stdin'];
                    if ($stdin !== null && @fwrite($stdin, $item->getPathname() . "\0") !== false) {
                        $written = true;
                        $n++;
                        break;
                    }
                    if ($stdin !== null) {
                        fclose($stdin);
                    }
                    $workers[$w]['stdin'] = null;
                    array_splice($aliveIndices, $pos, 1);
                }
                if (!$written) {
                    // Every worker is gone (xargs/rm unavailable, or all
                    // died). Let the serial fallback take over.
                    $complete = false;
                    break;
                }
            }
        } finally {
            foreach ($workers as $w) {
                if ($w['stdin'] !== null) {
                    fclose($w['stdin']);
                }
            }
            foreach ($procs as $proc) {
                if (proc_close($proc) !== 0) {
                    $complete = false;
                }
            }
        }

        if (!$complete) {
            return false;
        }

        foreach ($subDirs as $d) {
            if (!@rmdir($d)) {
                return false;
            }
        }

        return @rmdir($dir);
    }

    /**
     * Spawn the `xargs -0 rm -f` worker processes.
     *
     * @return list<array{proc: resource, stdin: resource|null}>
     */
    private function spawnWorkers(): array
    {
        $workers = [];
        $spec    = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        for ($i = 0; $i < self::PARALLEL_WORKERS; $i++) {
            $pipes = [];
            $proc  = @proc_open(['xargs', '-0', 'rm', '-f'], $spec, $pipes);
            if (!\is_resource($proc)) {
                foreach ($workers as $w) {
                    fclose($w['stdin']);
                    proc_close($w['proc']);
                }

                return [];
            }
            $workers[] = ['proc' => $proc, 'stdin' => $pipes[0]];
        }

        return $workers;
    }
}
