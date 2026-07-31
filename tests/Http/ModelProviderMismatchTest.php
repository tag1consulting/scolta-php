<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ModelProviderMismatchException;
use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * The provider/model mismatch tripwire, end to end through the handler.
 *
 * The failure being guarded: a site provisioned onto the Amazee.ai gateway
 * keeps the gateway's model alias, then switches to a direct provider key.
 * Every AI call fails, and before this the only signal was `ai_error` —
 * indistinguishable from a transient outage, and nothing an operator could
 * search for. These tests pin the specific reason, the specific message, and
 * the fact that ordinary failures are still reported the ordinary way.
 */
class ModelProviderMismatchTest extends TestCase
{
    public function testExpandQueryReportsMismatchReasonNotAiError(): void
    {
        $handler = $this->makeHandler(new MismatchingAiService());

        $result = $handler->handleExpandQuery('barga history');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['data']['degraded']);
        $this->assertSame(
            AiEndpointHandler::REASON_MODEL_MISMATCH,
            $result['data']['degraded_reason'],
        );
        $this->assertNotSame('ai_error', $result['data']['degraded_reason']);
    }

    public function testSummarizeReportsMismatchReasonNotAiError(): void
    {
        $handler = $this->makeHandler(new MismatchingAiService());

        $result = $handler->handleSummarize('barga history', 'some excerpts');

        $this->assertTrue($result['ok']);
        $this->assertSame(
            AiEndpointHandler::REASON_MODEL_MISMATCH,
            $result['data']['degraded_reason'],
        );
    }

    public function testFollowUpSurfacesTheOperatorFacingMessage(): void
    {
        $handler = $this->makeHandler(new MismatchingAiService());

        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => 'tell me more'],
        ]);

        $this->assertFalse($result['ok']);
        // Not the generic 'Follow-up unavailable' — the operator needs the
        // model and the provider, and follow-up is a user-initiated action
        // that reports rather than silently degrades.
        $this->assertStringContainsString('claude-4-5-sonnet', $result['error']);
        $this->assertStringContainsString('anthropic', $result['error']);
    }

    public function testMismatchIsLoggedWithModelAndProviderAsContext(): void
    {
        $logger = new RecordingLogger();
        $handler = $this->makeHandler(new MismatchingAiService(), $logger);

        $handler->handleExpandQuery('barga history');

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];

        $this->assertSame('error', $record['level']);
        $this->assertStringContainsString('claude-4-5-sonnet', $record['message']);
        $this->assertStringContainsString('anthropic', $record['message']);
        // Structured fields as well as the message: this is the entry an
        // operator greps for, and aggregators reformat messages.
        $this->assertSame('claude-4-5-sonnet', $record['context']['ai_model']);
        $this->assertSame('anthropic', $record['context']['ai_provider']);
    }

    public function testOrdinaryProviderFailureStillReportsAiError(): void
    {
        // The tripwire must not swallow the generic bucket: a transport error
        // or a genuine outage is still `ai_error`, and a monitoring rule that
        // watches for it keeps working.
        $handler = $this->makeHandler(new FailingAiService());

        $result = $handler->handleExpandQuery('barga history');

        $this->assertTrue($result['ok']);
        $this->assertSame('ai_error', $result['data']['degraded_reason']);
    }

    private function makeHandler(
        object $aiService,
        ?RecordingLogger $logger = null,
    ): AiEndpointHandler {
        return new AiEndpointHandler(
            aiService: $aiService,
            cache: new NullCacheDriver(),
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
            logger: $logger ?? new RecordingLogger(),
        );
    }
}

/**
 * Stands in for an AI service whose configured model the provider rejects.
 */
class MismatchingAiService
{
    public function getExpandPrompt(): string
    {
        return 'Expand the following search query.';
    }

    public function getSummarizePrompt(): string
    {
        return 'Summarize the following search results.';
    }

    public function getFollowUpPrompt(): string
    {
        return 'Continue the conversation.';
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        throw $this->mismatch();
    }

    public function messageForOperation(
        string $operation,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
    ): string {
        throw $this->mismatch();
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        throw $this->mismatch();
    }

    private function mismatch(): ModelProviderMismatchException
    {
        return new ModelProviderMismatchException(
            'claude-4-5-sonnet',
            'anthropic',
            \Tag1\Scolta\AiProvider\ModelIdentity::describeMismatch('anthropic', 'claude-4-5-sonnet'),
        );
    }
}

/**
 * Stands in for an ordinary provider failure (outage, transport error).
 */
class FailingAiService extends MismatchingAiService
{
    public function message(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        throw new \RuntimeException('Scolta AI API request failed: connection reset');
    }

    public function messageForOperation(
        string $operation,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
    ): string {
        throw new \RuntimeException('Scolta AI API request failed: connection reset');
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        throw new \RuntimeException('Scolta AI API request failed: connection reset');
    }
}

class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

class NullCacheDriver implements CacheDriverInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void {}
}
