<?php

declare(strict_types=1);

namespace Tag1\Scolta\Chat;

/**
 * Who a chat thread belongs to, and every storage key derived from that.
 *
 * A signed in visitor is their user id; an anonymous one is a random token
 * held in a cookie scoped to the chat routes, so no session is ever started.
 * Keys are a SHA-256 over the owner and the thread id, so a thread id copied
 * from someone else reads nothing. Adapters build owners here and never build
 * keys themselves.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ChatOwner
{
    /**
     * The anonymous owner cookie and its attributes. Path is the chat route
     * prefix, so the cookie never rides along on page requests; Secure is set
     * when the request is https; Max-Age is the thread lifetime.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public const COOKIE_NAME = 'scolta_chat';
    public const COOKIE_PATH = '/api/scolta/v1/chat';
    public const COOKIE_SAME_SITE = 'Lax';
    public const COOKIE_HTTP_ONLY = true;

    private function __construct(
        private readonly string $owner,
        private readonly ?string $newToken,
    ) {}

    /**
     * The owner for a signed in user.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function forUser(int|string $userId): self
    {
        return new self('u:' . $userId, null);
    }

    /**
     * The owner for an anonymous visitor, from the cookie value if any.
     *
     * A missing or malformed token is replaced by a fresh one, which
     * newToken() then returns so the adapter can set the cookie.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function fromCookie(?string $token): self
    {
        if ($token !== null && self::isToken($token)) {
            return new self('a:' . $token, null);
        }
        $fresh = bin2hex(random_bytes(32));

        return new self('a:' . $fresh, $fresh);
    }

    /**
     * Whether a value has the shape of a token this class generates.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function isToken(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $token) === 1;
    }

    /**
     * The token the adapter must set as the cookie, when one was generated.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function newToken(): ?string
    {
        return $this->newToken;
    }

    /**
     * The cookie to set on a chat response, or null when none is needed.
     *
     * @return array{name: string, value: string, path: string, maxAge: int, secure: bool, httpOnly: bool, sameSite: string}|null
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function cookie(bool $https, int $maxAge): ?array
    {
        if ($this->newToken === null) {
            return null;
        }

        return [
            'name' => self::COOKIE_NAME,
            'value' => $this->newToken,
            'path' => self::COOKIE_PATH,
            'maxAge' => $maxAge,
            'secure' => $https,
            'httpOnly' => self::COOKIE_HTTP_ONLY,
            'sameSite' => self::COOKIE_SAME_SITE,
        ];
    }

    /**
     * The storage key of one of this owner's threads.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function threadKey(string $threadId): string
    {
        return 'thread:' . hash('sha256', $this->owner . "\n" . $threadId);
    }

    /**
     * The storage key of this owner's current thread pointer.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function currentKey(): string
    {
        return 'current:' . hash('sha256', $this->owner);
    }

    /**
     * A new thread id. Thread ids are only ever made on the server.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function newThreadId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Whether a value has the shape of a thread id.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public static function isThreadId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{32}$/D', $id) === 1;
    }
}
