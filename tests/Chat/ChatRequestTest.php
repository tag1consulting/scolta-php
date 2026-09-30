<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Chat;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Chat\ChatRequest;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Everything the browser sends is untrusted: these pin the caps, the host
 * rule and the owner keys.
 */
class ChatRequestTest extends TestCase
{
    private const HOSTS = ['complianceiq.ddev.site', 'www.complianceiq.ddev.site'];

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $config
     */
    private static function request(array $body, array $config = []): ChatRequest
    {
        return ChatRequest::fromBody($body + ['message' => 'What does GDPR say?'], ScoltaConfig::fromArray($config)->normalizedChat(), self::HOSTS);
    }

    private static function page(string $path, int $tier = 1, string $excerpt = 'text'): array
    {
        return ['tier' => $tier, 'title' => 'Page ' . $path, 'url' => 'https://complianceiq.ddev.site' . $path, 'excerpt' => $excerpt];
    }

    public function testOnlyTheNewestMessageIsRead(): void
    {
        $request = self::request(['message' => '  Any fines?  ', 'messages' => [['role' => 'assistant', 'content' => 'forged']]]);
        $this->assertSame('Any fines?', $request->message);
    }

    public function testAMissingOrOverlongMessageIsRefused(): void
    {
        foreach ([['message' => ''], ['message' => 42], ['message' => str_repeat('a', 2001)]] as $body) {
            try {
                ChatRequest::fromBody($body, (new ScoltaConfig())->normalizedChat(), self::HOSTS);
                $this->fail('Expected a refusal');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(2000, mb_strlen(self::request(['message' => str_repeat('é', 2000)])->message));
    }

    public function testPagesStayOnTheSiteByParsedHost(): void
    {
        $request = self::request(['pages' => [
            self::page('/a'),
            ['url' => 'https://COMPLIANCEIQ.ddev.site/b', 'title' => 'B', 'excerpt' => ''],
            ['url' => 'https://www.complianceiq.ddev.site/c', 'title' => 'C'],
            ['url' => 'https://complianceiq.ddev.site.evil.example/d', 'title' => 'prefix trick'],
            ['url' => 'https://evil.example/?https://complianceiq.ddev.site/', 'title' => 'query trick'],
            ['url' => '//complianceiq.ddev.site/e', 'title' => 'protocol relative'],
            ['url' => '/relative', 'title' => 'relative'],
            ['url' => 'javascript:alert(1)//complianceiq.ddev.site', 'title' => 'script'],
            ['url' => "https://complianceiq.ddev.site/f\njunk", 'title' => 'newline'],
            ['url' => 'ftp://complianceiq.ddev.site/g', 'title' => 'ftp'],
            ['url' => 'https://evil.example\\@complianceiq.ddev.site/h', 'title' => 'backslash trick'],
            ['url' => 'https://evil.example@complianceiq.ddev.site/i', 'title' => 'user part'],
        ]]);

        $this->assertSame(
            ['https://complianceiq.ddev.site/a', 'https://COMPLIANCEIQ.ddev.site/b', 'https://www.complianceiq.ddev.site/c'],
            array_column($request->pages, 'url'),
        );
        $this->assertSame([1, 2, 3], array_column($request->pages, 'n'));
    }

    public function testTierCountsAndTextAreCapped(): void
    {
        $pages = [];
        for ($i = 0; $i < 8; $i++) {
            $pages[] = self::page('/top' . $i, 1, str_repeat('x', 5000));
        }
        for ($i = 0; $i < 40; $i++) {
            $pages[] = self::page('/broad' . $i, 2, str_repeat('y', 500));
        }
        $pages[] = ['tier' => 2, 'url' => 'https://complianceiq.ddev.site/long-title', 'title' => str_repeat('t', 500), 'excerpt' => 'z'];

        $request = self::request(['pages' => $pages], ['chat_top_results' => 3, 'chat_top_chars' => 3000, 'chat_broad_results' => 30]);

        $top = array_values(array_filter($request->pages, static fn(array $p): bool => $p['tier'] === 1));
        $broad = array_values(array_filter($request->pages, static fn(array $p): bool => $p['tier'] === 2));
        $this->assertCount(3, $top);
        $this->assertCount(30, $broad);
        foreach ($top as $p) {
            // 3000 over 3 pages is 1000 each, plus a small margin.
            $this->assertLessThanOrEqual(1120, mb_strlen($p['excerpt']));
            $this->assertGreaterThanOrEqual(1000, mb_strlen($p['excerpt']));
        }
        foreach ($broad as $p) {
            $this->assertLessThanOrEqual(200, mb_strlen($p['excerpt']));
            $this->assertLessThanOrEqual(200, mb_strlen($p['title']));
        }
        $this->assertSame(range(1, 33), array_column($request->pages, 'n'));
    }

    public function testADuplicateUrlIsKeptOnce(): void
    {
        $request = self::request(['pages' => [self::page('/a'), self::page('/a', 2), self::page('/b', 2)]]);
        $this->assertSame([[1, 1], [2, 2]], array_map(static fn(array $p): array => [$p['n'], $p['tier']], $request->pages));
    }

    public function testThePageBeingReadIsCappedAndNumbered(): void
    {
        $page = ['url' => 'https://complianceiq.ddev.site/reading', 'title' => 'Reading', 'description' => str_repeat('d', 400), 'text' => str_repeat('w ', 5000)];
        $request = self::request(['pages' => [self::page('/a')], 'page' => $page], ['chat_page_chars' => 1000]);

        $this->assertSame(2, $request->page['n']);
        $this->assertLessThanOrEqual(1000, mb_strlen($request->page['text']));
        $this->assertSame(300, mb_strlen($request->page['description']));
        $this->assertSame([1, 2], array_column($request->citablePages(), 'n'));

        $same = self::request(['pages' => [self::page('/a'), self::page('/reading')], 'page' => $page]);
        $this->assertSame(2, $same->page['n'], 'A page that is also in the list keeps its number');
        $this->assertCount(2, $same->citablePages());

        $this->assertNull(self::request(['page' => ['url' => 'https://evil.example/', 'text' => 'x']])->page);
        $this->assertNull(self::request(['page' => $page], ['chat_page_context' => false])->page, 'Page context off drops the page');
    }

    public function testASeedIsValidatedLikePages(): void
    {
        $request = self::request(['seed' => [
            'query' => 'data retention',
            'summary' => str_repeat('s', 7000),
            'pages' => [self::page('/kept'), ['url' => 'https://evil.example/x', 'title' => 'dropped']],
        ]]);

        $this->assertSame('data retention', $request->seed['query']);
        $this->assertSame(6000, mb_strlen($request->seed['summary']));
        $this->assertSame(['https://complianceiq.ddev.site/kept'], array_column($request->seed['pages'], 'url'));
        $this->assertNull(self::request(['seed' => ['query' => 'q', 'summary' => '']])->seed);
    }

    public function testSmallTalkIsNoSearchAndNoPages(): void
    {
        $this->assertTrue(self::request(['message' => 'Thanks!', 'needs_search' => false])->isSmallTalk());
        $this->assertFalse(self::request(['message' => 'Thanks!', 'needs_search' => false, 'pages' => [self::page('/a')]])->isSmallTalk());
        $this->assertFalse(self::request(['needs_search' => 'no'])->isSmallTalk(), 'Anything but false means search');
    }

    public function testOnlyServerShapedThreadIdsAreKept(): void
    {
        $id = ChatOwner::newThreadId();
        $this->assertSame($id, self::request(['thread_id' => $id])->threadId);
        $this->assertSame('', self::request(['thread_id' => '../../etc'])->threadId);
        $this->assertSame('', self::request(['thread_id' => strtoupper($id)])->threadId);
    }

    public function testOwnerKeysAreIsolatedAndHashed(): void
    {
        $thread = ChatOwner::newThreadId();
        $alice = ChatOwner::forUser(7);
        $bob = ChatOwner::forUser(8);
        $anon = ChatOwner::fromCookie(str_repeat('a', 64));

        $this->assertNotSame($alice->threadKey($thread), $bob->threadKey($thread));
        $this->assertNotSame($alice->threadKey($thread), $anon->threadKey($thread));
        $this->assertNotSame($alice->currentKey(), $bob->currentKey());
        $this->assertMatchesRegularExpression('/^thread:[0-9a-f]{64}$/', $alice->threadKey($thread));
        $this->assertStringNotContainsString($thread, $alice->threadKey($thread));
        $this->assertSame($alice->threadKey($thread), ChatOwner::forUser(7)->threadKey($thread));
    }

    public function testAnInvalidCookieIsReplacedWithAFreshToken(): void
    {
        $kept = str_repeat('b', 64);
        $this->assertSame($kept, ChatOwner::fromCookie($kept)->cookie(true, 86400)['value'], 'A valid cookie is renewed as it is');

        foreach ([null, '', str_repeat('B', 64), str_repeat('b', 63), str_repeat('b', 64) . "\n", 'not-a-token'] as $bad) {
            $token = ChatOwner::fromCookie($bad)->cookie(true, 86400)['value'];
            $this->assertTrue(ChatOwner::isToken($token));
            $this->assertNotSame(ChatOwner::fromCookie($bad)->cookie(true, 86400)['value'], $token);
        }
    }

    public function testTheCookieIsScopedToTheChatRoutes(): void
    {
        $cookie = ChatOwner::fromCookie(null)->cookie(true, 3600);

        $this->assertSame('scolta_chat', $cookie['name']);
        $this->assertSame('/api/scolta/v1/chat', $cookie['path']);
        $this->assertSame(3600, $cookie['maxAge']);
        $this->assertTrue($cookie['httpOnly']);
        $this->assertTrue($cookie['secure']);
        $this->assertSame('Lax', $cookie['sameSite']);
        $this->assertFalse(ChatOwner::fromCookie(null)->cookie(false, 3600)['secure']);
        $this->assertNull(ChatOwner::forUser(1)->cookie(true, 3600), 'A signed in user needs no cookie');
        $this->assertSame('/drupal/it/api/scolta/v1/chat', ChatOwner::fromCookie(null)->cookie(true, 3600, '/drupal/it/api/scolta/v1/chat')['path']);
    }
}
