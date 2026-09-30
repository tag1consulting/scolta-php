<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * The server's record of one chat thread, the only history the chat trusts.
 *
 * User messages hold the visitor's words only; the pages sent with a turn
 * are never stored. `revision` counts saves, so a fold can tell whether a
 * turn saved while it was summarizing.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ThreadState
{
    /**
     * Bumped when the stored shape changes; a thread stored under another
     * schema is discarded rather than misread.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public const SCHEMA = 1;

    /**
     * Every message, oldest first. An assistant message carries the pages it
     * cited.
     *
     * @var list<array{role: string, content: string, sources?: list<array{n: int, title: string, url: string}>}>
     */
    public array $messages = [];

    /** Running summary of the messages folded out of the window. */
    public string $summary = '';

    /** How many leading messages the summary covers. */
    public int $folded = 0;

    /**
     * Pages the answers cited, keyed by URL, most recently cited last.
     *
     * @var array<string, array{title: string, url: string}>
     */
    public array $cited = [];

    public int $revision = 0;

    /** Visitor turns answered (a search hand off is not a turn). */
    public int $turns = 0;

    public bool $seeded = false;

    /**
     * A state from its stored form; empty when there is none or its schema
     * is not this one.
     *
     * @param array<string, mixed>|null $data
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function fromArray(?array $data): self
    {
        $state = new self();
        if ($data === null || ($data['schema'] ?? null) !== self::SCHEMA) {
            return $state;
        }
        $state->messages = is_array($data['messages'] ?? null) ? array_values($data['messages']) : [];
        $state->summary = (string) ($data['summary'] ?? '');
        $state->folded = (int) ($data['folded'] ?? 0);
        $state->cited = is_array($data['cited'] ?? null) ? $data['cited'] : [];
        $state->revision = (int) ($data['revision'] ?? 0);
        $state->turns = (int) ($data['turns'] ?? 0);
        $state->seeded = (bool) ($data['seeded'] ?? false);

        return $state;
    }

    /**
     * The stored form.
     *
     * @return array<string, mixed>
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'messages' => $this->messages,
            'summary' => $this->summary,
            'folded' => $this->folded,
            'cited' => $this->cited,
            'revision' => $this->revision,
            'turns' => $this->turns,
            'seeded' => $this->seeded,
        ];
    }

    /**
     * Append one answered turn and remember the pages it cited.
     *
     * @param list<array{n: int, title: string, url: string}> $sources
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function addExchange(string $question, string $answer, array $sources): void
    {
        $this->messages[] = ['role' => 'user', 'content' => $question];
        $this->messages[] = ['role' => 'assistant', 'content' => $answer, 'sources' => $sources];
        $this->turns++;
        foreach ($sources as $source) {
            // Re-inserting moves a page cited again to the recent end.
            unset($this->cited[$source['url']]);
            $this->cited[$source['url']] = ['title' => $source['title'], 'url' => $source['url']];
        }
    }
}
