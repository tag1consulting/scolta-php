<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

/**
 * PSR-3 logger spy that records error() calls.
 */
class SpyLogger extends \Psr\Log\AbstractLogger
{
    /** @var array<string> */
    public array $errors = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === \Psr\Log\LogLevel::ERROR) {
            $this->errors[] = (string) $message;
        }
    }
}
