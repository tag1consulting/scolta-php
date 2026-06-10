<?php

declare(strict_types=1);

namespace Tag1\Scolta\Exception;

/**
 * Thrown when an authenticated-encryption operation fails.
 *
 * Raised on any decrypt failure — malformed envelope, unsupported
 * version, truncated payload, MAC mismatch, or cipher failure — and on
 * encrypt failure. Decrypt failures are never silent: callers decide
 * how to surface them. (The pattern this replaces returned null on
 * decrypt failure, which hid credential corruption from site owners.)
 *
 * @since 1.0.4
 * @stability experimental
 */
final class CryptoException extends \RuntimeException {}
