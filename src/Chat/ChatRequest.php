<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * One chat request body, validated as untrusted input.
 *
 * The browser runs the retrieval (Pagefind and WASM live only there) and
 * posts the pages with the message, so every field is bounded here before it
 * reaches a prompt: only the newest message is read, never a client copy of
 * the conversation; page counts and text are capped by the site's chat
 * limits; every URL must be absolute http(s) on one of the site's own hosts,
 * compared by parsed host. Pages are renumbered 1..N in the order sent, and
 * the page the visitor is reading takes the number of the same URL in that
 * list, or the next one.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ChatRequest
{
    public const MAX_MESSAGE = 2000;

    private const MAX_TITLE = 200;
    private const MAX_DESCRIPTION = 300;
    private const MAX_LINE = 200;
    private const MAX_SEED_QUERY = 300;
    private const MAX_SEED_SUMMARY = 6000;
    private const MAX_URL = 2048;

    /**
     * @param list<array{n: int, tier: int, title: string, url: string, excerpt: string}> $pages
     * @param array{n: int, title: string, url: string, description: string, text: string}|null $page
     * @param array{query: string, summary: string, pages: list<array{title: string, url: string, excerpt: string}>}|null $seed
     */
    private function __construct(
        public readonly string $threadId,
        public readonly string $message,
        public readonly bool $needsSearch,
        public readonly array $pages,
        public readonly ?array $page,
        public readonly ?array $seed,
    ) {}

    /**
     * Validate a decoded request body.
     *
     * @param array<array-key, mixed> $body
     * @param array{topResults: int, topChars: int, broadResults: int, pageContext: bool, pageChars: int} $limits
     *   From ScoltaConfig::normalizedChat().
     * @param list<string> $allowedHosts The site's hosts.
     *
     * @throws \InvalidArgumentException When there is no usable message.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function fromBody(array $body, array $limits, array $allowedHosts): self
    {
        $message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
        if ($message === '') {
            throw new \InvalidArgumentException('Message required');
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            throw new \InvalidArgumentException('Message too long');
        }
        $hosts = array_map('strtolower', $allowedHosts);

        $pages = self::pages($body['pages'] ?? null, $limits, $hosts);
        $page = $limits['pageContext'] ? self::currentPage($body['page'] ?? null, $pages, $limits['pageChars'], $hosts) : null;

        $seed = null;
        if (is_array($body['seed'] ?? null)) {
            $query = self::line($body['seed']['query'] ?? '', self::MAX_SEED_QUERY);
            $summary = self::text($body['seed']['summary'] ?? '', self::MAX_SEED_SUMMARY);
            if ($query !== '' && $summary !== '') {
                $seed = ['query' => $query, 'summary' => $summary, 'pages' => self::seedPages($body['seed']['pages'] ?? null, $limits, $hosts)];
            }
        }

        return new self(
            ChatOwner::isThreadId($body['thread_id'] ?? null) ? $body['thread_id'] : '',
            $message,
            ($body['needs_search'] ?? true) !== false,
            $pages,
            $page,
            $seed,
        );
    }

    /**
     * Whether a URL is absolute http(s) on one of the given hosts.
     *
     * @param list<string> $allowedHosts Lowercase hosts.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function isAllowedUrl(string $url, array $allowedHosts): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\s<>"\x00-\x1f\x7f]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        // A browser reads a backslash as a slash and a user part as not the
        // host, so either one can point a link somewhere parse_url() did not.
        if (str_contains($url, '\\') || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), $allowedHosts, true);
    }

    /**
     * Whether nothing was looked up for this turn: small talk.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function isSmallTalk(): bool
    {
        return !$this->needsSearch && $this->pages === [];
    }

    /**
     * Every page this turn can cite, the page being read included.
     *
     * @return list<array{n: int, title: string, url: string}>
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function citablePages(): array
    {
        $out = [];
        foreach ($this->pages as $p) {
            $out[] = ['n' => $p['n'], 'title' => $p['title'], 'url' => $p['url']];
        }
        if ($this->page !== null && $this->page['n'] > count($this->pages)) {
            $out[] = ['n' => $this->page['n'], 'title' => $this->page['title'], 'url' => $this->page['url']];
        }

        return $out;
    }

    /**
     * @param array{topResults: int, topChars: int, broadResults: int} $limits
     * @param list<string> $hosts
     * @return list<array{n: int, tier: int, title: string, url: string, excerpt: string}>
     */
    private static function pages(mixed $raw, array $limits, array $hosts): array
    {
        $top = [];
        $broad = [];
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
            if (!self::isAllowedUrl($url, $hosts) || isset($seen[$url])) {
                continue;
            }
            $tier = ($item['tier'] ?? 1) === 2 ? 2 : 1;
            if ($tier === 1 && count($top) < $limits['topResults']) {
                $top[] = ['title' => $item['title'] ?? '', 'url' => $url, 'excerpt' => $item['excerpt'] ?? ''];
            } elseif ($tier === 2 && count($broad) < $limits['broadResults']) {
                $broad[] = ['title' => $item['title'] ?? '', 'url' => $url, 'excerpt' => $item['excerpt'] ?? ''];
            } else {
                continue;
            }
            $seen[$url] = true;
        }

        // The browser divides topChars evenly across what it sent; allow a
        // little over that share for whitespace it normalizes differently.
        $share = max(100, intdiv($limits['topChars'], max(1, count($top))));
        $cap = $share + intdiv($share, 10) + 20;
        $out = [];
        foreach ($top as $item) {
            $out[] = [
                'n' => count($out) + 1,
                'tier' => 1,
                'title' => self::title($item['title'], $item['url']),
                'url' => $item['url'],
                'excerpt' => self::text($item['excerpt'], $cap),
            ];
        }
        foreach ($broad as $item) {
            $out[] = [
                'n' => count($out) + 1,
                'tier' => 2,
                'title' => self::title($item['title'], $item['url']),
                'url' => $item['url'],
                'excerpt' => self::line($item['excerpt'], self::MAX_LINE),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{n: int, tier: int, title: string, url: string, excerpt: string}> $pages
     * @param list<string> $hosts
     * @return array{n: int, title: string, url: string, description: string, text: string}|null
     */
    private static function currentPage(mixed $raw, array $pages, int $maxChars, array $hosts): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $url = is_string($raw['url'] ?? null) ? trim($raw['url']) : '';
        if (!self::isAllowedUrl($url, $hosts)) {
            return null;
        }
        $n = count($pages) + 1;
        foreach ($pages as $p) {
            if ($p['url'] === $url) {
                $n = $p['n'];
                break;
            }
        }

        return [
            'n' => $n,
            'title' => self::title($raw['title'] ?? '', $url),
            'url' => $url,
            'description' => self::line($raw['description'] ?? '', self::MAX_DESCRIPTION),
            'text' => self::text($raw['text'] ?? '', $maxChars),
        ];
    }

    /**
     * @param array{topResults: int, broadResults: int} $limits
     * @param list<string> $hosts
     * @return list<array{title: string, url: string, excerpt: string}>
     */
    private static function seedPages(mixed $raw, array $limits, array $hosts): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (!is_array($item) || count($out) >= $limits['topResults'] + $limits['broadResults']) {
                continue;
            }
            $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
            if (!self::isAllowedUrl($url, $hosts)) {
                continue;
            }
            $out[] = [
                'title' => self::title($item['title'] ?? '', $url),
                'url' => $url,
                'excerpt' => self::line($item['excerpt'] ?? '', self::MAX_LINE),
            ];
        }

        return $out;
    }

    private static function title(mixed $value, string $url): string
    {
        $title = self::line($value, self::MAX_TITLE);

        return $title !== '' ? $title : $url;
    }

    /** One line: whitespace collapsed, capped. */
    private static function line(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max));
    }

    /** Running text: control characters dropped, newlines kept, capped. */
    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/[^\P{Cc}\n\t]/u', '', $value) ?? '';

        return trim(mb_substr(trim($value), 0, $max));
    }
}
