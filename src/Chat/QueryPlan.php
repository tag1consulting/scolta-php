<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * The planning call: one model call that turns a follow up into a standalone
 * query and expands it, replying {"query", "needs_search", "terms"}.
 *
 * Its prompt is the `chat_plan` template followed by the site's resolved
 * expansion prompt, so a site that overrides expansion changes planning too.
 * The first turn of a thread needs no rewrite and goes to expand-query
 * instead, which caches by query.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class QueryPlan
{
    public const MAX_TERMS = 6;

    /**
     * @since 2.0.0
     * @stability experimental
     */
    public static function systemPrompt(string $planPrompt, string $expandPrompt): string
    {
        return rtrim($planPrompt) . "\n" . $expandPrompt;
    }

    /**
     * @param list<array{role: string, content: string}> $history
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function userMessage(string $message, array $history, string $pageTitle = ''): string
    {
        $lines = ['Earlier in the conversation:'];
        foreach ($history as $item) {
            $lines[] = ($item['role'] === 'user' ? 'Visitor: ' : 'Assistant: ') . $item['content'];
        }
        if ($pageTitle !== '') {
            $lines[] = '';
            $lines[] = 'The visitor is reading the page: ' . $pageTitle;
        }
        $lines[] = '';
        $lines[] = 'Latest visitor message: ' . $message;

        return implode("\n", $lines);
    }

    /**
     * Parse the model's reply, tolerating a code fence around the JSON.
     *
     * @return array{query: string, needs_search: bool, terms: list<string>}|null
     *   Null when the reply is unusable.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function parse(string $raw): ?array
    {
        if (!preg_match('/\{.*\}/s', $raw, $match)) {
            return null;
        }
        $decoded = json_decode($match[0], true);
        if (!is_array($decoded) || !is_string($decoded['query'] ?? null)) {
            return null;
        }
        $query = trim(preg_replace('/\s+/u', ' ', $decoded['query']) ?? '');
        $needsSearch = ($decoded['needs_search'] ?? true) !== false;
        if ($query === '' && $needsSearch) {
            return null;
        }
        $terms = [];
        foreach (is_array($decoded['terms'] ?? null) ? $decoded['terms'] : [] as $term) {
            if (!is_string($term)) {
                continue;
            }
            $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');
            if ($term !== '' && mb_strlen($term) <= 100 && strcasecmp($term, $query) !== 0 && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        return [
            'query' => mb_substr($query, 0, 200),
            'needs_search' => $needsSearch,
            'terms' => $needsSearch ? $terms : [],
        ];
    }
}
