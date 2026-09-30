<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * Builds the user turn of a chat answer call.
 *
 * The system prompt is the resolved `chat` template and nothing else, the
 * same for every turn on a site, so a provider can cache it. Everything that
 * changes per turn goes here, under the headings the template describes, in
 * this order and each only when present: the summary of earlier turns, the
 * pages cited earlier, the page the visitor is reading, the pages for this
 * turn, then the visitor's message. Page text sits inside <page> tags so the
 * model can tell site content from instructions.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ChatPrompt
{
    /**
     * @param list<array{title: string, url: string}> $priorSources
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function userTurn(ChatRequest $request, string $summary, array $priorSources): string
    {
        $parts = [];
        if ($summary !== '') {
            $parts[] = "Summary of the conversation so far:\n" . $summary;
        }
        if ($priorSources !== []) {
            $lines = [];
            foreach ($priorSources as $source) {
                $lines[] = '- ' . self::attr($source['title']) . ': ' . $source['url'];
            }
            $parts[] = "Pages cited earlier:\n" . implode("\n", $lines);
        }
        // A small talk turn looked nothing up, and says so by carrying no page.
        if (!$request->isSmallTalk()) {
            $page = $request->page;
            if ($page !== null) {
                $body = trim(($page['description'] !== '' ? $page['description'] . "\n\n" : '') . $page['text']);
                $parts[] = "The visitor is reading this page:\n" . self::page($page['n'], $page['title'], $page['url'], $body);
            }
            $top = [];
            $broad = [];
            foreach ($request->pages as $p) {
                if ($p['tier'] === 1) {
                    $top[] = self::page($p['n'], $p['title'], $p['url'], $p['excerpt']);
                } else {
                    $broad[] = self::page($p['n'], $p['title'], $p['url'], $p['excerpt']);
                }
            }
            if ($top !== []) {
                $parts[] = "Pages for this turn:\n" . implode("\n", $top);
            }
            if ($broad !== []) {
                $parts[] = "More pages for this turn:\n" . implode("\n", $broad);
            }
        }
        $parts[] = "The visitor's message:\n" . $request->message;

        return implode("\n\n", $parts);
    }

    private static function page(int $n, string $title, string $url, string $text): string
    {
        // A page cannot close its own tag early and have what follows read as
        // instructions.
        $text = preg_replace('#</?\s*page\b[^>]*>?#i', '', $text) ?? '';

        return '<page n="' . $n . '" title="' . self::attr($title) . '" url="' . self::attr($url) . "\">\n" . trim($text) . "\n</page>";
    }

    private static function attr(string $value): string
    {
        return str_replace(['"', '<', '>'], ["'", '', ''], $value);
    }
}
