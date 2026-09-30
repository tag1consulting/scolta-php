<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * Decides what of a thread the model sees on each turn.
 *
 * The last six messages travel verbatim; everything older is folded into one
 * running summary of at most 120 words by its own request after the reply.
 * Until a fold saves, messages that left the window go verbatim too, so a
 * follow up sent before the fold lands still sees them. A 12,000 character
 * backstop then drops the oldest pairs after the first two messages, and
 * never the current turn.
 *
 * None of these numbers were tuned in the proof of concept, so they are
 * constants rather than configuration.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ContextAssembler
{
    public const WINDOW_MESSAGES = 6;
    public const SUMMARY_WORDS = 120;
    public const HISTORY_CHARS = 12000;
    public const PRIOR_SOURCES = 8;

    /**
     * Write a search hand off into an empty thread as its opening exchange:
     * the search query as the visitor's turn, the AI summary as the answer,
     * and the summary's pages as pages cited earlier.
     *
     * @param array{query: string, summary: string, pages: list<array{title: string, url: string, excerpt: string}>} $seed
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function applySeed(ThreadState $state, array $seed): bool
    {
        if ($state->messages !== []) {
            return false;
        }
        $state->messages[] = ['role' => 'user', 'content' => $seed['query']];
        $state->messages[] = ['role' => 'assistant', 'content' => $seed['summary']];
        foreach ($seed['pages'] as $page) {
            $state->cited[$page['url']] = ['title' => $page['title'], 'url' => $page['url']];
        }
        $state->seeded = true;

        return true;
    }

    /**
     * Messages that left the window and are not folded yet, oldest first.
     *
     * @return list<array{role: string, content: string}>
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function pendingFold(ThreadState $state): array
    {
        $outside = max(0, count($state->messages) - self::WINDOW_MESSAGES);
        if ($outside <= $state->folded) {
            return [];
        }
        $pending = [];
        foreach (array_slice($state->messages, $state->folded, $outside - $state->folded) as $message) {
            $pending[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        return $pending;
    }

    /**
     * The user message of the fold call.
     *
     * @param list<array{role: string, content: string}> $evicted
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function foldMessage(ThreadState $state, array $evicted): string
    {
        $lines = ['Earlier summary: ' . ($state->summary !== '' ? $state->summary : '(none)'), '', 'Messages leaving the window:'];
        foreach ($evicted as $message) {
            $lines[] = ($message['role'] === 'user' ? 'Visitor: ' : 'Assistant: ') . $message['content'];
        }

        return implode("\n", $lines);
    }

    /**
     * Store a fresh summary and mark the pending messages folded.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function applyFold(ThreadState $state, string $summary): void
    {
        $state->summary = self::limitWords($summary, self::SUMMARY_WORDS);
        $state->folded = max($state->folded, count($state->messages) - self::WINDOW_MESSAGES);
    }

    /**
     * Fold without a model call, for when the fold call fails. The visitor's
     * questions are what a later turn most needs, so they are kept.
     *
     * @param list<array{role: string, content: string}> $evicted
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function applyFallbackFold(ThreadState $state, array $evicted): void
    {
        $questions = [];
        foreach ($evicted as $message) {
            if ($message['role'] === 'user') {
                $questions[] = $message['content'];
            }
        }
        self::applyFold($state, trim($state->summary . ' Earlier the visitor asked: ' . implode(' / ', $questions)));
    }

    /**
     * The most recently cited pages, oldest first.
     *
     * @return list<array{title: string, url: string}>
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function priorSources(ThreadState $state): array
    {
        return array_values(array_slice($state->cited, -self::PRIOR_SOURCES));
    }

    /**
     * The message list for the answer call: every message not yet folded,
     * then the current turn, trimmed by the backstop.
     *
     * @return array{messages: list<array{role: string, content: string}>, dropped: int}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function assemble(ThreadState $state, string $currentContent): array
    {
        $messages = [];
        foreach (array_slice($state->messages, $state->folded) as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }
        // A conversation sent to a model must open with a user turn; after an
        // odd number of folded messages the rest can open on an answer.
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        $messages[] = ['role' => 'user', 'content' => $currentContent];

        $before = count($messages);
        $messages = self::truncate($messages, self::HISTORY_CHARS);

        return ['messages' => $messages, 'dropped' => $before - count($messages)];
    }

    /**
     * scolta-core's truncate_conversation rule with the newest message kept:
     * while the total is over $maxLength, drop the oldest $removalUnit
     * messages after the first $preserveFirstN, never touching the last one.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function truncate(array $messages, int $maxLength, int $preserveFirstN = 2, int $removalUnit = 2): array
    {
        while (true) {
            $total = 0;
            foreach ($messages as $message) {
                $total += mb_strlen($message['content']);
            }
            $removable = count($messages) - $preserveFirstN - 1;
            if ($total <= $maxLength || $removable <= 0) {
                return $messages;
            }
            array_splice($messages, $preserveFirstN, min($removalUnit, $removable));
        }
    }

    /**
     * Cut text to at most $words words.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function limitWords(string $text, int $words): string
    {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($parts, 0, $words));
    }
}
