<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use Psr\Log\LoggerInterface;
use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Resolves which indexer backend to use and emits notice-level log messages.
 *
 * Encapsulates indexer selection logic so every adapter emits the same
 * structured messages. Adapters call resolve() and act on the returned
 * string ('php' or 'binary'). All logging is handled here.
 *
 * Design rule: 'auto' always means PHP. Binary pipeline requires explicit
 * 'indexer: binary' configuration. If binary is unavailable when 'binary'
 * is configured, falls back to PHP with a log notice.
 *
 * Log messages:
 *   - [scolta] Using PHP indexer.
 *   - [scolta] Using binary indexer: {binary}.
 *   - [scolta] Falling back to PHP indexer: binary not available. {reason}
 */
final class IndexerResolver
{
    public function __construct(
        private readonly PagefindBinary $binary,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the effective indexer backend and emit a notice-level log message.
     *
     * @param string $effectiveIndexer 'php', 'binary', or 'auto'.
     * @return string 'php' or 'binary'.
     * @since 1.0.0
     * @stability stable
     */
    public function resolve(string $effectiveIndexer): string
    {
        if ($effectiveIndexer === 'php') {
            $this->logger->notice('[scolta] Using PHP indexer.');
            return 'php';
        }

        if ($effectiveIndexer === 'binary') {
            $path = $this->binary->resolve();
            if ($path !== null) {
                $this->logger->notice('[scolta] Using binary indexer: {binary}.', ['binary' => $path]);
                return 'binary';
            }
            $status = $this->binary->status();
            $this->logger->notice(
                '[scolta] Falling back to PHP indexer: binary not available. {reason}',
                ['reason' => $status['message']],
            );
            return 'php';
        }

        // 'auto' (or any unrecognised value): always PHP.
        // Binary pipeline requires explicit 'indexer: binary' configuration.
        $this->logger->notice('[scolta] Using PHP indexer.');
        return 'php';
    }
}
