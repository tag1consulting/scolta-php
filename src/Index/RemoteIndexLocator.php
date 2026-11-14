<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Finds an index another host serves, by fetching the files a browser loads.
 *
 * The browser imports pagefind.js first, and that loads pagefind-entry.json,
 * so both are fetched, in that order. A host can answer 200 with an error
 * page, or redirect to a login page that answers 200, so neither status is
 * trusted alone: pagefind.js must not come back as HTML, and the entry file
 * must decode to the `version` and `languages` a build verifies it wrote
 * before it publishes. This cannot see what only a browser enforces, such as
 * CORS headers or the content type the .wasm file is served with.
 *
 * Health endpoints are usually reachable anonymously so uptime monitors work,
 * and an unreachable host holds the request open for the full timeout, so the
 * cache is required: without it every anonymous request would make another
 * to the remote host. The answer is reused for a short while per origin,
 * failures included, since a failure is the slow case.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class RemoteIndexLocator implements IndexLocatorInterface
{
    /**
     * Default seconds an answer is reused, well inside a monitor's window.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public const DEFAULT_TTL = 60;

    private readonly string $baseUrl;

    /**
     * @param string $baseUrl The URL the remote pagefind/ directory sits
     *   under, e.g. https://search.example.com. A trailing slash is ignored.
     * @param ClientInterface $httpClient The client that fetches the entry
     *   file.
     * @param CacheDriverInterface $cache Where answers are reused from.
     * @param int $ttlSeconds How long an answer is reused.
     * @param float $timeout Seconds the whole request may take.
     * @param float $connectTimeout Seconds connecting may take.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function __construct(
        string $baseUrl,
        private readonly ClientInterface $httpClient,
        private readonly CacheDriverInterface $cache,
        private readonly int $ttlSeconds = self::DEFAULT_TTL,
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 3.0,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * {@inheritdoc}
     *
     * No answer, an answer other than 200, pagefind.js served as HTML, or an
     * entry file without `version` and `languages` all count as no index.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function exists(): bool
    {
        $key = 'scolta_remote_index_reachable:' . hash('sha256', $this->baseUrl);

        // Stored as a string: a cache driver answers null for a miss, so a
        // cached FALSE must not look like one.
        $cached = $this->cache->get($key);
        if ($cached === 'yes' || $cached === 'no') {
            return $cached === 'yes';
        }

        $exists = $this->servesScript() && $this->servesEntryFile();
        $this->cache->set($key, $exists ? 'yes' : 'no', $this->ttlSeconds);

        return $exists;
    }

    /**
     * {@inheritdoc}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function isRemote(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function origin(): string
    {
        return $this->baseUrl;
    }

    /**
     * Whether pagefind.js is served, and not as an HTML page.
     */
    private function servesScript(): bool
    {
        $response = $this->get('/pagefind/pagefind.js');

        return $response !== null
            && $response->getStatusCode() === 200
            && !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html');
    }

    /**
     * Whether pagefind-entry.json is served, and is an index's entry file.
     */
    private function servesEntryFile(): bool
    {
        $response = $this->get('/pagefind/pagefind-entry.json');
        if ($response === null || $response->getStatusCode() !== 200) {
            return false;
        }

        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) && isset($data['version'], $data['languages']);
    }

    /**
     * A file under the base URL, or null when the host does not answer.
     */
    private function get(string $path): ?ResponseInterface
    {
        try {
            return $this->httpClient->request('GET', $this->baseUrl . $path, [
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
                'http_errors' => false,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
