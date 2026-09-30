<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Chat\ThreadState;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Exception\RateLimitException;
use Tag1\Scolta\Http\ChatEndpointHandler;
use Tag1\Scolta\Http\ServerSentEvent;
use Tag1\Scolta\Tests\Chat\InMemoryThreadStore;

class ChatEndpointHandlerTest extends TestCase
{
    private const HOST = 'complianceiq.ddev.site';

    private FakeChatAiService $ai;
    private InMemoryThreadStore $threads;
    private InMemoryCacheDriver $cache;
    private ChatOwner $owner;

    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->ai = new FakeChatAiService();
        $this->threads = new InMemoryThreadStore();
        $this->cache = new InMemoryCacheDriver();
        $this->owner = ChatOwner::forUser(7);
        $this->logs = [];
    }

    /** @param array<string, mixed> $config */
    private function handler(array $config = [], int $generation = 1, int $cacheTtl = 3600): ChatEndpointHandler
    {
        $logs = &$this->logs;
        $logger = new class ($logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<mixed>}> $logs */
            public function __construct(private array &$logs) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new ChatEndpointHandler(
            $this->ai,
            $this->cache,
            $generation,
            $cacheTtl,
            $this->threads,
            ScoltaConfig::fromArray($config + ['site_name' => 'ComplianceIQ', 'chat_enabled' => true, 'ai_model' => 'model-a']),
            ['ComplianceIQ.ddev.site'],
            $logger,
        );
    }

    private static function page(string $path, int $tier = 1, string $excerpt = 'Excerpt.'): array
    {
        return ['tier' => $tier, 'title' => 'Page ' . $path, 'url' => 'https://' . self::HOST . $path, 'excerpt' => $excerpt];
    }

    /** @param array<string, mixed> $body */
    private static function turn(array $body = []): array
    {
        return $body + ['message' => 'What does GDPR say about breach notification?', 'needs_search' => true, 'pages' => [self::page('/gdpr'), self::page('/hipaa', 2)]];
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function events(array $body, ?ChatEndpointHandler $handler = null, ?ChatOwner $owner = null): array
    {
        return iterator_to_array(($handler ?? $this->handler())->streamTurn($owner ?? $this->owner, $body, true), false);
    }

    private function stored(string $threadId, ?ChatOwner $owner = null): ThreadState
    {
        return ThreadState::fromArray($this->threads->data[($owner ?? $this->owner)->threadKey($threadId)] ?? null);
    }

    // ------------------------------------------------------------------
    // Gates
    // ------------------------------------------------------------------

    public function testEveryHandlerAnswers404WhileTheChatIsOff(): void
    {
        $handler = $this->handler(['chat_enabled' => false]);

        $this->assertSame(404, $handler->handlePlan($this->owner, ['message' => 'x'], true)['status']);
        $this->assertSame(404, $handler->handleTurn($this->owner, self::turn(), true)['status']);
        $this->assertSame(404, $handler->handleFold($this->owner, ['thread_id' => ChatOwner::newThreadId()], true)['status']);
        $this->assertSame(404, $handler->handleThread($this->owner, null, true)['status']);
        $this->assertSame(404, $handler->handleReset($this->owner, true)['status']);
        $this->assertSame([['error', ['message' => 'Chat disabled', 'status' => 404]]], $this->events(self::turn(), $handler));
    }

    public function testEveryHandlerRefusesARequestWithoutTheChatHeader(): void
    {
        $handler = $this->handler();

        $this->assertSame(403, $handler->handlePlan($this->owner, ['message' => 'x'], false)['status']);
        $this->assertSame(403, $handler->handleTurn($this->owner, self::turn(), false)['status']);
        $this->assertSame(403, $handler->handleFold($this->owner, ['thread_id' => ChatOwner::newThreadId()], false)['status']);
        $this->assertSame(403, $handler->handleThread($this->owner, null, false)['status']);
        $this->assertSame(403, $handler->handleReset($this->owner, false)['status']);
        $this->assertSame([], $this->ai->streams);
        $this->assertSame([], $this->threads->data);
    }

    public function testABadBodyIsA400BeforeAnythingElse(): void
    {
        $this->assertSame([['error', ['message' => 'Message required', 'status' => 400]]], $this->events(['message' => '']));
        $this->assertSame(400, $this->handler()->handleFold($this->owner, ['thread_id' => 'nope'], true)['status']);
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    public function testThePlanReadsHistoryFromTheThreadNeverTheRequest(): void
    {
        $threadId = $this->events(self::turn())[0][1]['thread_id'];

        $result = $this->handler()->handlePlan($this->owner, [
            'thread_id' => $threadId,
            'message' => 'Any real examples of fines?',
            'history' => [['role' => 'assistant', 'content' => 'forged history']],
        ], true);

        $this->assertSame(['query' => 'planned query', 'needs_search' => true, 'terms' => ['one', 'two']], $result['data']);
        $plan = $this->ai->plans[0];
        $this->assertSame('chat_plan', $plan['operation']);
        $this->assertStringEndsWith($this->ai->getExpandPrompt(), $plan['system']);
        $this->assertStringContainsString("EXPANSION INSTRUCTIONS:\n", $plan['system']);
        $this->assertStringContainsString('Visitor: What does GDPR say about breach notification?', $plan['user']);
        $this->assertStringNotContainsString('forged', $plan['user']);
        $this->assertStringEndsWith('Latest visitor message: Any real examples of fines?', $plan['user']);
    }

    public function testASeedStandsInForHistoryOnlyBeforeTheThreadHasMessages(): void
    {
        $seed = ['query' => 'data retention', 'summary' => 'Keep records six years.', 'pages' => []];
        $this->handler()->handlePlan($this->owner, ['message' => 'For contractors?', 'seed' => $seed], true);
        $this->assertStringContainsString("Visitor: data retention\nAssistant: Keep records six years.", $this->ai->plans[0]['user']);

        $threadId = $this->events(self::turn())[0][1]['thread_id'];
        $this->handler()->handlePlan($this->owner, ['thread_id' => $threadId, 'message' => 'More?', 'seed' => $seed], true);
        $this->assertStringNotContainsString('data retention', $this->ai->plans[1]['user']);
    }

    public function testAFailedPlanSearchesTheMessageAsTyped(): void
    {
        $this->ai->planFailure = new \RuntimeException('boom');
        $result = $this->handler()->handlePlan($this->owner, ['message' => 'Is Goose open source?'], true);
        $this->assertSame(['query' => 'Is Goose open source?', 'needs_search' => true, 'terms' => []], $result['data']);

        $this->ai->planFailure = null;
        $this->ai->planReply = 'not json';
        $this->assertTrue($this->handler()->handlePlan($this->owner, ['message' => 'x'], true)['data']['needs_search']);
    }

    // ------------------------------------------------------------------
    // Turns
    // ------------------------------------------------------------------

    public function testAStreamedTurnSendsThreadDeltasSourcesAndDone(): void
    {
        $this->ai->pieces = ['GDPR requires notice within 72 hours ', '[[1]](https://complianceiq.ddev.site/gdpr).'];

        $events = $this->events(self::turn());

        $this->assertSame(['thread', 'delta', 'delta', 'sources', 'done'], array_column($events, 0));
        $threadId = $events[0][1]['thread_id'];
        $this->assertTrue(ChatOwner::isThreadId($threadId));
        $this->assertSame([['n' => 1, 'title' => 'Page /gdpr', 'url' => 'https://complianceiq.ddev.site/gdpr']], $events[3][1]['pages']);
        $this->assertSame(['fold' => false], $events[4][1]);

        $stream = $this->ai->streams[0];
        $this->assertSame($this->ai->getChatPrompt(), $stream['system'], 'The system prompt is the template and nothing else.');
        $this->assertTrue($stream['cacheSystem']);
        $this->assertSame(700, $stream['maxTokens']);
        $this->assertStringContainsString('Pages for this turn:', $stream['messages'][0]['content']);

        $state = $this->stored($threadId);
        $this->assertSame(['What does GDPR say about breach notification?', 'GDPR requires notice within 72 hours [[1]](https://complianceiq.ddev.site/gdpr).'], array_column($state->messages, 'content'));
        $this->assertSame(1, $state->turns);
        $this->assertSame(86400, $this->threads->ttls[$this->owner->threadKey($threadId)]);
    }

    public function testADeclineThatCitesNothingShowsNoSources(): void
    {
        $this->ai->pieces = ["The pages I have here don't cover sourdough."];
        $events = $this->events(self::turn(['message' => 'How do I make sourdough bread?']));

        $this->assertSame(['pages' => []], $events[array_search('sources', array_column($events, 0), true)][1]);
    }

    public function testNoPagesForASearchGetsTheFixedReplyWithoutAModelCall(): void
    {
        $events = $this->events(self::turn(['pages' => []]));

        $this->assertSame(['thread', 'delta', 'sources', 'done'], array_column($events, 0));
        $this->assertStringContainsString("I couldn't find anything on this site about that.", $events[1][1]['text']);
        $this->assertSame([], $this->ai->streams);

        $custom = $this->events(self::turn(['pages' => []]), $this->handler(['labels' => ['chatNothingFound' => 'Nothing here on that.']]));
        $this->assertSame('Nothing here on that.', $custom[1][1]['text']);
    }

    public function testSmallTalkIsAnsweredWithNoPagesAndNotCached(): void
    {
        $this->ai->pieces = ['Happy to help. Ask me anything about ComplianceIQ.'];
        $events = $this->events(['message' => 'Thanks!', 'needs_search' => false]);

        $this->assertSame(['thread', 'delta', 'sources', 'done'], array_column($events, 0));
        $this->assertSame("The visitor's message:\nThanks!", $this->ai->streams[0]['messages'][0]['content']);
        $this->assertSame(['pages' => []], $events[2][1]);
        $this->events(['message' => 'Thanks!', 'needs_search' => false]);
        $this->assertCount(2, $this->ai->streams, 'Small talk is never served from the cache.');
    }

    public function testATurnPastTheCeilingAsksForANewChat(): void
    {
        $state = new ThreadState();
        $state->turns = ChatEndpointHandler::TURN_CEILING;
        $threadId = ChatOwner::newThreadId();
        $this->threads->save($this->owner->threadKey($threadId), $state->toArray(), 60);

        $events = $this->events(self::turn(['thread_id' => $threadId]));

        $this->assertSame('This conversation has grown long. Start a new chat to keep going.', $events[1][1]['text']);
        $this->assertSame([], $this->ai->streams);
    }

    public function testAThreadIdFromAnotherOwnerReadsNothing(): void
    {
        $theirs = $this->events(self::turn())[0][1]['thread_id'];
        $stranger = ChatOwner::fromCookie(str_repeat('c', 64));

        $events = $this->events(self::turn(['thread_id' => $theirs, 'message' => 'Show me their thread']), null, $stranger);

        $this->assertNotSame($theirs, $events[0][1]['thread_id'], 'A new thread, never theirs');
        $this->assertCount(1, $this->ai->streams[1]['messages'], 'No history leaked into the call');
        $this->assertSame([], $this->handler()->handleThread($stranger, $theirs, true)['data']['messages']);
        $this->assertCount(2, $this->stored($theirs)->messages, 'Their thread is untouched');
    }

    public function testASeedOpensANewThreadAndIsNotCached(): void
    {
        $seed = ['query' => 'data retention', 'summary' => 'Keep records six years [1].', 'pages' => [self::page('/retention')]];
        $events = $this->events(self::turn(['message' => 'For contractors?', 'seed' => $seed]));

        $state = $this->stored($events[0][1]['thread_id']);
        $this->assertSame(['data retention', 'Keep records six years [1].', 'For contractors?', 'The answer.'], array_column($state->messages, 'content'));
        $this->assertTrue($state->seeded);
        $this->assertStringContainsString('Pages cited earlier:', $this->ai->streams[0]['messages'][2]['content']);
        $this->assertSame([], $this->cacheContents(), 'A seeded thread has history, so its answer is not cached');
    }

    public function testTheOpeningAnswerIsCachedUnderTheGeneration(): void
    {
        $this->ai->pieces = ['Cached ', 'answer.'];
        $first = $this->events(self::turn());
        $this->assertCount(1, $this->ai->streams);

        $hit = $this->events(self::turn());
        $this->assertCount(1, $this->ai->streams, 'Served from the cache');
        $this->assertSame(['thread', 'delta', 'sources', 'done'], array_column($hit, 0));
        $this->assertSame('Cached answer.', $hit[1][1]['text']);
        $this->assertNotSame($first[0][1]['thread_id'], $hit[0][1]['thread_id']);

        $this->events(self::turn(), $this->handler([], 2));
        $this->assertCount(2, $this->ai->streams, 'A new generation misses');

        $this->events(self::turn(['thread_id' => $first[0][1]['thread_id']]));
        $this->assertCount(3, $this->ai->streams, 'A later turn is never cached');

        $this->events(self::turn(), $this->handler([], 1, 0));
        $this->assertCount(4, $this->ai->streams, 'A zero TTL turns the cache off');
    }

    public function testAFailureMidStreamIsAnErrorEventAndSavesNothing(): void
    {
        $this->ai->pieces = ['Part'];
        $this->ai->streamFailure = new RateLimitException('Scolta AI API rate limit reached.', '30');

        $events = $this->events(self::turn());

        $this->assertSame(['thread', 'delta', 'error'], array_column($events, 0));
        $this->assertSame(['message' => 'AI API rate limit reached', 'status' => 429, 'retry_after' => '30'], $events[2][1]);
        $this->assertSame([], $this->stored($events[0][1]['thread_id'])->messages);

        $this->ai->pieces = [];
        $this->ai->streamFailure = new ApiKeyMissingException('no key');
        $this->assertSame(['message' => 'Chat unavailable', 'status' => 503], $this->events(self::turn())[1][1]);
    }

    public function testHandleTurnCollectsTheStream(): void
    {
        $this->ai->pieces = ['A ', 'reply [[1]](https://complianceiq.ddev.site/gdpr).'];
        $result = $this->handler()->handleTurn($this->owner, self::turn(), true);

        $this->assertTrue($result['ok']);
        $this->assertSame('A reply [[1]](https://complianceiq.ddev.site/gdpr).', $result['data']['answer']);
        $this->assertSame([1], array_column($result['data']['sources'], 'n'));

        $this->ai->streamFailure = new RateLimitException('limit', null);
        $this->ai->pieces = [];
        $this->assertSame(['ok' => false, 'status' => 429, 'error' => 'AI API rate limit reached'], $this->handler([], 1, 0)->handleTurn($this->owner, self::turn(), true));
    }

    public function testLogsCarryNoMessageOrPageText(): void
    {
        $this->ai->pieces = ['Secret answer text'];
        $this->events(self::turn(['message' => 'my private question', 'pages' => [self::page('/p', 1, 'private page text')]]));
        $this->ai->planFailure = new \RuntimeException('private failure detail');
        $this->handler()->handlePlan($this->owner, ['message' => 'my private question'], true);

        $this->assertNotSame([], $this->logs);
        $flat = json_encode($this->logs);
        foreach (['private question', 'private page text', 'Secret answer', 'private failure detail'] as $text) {
            $this->assertStringNotContainsString($text, (string) $flat);
        }
    }

    // ------------------------------------------------------------------
    // Fold, history, reset
    // ------------------------------------------------------------------

    /** @return string The thread id, after four answered turns. */
    private function fourTurns(): string
    {
        $threadId = $this->events(self::turn(['message' => 'Q1']))[0][1]['thread_id'];
        foreach (['Q2', 'Q3', 'Q4'] as $q) {
            $done = $this->events(self::turn(['message' => $q, 'thread_id' => $threadId]));
        }
        $this->assertSame(['fold' => true], end($done)[1], 'Four turns push a pair out of the window');

        return $threadId;
    }

    public function testAFoldSummarizesWhatLeftTheWindow(): void
    {
        $threadId = $this->fourTurns();

        $result = $this->handler()->handleFold($this->owner, ['thread_id' => $threadId], true);

        $this->assertTrue($result['data']['folded']);
        $state = $this->stored($threadId);
        $this->assertSame('A short summary.', $state->summary);
        $this->assertSame(2, $state->folded);
        $this->assertSame($this->ai->getChatFoldPrompt(), $this->ai->messages[0]['system']);
        $this->assertStringContainsString('Visitor: Q1', $this->ai->messages[0]['user']);
        $this->assertFalse($this->handler()->handleFold($this->owner, ['thread_id' => $threadId], true)['data']['folded'], 'Nothing left to fold');
    }

    public function testAFailedFoldKeepsTheQuestions(): void
    {
        $threadId = $this->fourTurns();
        $this->ai->foldFailure = new \RuntimeException('down');

        $this->handler()->handleFold($this->owner, ['thread_id' => $threadId], true);

        $this->assertStringContainsString('Earlier the visitor asked: Q1', $this->stored($threadId)->summary);
    }

    public function testATurnThatSavesDuringAFoldMakesTheFoldStartOver(): void
    {
        $threadId = $this->fourTurns();
        $once = true;
        $this->ai->duringFold = function () use ($threadId, &$once): void {
            if ($once) {
                $once = false;
                iterator_to_array($this->handler()->streamTurn($this->owner, self::turn(['message' => 'Q5', 'thread_id' => $threadId]), true));
            }
        };

        $this->handler()->handleFold($this->owner, ['thread_id' => $threadId], true);

        $state = $this->stored($threadId);
        $this->assertCount(10, $state->messages, 'The turn that landed mid fold was kept');
        $this->assertSame('Q5', $state->messages[8]['content']);
        $this->assertSame(4, $state->folded, 'The second fold covered what the new turn pushed out');
        $this->assertCount(2, $this->ai->messages, 'The fold ran again on the fresh thread');
    }

    public function testAFoldThatSavesDuringATurnIsKeptAndTheTurnSeesTheUnfoldedMessages(): void
    {
        $threadId = $this->fourTurns();
        $this->ai->pieces = ['Five.'];
        $turn = $this->handler()->streamTurn($this->owner, self::turn(['message' => 'Q5', 'thread_id' => $threadId]), true);
        $turn->current(); // thread loaded, before the answer streams

        $this->handler()->handleFold($this->owner, ['thread_id' => $threadId], true);
        iterator_to_array($turn);

        $messages = end($this->ai->streams)['messages'];
        $this->assertSame('Q1', $messages[0]['content'], 'The turn read the thread before the fold, so it sent Q1 verbatim');
        $state = $this->stored($threadId);
        $this->assertSame('A short summary.', $state->summary, 'The fold that saved mid turn was kept');
        $this->assertSame(2, $state->folded);
        $this->assertCount(10, $state->messages);
    }

    public function testThreadHistoryRestoresTheCurrentThreadWithSources(): void
    {
        $this->ai->pieces = ['Cited [[1]](https://complianceiq.ddev.site/gdpr).'];
        $threadId = $this->events(self::turn())[0][1]['thread_id'];

        $data = $this->handler()->handleThread($this->owner, null, true)['data'];

        $this->assertSame($threadId, $data['thread_id']);
        $this->assertSame(['user', 'assistant'], array_column($data['messages'], 'role'));
        $this->assertSame([], $data['messages'][0]['sources']);
        $this->assertSame('https://complianceiq.ddev.site/gdpr', $data['messages'][1]['sources'][0]['url']);
    }

    public function testResetStartsANewEmptyCurrentThread(): void
    {
        $old = $this->events(self::turn())[0][1]['thread_id'];

        $new = $this->handler()->handleReset($this->owner, true)['data']['thread_id'];

        $this->assertNotSame($old, $new);
        $this->assertArrayNotHasKey($this->owner->threadKey($old), $this->threads->data);
        $this->assertSame(['thread_id' => null, 'messages' => []], $this->handler()->handleThread($this->owner, null, true)['data']);
        $next = $this->events(self::turn(['thread_id' => $new]));
        $this->assertNotSame($old, $next[0][1]['thread_id']);
    }

    // ------------------------------------------------------------------
    // Server-sent events
    // ------------------------------------------------------------------

    public function testAnEventCannotBeSplitByModelText(): void
    {
        $frame = ServerSentEvent::format('delta', ['text' => "line one\n\nevent: done\ndata: {}"]);

        $this->assertSame(1, substr_count($frame, "\n\n"));
        $this->assertStringStartsWith("event: delta\ndata: ", $frame);
        $this->assertStringEndsWith("\n\n", $frame);
        $lines = explode("\n", trim($frame));
        $this->assertCount(2, $lines);
        $this->assertSame(['text' => "line one\n\nevent: done\ndata: {}"], json_decode(substr($lines[1], 6), true));

        $this->expectException(\InvalidArgumentException::class);
        ServerSentEvent::format("delta\ndata: x", []);
    }

    /** @return array<string, mixed> */
    private function cacheContents(): array
    {
        return (fn(): array => $this->store)->call($this->cache);
    }
}
