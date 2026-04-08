<?php

declare(strict_types=1);

namespace Tag1\Scolta;

/**
 * Pre-flight check for Extism/WASM dependencies.
 *
 * Call verify() before any WASM operation to get a clear error message
 * instead of a cryptic FFI fatal. All three platform adapters call this
 * at the start of CLI commands that touch WASM.
 */
final class ExtismCheck
{
    /**
     * Verify that Extism is available for WASM operations.
     *
     * @throws \RuntimeException if FFI is disabled or Extism is not installed
     */
    public static function verify(): void
    {
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException(
                "PHP FFI extension is not loaded. Scolta requires FFI to run WebAssembly modules.\n"
                . "Enable it in php.ini: ffi.enable=true\n"
                . "See: https://www.php.net/manual/en/ffi.configuration.php"
            );
        }

        $ffiEnabled = ini_get('ffi.enable');
        if ($ffiEnabled !== '1' && $ffiEnabled !== 'true' && $ffiEnabled !== 'preload') {
            throw new \RuntimeException(
                "PHP FFI is loaded but disabled (ffi.enable={$ffiEnabled}).\n"
                . "Set ffi.enable=true in php.ini.\n"
                . "Note: ffi.enable=preload works for CLI but not web requests."
            );
        }

        if (!class_exists(\Extism\Plugin::class)) {
            throw new \RuntimeException(
                "Extism PHP SDK is not installed. Scolta requires it to run WebAssembly modules.\n"
                . "Install: composer require extism/extism\n"
                . "You also need the Extism shared library (libextism.so / libextism.dylib).\n"
                . "Install: curl -s https://get.extism.org/cli | bash && sudo extism lib install --version latest"
            );
        }
    }

    /**
     * Check if Extism is available without throwing.
     *
     * @return array{available: bool, message: string}
     */
    public static function status(): array
    {
        try {
            self::verify();
            return ['available' => true, 'message' => 'Extism available'];
        } catch (\RuntimeException $e) {
            return ['available' => false, 'message' => $e->getMessage()];
        }
    }
}
