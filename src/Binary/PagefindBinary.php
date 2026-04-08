<?php

declare(strict_types=1);

namespace Tag1\Scolta\Binary;

/**
 * Resolves the Pagefind binary location using a deterministic fallback chain.
 *
 * Resolution order:
 * 1. Explicitly configured path (user setting)
 * 2. Project-local download directory (.scolta/bin/pagefind)
 * 3. npx pagefind (Node.js on-demand download)
 * 4. Bare 'pagefind' on system PATH
 *
 * The first method that finds an executable binary wins. Each step is
 * tested by actually running `{binary} --version`.
 */
class PagefindBinary
{
    /** Resolved binary command, cached after first resolution. */
    private ?string $resolved = null;

    /** The method used to resolve ('configured', 'local', 'npx', 'path', 'none'). */
    private string $resolvedVia = 'none';

    /**
     * @param string|null $configuredPath Explicit path from platform config.
     * @param string|null $projectDir     Project root directory for .scolta/bin/ lookup.
     */
    public function __construct(
        private readonly ?string $configuredPath = null,
        private readonly ?string $projectDir = null,
    ) {
    }

    /**
     * Resolve the Pagefind binary command string.
     *
     * Returns the full command to execute (may be a path or "npx pagefind").
     * Returns null if no working binary can be found.
     */
    public function resolve(): ?string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // 1. Explicitly configured path (skip if it's just the bare default 'pagefind').
        if ($this->configuredPath !== null
            && $this->configuredPath !== ''
            && $this->configuredPath !== 'pagefind'
        ) {
            if ($this->isExecutable($this->configuredPath)) {
                $this->resolved = $this->configuredPath;
                $this->resolvedVia = 'configured';
                return $this->resolved;
            }
        }

        // 2. Project-local download (.scolta/bin/pagefind).
        if ($this->projectDir !== null) {
            $localBinary = rtrim($this->projectDir, '/') . '/.scolta/bin/pagefind';
            if ($this->isExecutable($localBinary)) {
                $this->resolved = $localBinary;
                $this->resolvedVia = 'local';
                return $this->resolved;
            }
        }

        // 3. npx pagefind (Node.js environments).
        if ($this->isExecutable('npx pagefind')) {
            $this->resolved = 'npx pagefind';
            $this->resolvedVia = 'npx';
            return $this->resolved;
        }

        // 4. Bare 'pagefind' on system PATH.
        if ($this->isExecutable('pagefind')) {
            $this->resolved = 'pagefind';
            $this->resolvedVia = 'path';
            return $this->resolved;
        }

        $this->resolvedVia = 'none';
        return null;
    }

    /**
     * How the binary was resolved.
     *
     * One of: 'configured', 'local', 'npx', 'path', 'none'.
     */
    public function resolvedVia(): string
    {
        if ($this->resolved === null && $this->resolvedVia === 'none') {
            $this->resolve();
        }
        return $this->resolvedVia;
    }

    /**
     * Get the version string from the resolved binary, or null.
     */
    public function version(): ?string
    {
        $binary = $this->resolve();
        if ($binary === null) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        exec($binary . ' --version 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0 && !empty($output)) {
            return trim(implode(' ', $output));
        }
        return null;
    }

    /**
     * Structured status report for CLI status commands and admin forms.
     *
     * @return array{available: bool, binary: ?string, version: ?string, via: string, message: string}
     */
    public function status(): array
    {
        $binary = $this->resolve();
        $version = $this->version();

        if ($binary !== null) {
            return [
                'available' => true,
                'binary' => $binary,
                'version' => $version,
                'via' => $this->resolvedVia,
                'message' => sprintf(
                    'Pagefind %s (resolved via %s)',
                    $version ?? 'unknown version',
                    $this->resolvedVia,
                ),
            ];
        }

        $tried = [];
        if ($this->configuredPath !== null
            && $this->configuredPath !== ''
            && $this->configuredPath !== 'pagefind'
        ) {
            $tried[] = "configured path '{$this->configuredPath}' -- not found or not executable";
        }
        if ($this->projectDir !== null) {
            $localPath = rtrim($this->projectDir, '/') . '/.scolta/bin/pagefind';
            $tried[] = "project-local {$localPath} -- not found";
        }
        $tried[] = 'npx pagefind -- npx not available or pagefind not installable';
        $tried[] = "system PATH -- 'pagefind' not found";

        return [
            'available' => false,
            'binary' => null,
            'version' => null,
            'via' => 'none',
            'message' => "Pagefind binary not found. Tried:\n  - " . implode("\n  - ", $tried)
                . "\n\nInstall: npm install -g pagefind\nOr run the download-pagefind command.",
        ];
    }

    /**
     * The recommended download target directory for download-pagefind commands.
     *
     * Returns the project-local .scolta/bin/ directory, creating it if needed.
     * Falls back to system temp if projectDir is not set.
     */
    public function downloadTargetDir(): string
    {
        if ($this->projectDir !== null) {
            $dir = rtrim($this->projectDir, '/') . '/.scolta/bin';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            return $dir;
        }
        return sys_get_temp_dir();
    }

    /**
     * Test if a binary command is executable by running {cmd} --version.
     */
    private function isExecutable(string $cmd): bool
    {
        $output = [];
        $exitCode = 0;
        exec($cmd . ' --version 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }
}
