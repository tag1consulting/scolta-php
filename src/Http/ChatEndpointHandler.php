<?php

declare(strict_types=1);

namespace Tag1\Scolta\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Chat\ChatPrompt;
use Tag1\Scolta\Chat\ChatRequest;
use Tag1\Scolta\Chat\CitedPages;
use Tag1\Scolta\Chat\ContextAssembler;
use Tag1\Scolta\Chat\QueryPlan;
use Tag1\Scolta\Chat\ThreadState;
use Tag1\Scolta\Chat\ThreadStoreInterface;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * The chat's request handling, free of any framework: the chat's
 * AiEndpointHandler.
 *
 * Every turn's pages come from the browser, which runs the retrieval; the
 * thread lives on the server behind a ThreadStoreInterface keyed by
 * ChatOwner. Handlers return the same {ok, data, status, error} shape as
 * AiEndpointHandler, and streamTurn() yields [event, data] pairs for an
 * adapter to send as server-sent events. With the chat off every handler
 * answers 404, and every handler refuses a request without the
 * X-Scolta-Chat header, which a cross site form cannot send without a CORS
 * preflight. Adapters add their platform's CSRF check on top.
 *
 * Every model call goes through the AiServiceAdapter, so the platform's AI
 * layer and the budget and key bookkeeping apply to the chat as they do to
 * search.
 *
 * @since 2.0.0
 * @stability experimental
 */
class ChatEndpointHandler
{
    use AiFailureMapping;

    /** Turns in one thread before the chat asks for a new one. */
    public const TURN_CEILING = 40;

    private const PLAN_HISTORY = 4;
    private const PLAN_MAX_TOKENS = 300;
    private const FOLD_MAX_TOKENS = 300;
    private const FOLD_ATTEMPTS = 3;

    private const NOTHING_FOUND = "I don't have a page that covers that. Could you ask it another way, or name the topic or page you have in mind?";
    private const START_NEW = 'This conversation has grown long. Start a new chat to keep going.';

    /** @var list<string> */
    private readonly array $allowedHosts;

    /** @var array{enabled: bool, topResults: int, topChars: int, broadResults: int, broadChars: int, pageContext: bool, pageChars: int, maxTokens: int, threadTtl: int, handoff: bool} */
    private readonly array $chat;

    /**
     * @param AiServiceAdapter     $aiService    The platform's AI service.
     * @param CacheDriverInterface $cache        Cache for opening answers.
     * @param int                  $generation   Cache generation; a reindex bumps it.
     * @param int                  $cacheTtl     Opening answer lifetime in seconds (0 = off).
     * @param ThreadStoreInterface $threads      Where threads live.
     * @param ScoltaConfig         $config       The site's config.
     * @param list<string>         $allowedHosts The site's own hosts; page URLs must be on one.
     * @param LoggerInterface      $logger       Events, sizes and timings only, never text.
     */
    public function __construct(
        private readonly AiServiceAdapter $aiService,
        private readonly CacheDriverInterface $cache,
        private readonly int $generation,
        private readonly int $cacheTtl,
        private readonly ThreadStoreInterface $threads,
        private readonly ScoltaConfig $config,
        array $allowedHosts,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->allowedHosts = array_values(array_unique(array_map('strtolower', $allowedHosts)));
        $this->chat = $config->normalizedChat();
    }

    /**
     * Plan a follow up: rewrite it as a standalone query and expand it.
     *
     * Body: {thread_id, message, seed?, page?}. History comes from the stored
     * thread, never the request; a hand off's seed stands in for it while the
     * thread has no messages. A failed planning call degrades to searching
     * the message as typed.
     *
     * @param array<array-key, mixed> $body
     * @return array{ok: bool, data?: array{query: string, needs_search: bool, terms: list<string>}, status?: int, error?: string}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function handlePlan(ChatOwner $owner, array $body, bool $chatHeader): array
    {
        $refused = $this->refuse($chatHeader);
        if ($refused !== null) {
            return $refused;
        }
        try {
            $request = ChatRequest::fromBody($body, $this->chat, $this->allowedHosts);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'status' => 400, 'error' => $e->getMessage()];
        }

        $state = $this->load($owner, $request->threadId) ?? new ThreadState();
        $history = [];
        foreach (array_slice($state->messages, -self::PLAN_HISTORY) as $message) {
            $history[] = ['role' => $message['role'], 'content' => $message['content']];
        }
        if ($history === [] && $request->seed !== null) {
            $history = [
                ['role' => 'user', 'content' => $request->seed['query']],
                ['role' => 'assistant', 'content' => $request->seed['summary']],
            ];
        }

        $plan = null;
        $start = microtime(true);
        try {
            $raw = $this->aiService->messageForOperation(
                'chat_plan',
                QueryPlan::systemPrompt($this->aiService->getChatPlanPrompt(), $this->aiService->getExpandPrompt()),
                QueryPlan::userMessage($request->message, $history, $request->page['title'] ?? ''),
                self::PLAN_MAX_TOKENS,
            );
            $plan = QueryPlan::parse($raw);
        } catch (\Exception $e) {
            $this->logger->warning('Scolta chat plan failed ({exception_class}); searching the message as typed', ['exception_class' => $e::class]);
        }
        $this->logger->info('Scolta chat plan: {ms} ms, parsed {parsed}', ['ms' => self::ms($start), 'parsed' => $plan !== null ? 'yes' : 'no']);

        return ['ok' => true, 'data' => $plan ?? [
            'query' => mb_substr($request->message, 0, 200),
            'needs_search' => true,
            'terms' => [],
        ]];
    }

    /**
     * Answer one turn as a stream of [event, data] pairs.
     *
     * Events, in order: `thread` {thread_id}; `delta` {text}, many; `sources`
     * {pages}, the pages the answer cited in order of first citation; `done`
     * {fold}, true when messages are waiting to be folded. A failure yields a
     * single `error` {message, status} instead, first when the request itself
     * is refused, so an adapter can read the first event before it commits to
     * a streamed response.
     *
     * Body: {thread_id?, message, needs_search, pages, page?, seed?}.
     *
     * @param array<array-key, mixed> $body
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}>
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function streamTurn(ChatOwner $owner, array $body, bool $chatHeader): \Generator
    {
        $refused = $this->refuse($chatHeader);
        if ($refused !== null) {
            yield self::errorEvent($refused);
            return;
        }
        try {
            $request = ChatRequest::fromBody($body, $this->chat, $this->allowedHosts);
        } catch (\InvalidArgumentException $e) {
            yield ['error', ['message' => $e->getMessage(), 'status' => 400]];
            return;
        }

        $threadId = $request->threadId;
        $state = $this->load($owner, $threadId);
        if ($state === null) {
            $threadId = ChatOwner::newThreadId();
            $state = new ThreadState();
        }
        $this->threads->save($owner->currentKey(), ['thread_id' => $threadId], $this->chat['threadTtl']);
        if ($request->seed !== null) {
            ContextAssembler::applySeed($state, $request->seed);
        }
        yield ['thread', ['thread_id' => $threadId]];

        if ($state->turns >= self::TURN_CEILING) {
            yield ['delta', ['text' => $this->label('chatStartNew', self::START_NEW)]];
            yield ['sources', ['pages' => []]];
            yield ['done', ['fold' => false]];
            return;
        }

        if ($request->needsSearch && $request->pages === [] && $request->page === null) {
            // Nothing to ground an answer in. A model call could only answer
            // from general knowledge, so this reply is fixed.
            $answer = $this->label('chatNothingFound', self::NOTHING_FOUND);
            yield ['delta', ['text' => $answer]];
            $fold = $this->record($owner, $threadId, $state, $request->message, $answer, []);
            yield ['sources', ['pages' => []]];
            yield ['done', ['fold' => $fold]];
            return;
        }

        $system = $this->aiService->getChatPrompt();
        $userTurn = ChatPrompt::userTurn($request, $state->summary, ContextAssembler::priorSources($state));
        $assembled = ContextAssembler::assemble($state, $userTurn);
        $maxTokens = $this->chat['maxTokens'];
        $start = microtime(true);

        // Only an opening turn is cached: it depends on nothing but the prompt
        // and what was sent with the question. A seeded thread has history.
        $cacheKey = null;
        if ($this->cacheTtl > 0 && $state->messages === [] && !$request->isSmallTalk()) {
            $cacheKey = AiEndpointHandler::cacheKeyFor($this->generation, 'chat', $this->config->aiModel, $system, $userTurn, (string) $maxTokens);
            $hit = $this->cache->get($cacheKey);
            if (is_string($hit) && $hit !== '') {
                yield ['delta', ['text' => $hit]];
                yield from $this->finish($owner, $threadId, $state, $request, $hit, $start, true);
                return;
            }
        }

        $answer = '';
        $firstMs = null;
        try {
            $stream = $this->aiService->conversationStream($system, $assembled['messages'], $maxTokens, null, null, true);
            foreach ($stream as $piece) {
                if ($piece === '') {
                    continue;
                }
                $firstMs ??= self::ms($start);
                $answer .= $piece;
                yield ['delta', ['text' => $piece]];
            }
        } catch (ApiKeyMissingException $e) {
            yield ['error', ['message' => 'Chat unavailable', 'status' => 503]];
            return;
        } catch (\Exception $e) {
            yield self::errorEvent($this->aiFailureResult($e, 'chat', 'Chat unavailable'));
            return;
        }
        $answer = trim($answer);
        if ($answer === '') {
            yield ['error', ['message' => 'Chat unavailable', 'status' => 503]];
            return;
        }
        if ($cacheKey !== null) {
            $this->cache->set($cacheKey, $answer, $this->cacheTtl);
        }
        $this->logger->info('Scolta chat answer: first text {first_ms} ms, {pages} pages, page context {page_context}, prompt {prompt_chars} chars, {dropped} dropped', [
            'pages' => count($request->pages),
            'page_context' => $request->page !== null ? 'yes' : 'no',
            'prompt_chars' => mb_strlen($system) + array_sum(array_map(static fn(array $m): int => mb_strlen($m['content']), $assembled['messages'])),
            'dropped' => $assembled['dropped'],
            'first_ms' => $firstMs,
        ]);
        yield from $this->finish($owner, $threadId, $state, $request, $answer, $start, false);
    }

    /**
     * streamTurn() collected into one reply, for clients that cannot stream.
     *
     * @param array<array-key, mixed> $body
     * @return array{ok: bool, data?: array{thread_id: string, answer: string, sources: list<array{n: int, title: string, url: string}>, fold: bool}, status?: int, error?: string}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function handleTurn(ChatOwner $owner, array $body, bool $chatHeader): array
    {
        $data = ['thread_id' => '', 'answer' => '', 'sources' => [], 'fold' => false];
        foreach ($this->streamTurn($owner, $body, $chatHeader) as [$event, $payload]) {
            if ($event === 'error') {
                return ['ok' => false, 'status' => (int) $payload['status'], 'error' => (string) $payload['message']];
            }
            match ($event) {
                'thread' => $data['thread_id'] = (string) $payload['thread_id'],
                'delta' => $data['answer'] .= (string) $payload['text'],
                'sources' => $data['sources'] = $payload['pages'],
                'done' => $data['fold'] = (bool) $payload['fold'],
                default => null,
            };
        }

        return ['ok' => true, 'data' => $data];
    }

    /**
     * Fold the messages that left the window into the running summary.
     *
     * Body: {thread_id}. Runs after a reply is on screen, as its own request.
     * The fold saves only if no turn saved while it was summarizing; if one
     * did, it reloads and folds again.
     *
     * @param array<array-key, mixed> $body
     * @return array{ok: bool, data?: array{folded: bool}, status?: int, error?: string}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function handleFold(ChatOwner $owner, array $body, bool $chatHeader): array
    {
        $refused = $this->refuse($chatHeader);
        if ($refused !== null) {
            return $refused;
        }
        $threadId = $body['thread_id'] ?? null;
        if (!ChatOwner::isThreadId($threadId)) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid thread'];
        }

        $system = $this->aiService->getChatFoldPrompt();
        for ($attempt = 0; $attempt < self::FOLD_ATTEMPTS; $attempt++) {
            $state = $this->load($owner, $threadId);
            $evicted = $state === null ? [] : ContextAssembler::pendingFold($state);
            if ($state === null || $evicted === []) {
                return ['ok' => true, 'data' => ['folded' => false]];
            }
            $start = microtime(true);
            try {
                $summary = $this->aiService->message($system, ContextAssembler::foldMessage($state, $evicted), self::FOLD_MAX_TOKENS);
                ContextAssembler::applyFold($state, $summary);
            } catch (\Exception $e) {
                $this->logger->warning('Scolta chat fold failed ({exception_class}); kept the questions only', ['exception_class' => $e::class]);
                ContextAssembler::applyFallbackFold($state, $evicted);
            }
            $latest = $this->load($owner, $threadId);
            if ($latest === null || $latest->revision !== $state->revision) {
                continue;
            }
            $state->revision++;
            $this->threads->save($owner->threadKey($threadId), $state->toArray(), $this->chat['threadTtl']);
            $this->logger->info('Scolta chat fold: {ms} ms, {messages} messages', ['ms' => self::ms($start), 'messages' => count($evicted)]);

            return ['ok' => true, 'data' => ['folded' => true]];
        }

        return ['ok' => true, 'data' => ['folded' => false]];
    }

    /**
     * A thread's history, to restore the chat on reload: the given thread or,
     * without one, the owner's current thread.
     *
     * @return array{ok: bool, data?: array{thread_id: string|null, messages: list<array{role: string, content: string, sources: list<array{n: int, title: string, url: string}>}>}, status?: int, error?: string}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function handleThread(ChatOwner $owner, ?string $threadId, bool $chatHeader): array
    {
        $refused = $this->refuse($chatHeader);
        if ($refused !== null) {
            return $refused;
        }
        if ($threadId === null || $threadId === '') {
            $threadId = $this->currentThreadId($owner);
        }
        $state = ChatOwner::isThreadId($threadId) ? $this->load($owner, (string) $threadId) : null;
        if ($state === null) {
            return ['ok' => true, 'data' => ['thread_id' => null, 'messages' => []]];
        }
        $messages = [];
        foreach ($state->messages as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
                'sources' => $message['sources'] ?? [],
            ];
        }

        return ['ok' => true, 'data' => ['thread_id' => $threadId, 'messages' => $messages]];
    }

    /**
     * Start a new empty thread: the current one is deleted.
     *
     * @return array{ok: bool, data?: array{thread_id: string}, status?: int, error?: string}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function handleReset(ChatOwner $owner, bool $chatHeader): array
    {
        $refused = $this->refuse($chatHeader);
        if ($refused !== null) {
            return $refused;
        }
        $current = $this->currentThreadId($owner);
        if ($current !== null) {
            $this->threads->delete($owner->threadKey($current));
        }
        $threadId = ChatOwner::newThreadId();
        $this->threads->save($owner->currentKey(), ['thread_id' => $threadId], $this->chat['threadTtl']);

        return ['ok' => true, 'data' => ['thread_id' => $threadId]];
    }

    /**
     * Sources, then saving the turn, then `done`.
     *
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    private function finish(ChatOwner $owner, string $threadId, ThreadState $state, ChatRequest $request, string $answer, float $start, bool $cached): \Generator
    {
        $sources = CitedPages::find($answer, $request->citablePages());
        $fold = $this->record($owner, $threadId, $state, $request->message, $answer, $sources);
        $this->logger->info('Scolta chat turn: {ms} ms, cached {cached}, {cited} cited', ['ms' => self::ms($start), 'cached' => $cached ? 'yes' : 'no', 'cited' => count($sources)]);
        yield ['sources', ['pages' => $sources]];
        yield ['done', ['fold' => $fold]];
    }

    /**
     * Save a turn by reloading the thread and appending its two messages, so
     * a fold that saved while the answer streamed is kept.
     *
     * @param list<array{n: int, title: string, url: string}> $sources
     * @return bool Whether messages are now waiting to be folded.
     */
    private function record(ChatOwner $owner, string $threadId, ThreadState $started, string $question, string $answer, array $sources): bool
    {
        // A thread that existed when the turn began and is gone now was reset
        // while the answer streamed: saving would bring it back.
        $state = $this->load($owner, $threadId) ?? ($started->revision === 0 ? $started : null);
        if ($state === null) {
            return false;
        }
        $state->addExchange($question, $answer, $sources);
        $state->revision++;
        $this->threads->save($owner->threadKey($threadId), $state->toArray(), $this->chat['threadTtl']);

        return ContextAssembler::pendingFold($state) !== [];
    }

    private function load(ChatOwner $owner, string $threadId): ?ThreadState
    {
        if (!ChatOwner::isThreadId($threadId)) {
            return null;
        }
        $data = $this->threads->load($owner->threadKey($threadId));

        return $data === null ? null : ThreadState::fromArray($data);
    }

    private function currentThreadId(ChatOwner $owner): ?string
    {
        $pointer = $this->threads->load($owner->currentKey());
        $id = $pointer['thread_id'] ?? null;

        return ChatOwner::isThreadId($id) ? $id : null;
    }

    /**
     * @return array{ok: false, status: int, error: string}|null
     */
    private function refuse(bool $chatHeader): ?array
    {
        if (!$this->chat['enabled']) {
            return ['ok' => false, 'status' => 404, 'error' => 'Chat disabled'];
        }
        if (!$chatHeader) {
            return ['ok' => false, 'status' => 403, 'error' => 'Missing X-Scolta-Chat header'];
        }

        return null;
    }

    private function label(string $key, string $default): string
    {
        return $this->config->normalizedLabels()[$key] ?? $default;
    }

    /**
     * @param array{status: int, error: string, retry_after?: string} $result
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function errorEvent(array $result): array
    {
        $data = ['message' => $result['error'], 'status' => $result['status']];
        if (isset($result['retry_after'])) {
            $data['retry_after'] = $result['retry_after'];
        }

        return ['error', $data];
    }

    private static function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
