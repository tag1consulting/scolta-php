<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Index\LocalIndexLocator;
use Tag1\Scolta\Index\RemoteIndexLocator;

/**
 * The two ways a site finds the index it searches.
 */
class IndexLocatorTest extends TestCase
{
    private string $tempDir;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scolta_locator_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['/pagefind/pagefind.js', '/pagefind.js'] as $file) {
            @unlink($this->tempDir . $file);
        }
        @rmdir($this->tempDir . '/pagefind');
        @rmdir($this->tempDir);
    }

    // -------------------------------------------------------------------
    // Local
    // -------------------------------------------------------------------

    public function testLocalFindsAnIndexInThePagefindSubdirectory(): void
    {
        mkdir($this->tempDir . '/pagefind');
        file_put_contents($this->tempDir . '/pagefind/pagefind.js', '');

        $this->assertTrue((new LocalIndexLocator($this->tempDir))->exists());
    }

    public function testLocalFindsAPre200IndexAtTheRoot(): void
    {
        file_put_contents($this->tempDir . '/pagefind.js', '');

        $this->assertTrue((new LocalIndexLocator($this->tempDir))->exists());
    }

    public function testLocalReportsAnEmptyDirectoryAsNoIndex(): void
    {
        $locator = new LocalIndexLocator($this->tempDir);

        $this->assertFalse($locator->exists());
        $this->assertFalse($locator->isRemote());
        $this->assertSame($this->tempDir, $locator->origin());
    }

    // -------------------------------------------------------------------
    // Remote
    // -------------------------------------------------------------------

    public function testRemoteFetchesTheScriptThenTheEntryFile(): void
    {
        $locator = new RemoteIndexLocator('https://search.example.com/', $this->client(...$this->servedIndex()), new ArrayCache());

        $this->assertTrue($locator->exists());
        $this->assertTrue($locator->isRemote());
        $this->assertSame('https://search.example.com', $locator->origin());
        $this->assertSame(
            ['https://search.example.com/pagefind/pagefind.js', 'https://search.example.com/pagefind/pagefind-entry.json'],
            array_map(fn(array $entry) => (string) $entry['request']->getUri(), $this->requests),
        );
    }

    public function testRemoteReportsAMissingScriptAsNoIndexWithoutAskingForTheEntryFile(): void
    {
        $locator = new RemoteIndexLocator('https://search.example.com', $this->client(new Response(404)), new ArrayCache());

        $this->assertFalse($locator->exists());
        $this->assertCount(1, $this->requests);
    }

    public function testRemoteReportsAScriptServedAsHtmlAsNoIndex(): void
    {
        // An error page, or a login page a redirect landed on, answering 200.
        $client = $this->client(new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html>Sign in</html>'));

        $this->assertFalse((new RemoteIndexLocator('https://search.example.com', $client, new ArrayCache()))->exists());
    }

    public function testRemoteReportsAMissingEntryFileAsNoIndex(): void
    {
        $client = $this->client($this->script(), new Response(404));

        $this->assertFalse((new RemoteIndexLocator('https://search.example.com', $client, new ArrayCache()))->exists());
    }

    #[DataProvider('notAnEntryFile')]
    public function testRemoteReportsAnEntryFileThatIsNotOneAsNoIndex(string $body): void
    {
        $client = $this->client($this->script(), new Response(200, ['Content-Type' => 'application/json'], $body));

        $this->assertFalse((new RemoteIndexLocator('https://search.example.com', $client, new ArrayCache()))->exists());
    }

    /**
     * Bodies a host can answer 200 with that are not an index's entry file.
     *
     * @return array<string, array{string}>
     */
    public static function notAnEntryFile(): array
    {
        return [
            'an HTML error page' => ['<html><body>Not found</body></html>'],
            'JSON without languages' => ['{"version":"1.4.0"}'],
            'JSON without version' => ['{"languages":{"en":{}}}'],
            'a JSON list' => ['[]'],
        ];
    }

    public function testRemoteReportsAnUnreachableHostAsNoIndex(): void
    {
        $client = $this->client(new ConnectException('refused', new Request('GET', 'https://search.example.com')));

        $this->assertFalse((new RemoteIndexLocator('https://search.example.com', $client, new ArrayCache()))->exists());
    }

    public function testRemoteReusesACachedAnswerWithinTheTtl(): void
    {
        $cache = new ArrayCache();
        $client = $this->client(...$this->servedIndex());

        $first = new RemoteIndexLocator('https://search.example.com', $client, $cache);
        $second = new RemoteIndexLocator('https://search.example.com', $client, $cache);

        $this->assertTrue($first->exists());
        $this->assertTrue($second->exists());
        $this->assertCount(2, $this->requests, 'A cached answer must not make further requests');
        $this->assertSame([RemoteIndexLocator::DEFAULT_TTL], array_values($cache->ttls));
    }

    public function testRemoteCachesAFailureToo(): void
    {
        // The slow case: an unreachable host is the one that runs to the
        // timeout, so it is the one a cache most needs to cover.
        $cache = new ArrayCache();
        $client = $this->client(new ConnectException('refused', new Request('GET', 'https://search.example.com')));
        $locator = new RemoteIndexLocator('https://search.example.com', $client, $cache);

        $this->assertFalse($locator->exists());
        $this->assertFalse($locator->exists());
        $this->assertCount(1, $this->requests);
    }

    public function testRemoteCachesEachOriginSeparately(): void
    {
        $cache = new ArrayCache();
        $client = $this->client(...[...$this->servedIndex(), new Response(404)]);

        $this->assertTrue((new RemoteIndexLocator('https://one.example.com', $client, $cache))->exists());
        $this->assertFalse((new RemoteIndexLocator('https://two.example.com', $client, $cache))->exists());
    }

    /**
     * The two answers a host serving an index gives.
     *
     * @return list<Response>
     */
    private function servedIndex(): array
    {
        return [
            $this->script(),
            new Response(200, ['Content-Type' => 'application/json'], '{"version":"1.4.0","languages":{"en":{"hash":"en_1","wasm":"en","page_count":3}}}'),
        ];
    }

    private function script(): Response
    {
        return new Response(200, ['Content-Type' => 'text/javascript'], 'export const init = () => {};');
    }

    private function client(Response|\Throwable ...$answers): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->requests));

        return new Client(['handler' => $stack]);
    }
}

/**
 * A cache that keeps values for the test and records the TTL each was set with.
 */
class ArrayCache implements CacheDriverInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttlSeconds;
    }
}
