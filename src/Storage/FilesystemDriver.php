<?php

declare(strict_types=1);

namespace Tag1\Scolta\Storage;

/**
 * Local filesystem storage driver. Default for WordPress and Drupal.
 */
class FilesystemDriver implements StorageDriverInterface
{
    /**
     * Validate that a path does not use PHP stream wrappers.
     *
     * Defense-in-depth: platform adapters sanitize paths upstream,
     * but this guard prevents accidental misuse of the storage driver.
     *
     * @throws \InvalidArgumentException If the path contains a stream wrapper.
     */
    private function validatePath(string $path): void
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $path)) {
            throw new \InvalidArgumentException('Stream wrappers are not allowed in file paths.');
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function get(string $path): string
    {
        $this->validatePath($path);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read: {$path}");
        }

        return $contents;
    }

    public function put(string $path, string $contents): bool
    {
        $this->validatePath($path);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($path, $contents) !== false;
    }

    public function delete(string $path): bool
    {
        $this->validatePath($path);
        if (!file_exists($path)) {
            return true;
        }

        return unlink($path);
    }

    public function deleteDirectory(string $path): bool
    {
        $this->validatePath($path);
        if (!is_dir($path)) {
            return true;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        return rmdir($path);
    }

    public function makeDirectory(string $path): bool
    {
        $this->validatePath($path);
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, 0755, true);
    }

    public function move(string $from, string $to): bool
    {
        $this->validatePath($from);
        $this->validatePath($to);

        return rename($from, $to);
    }

    public function files(string $directory, string $pattern = '*'): array
    {
        $this->validatePath($directory);
        $result = glob($directory . '/' . $pattern);

        return $result !== false ? $result : [];
    }
}
