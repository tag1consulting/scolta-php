<?php

declare(strict_types=1);

namespace Tag1\Scolta\Http;

/**
 * Formats one server-sent event for the chat stream.
 *
 * The data is JSON encoded, so a newline in model text can never start a
 * second field or event.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ServerSentEvent
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException For an event name outside [a-z_].
     * @throws \JsonException When the data cannot be encoded.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function format(string $event, array $data): string
    {
        if (preg_match('/^[a-z_]+$/D', $event) !== 1) {
            throw new \InvalidArgumentException('Invalid event name');
        }

        return 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
