<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Http\AiControllerTrait;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\Scolta\Prompt\NullEnricher;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

class AiControllerTraitTest extends TestCase
{
    public function testCreateHandlerReturnsAiEndpointHandler(): void
    {
        $controller = new ConcreteAiController();
        $config     = new ScoltaConfig();

        $handler = $controller->exposeCreateHandler(new \stdClass(), $config);

        $this->assertInstanceOf(AiEndpointHandler::class, $handler);
    }

    public function testCreateHandlerPassesCacheTtlToResolveCache(): void
    {
        $controller     = new ConcreteAiController();
        $config         = new ScoltaConfig();
        $config->cacheTtl = 300;

        $controller->exposeCreateHandler(new \stdClass(), $config);

        $this->assertSame(300, $controller->lastCacheTtl);
    }

    public function testCreateHandlerPassesZeroCacheTtl(): void
    {
        $controller       = new ConcreteAiController();
        $config           = new ScoltaConfig();
        $config->cacheTtl = 0;

        $controller->exposeCreateHandler(new \stdClass(), $config);

        $this->assertSame(0, $controller->lastCacheTtl);
    }

    public function testCreateHandlerPassesResolvedLoggerToHandler(): void
    {
        $controller = new ConcreteAiController();
        $controller->logger = new TraitRecordingLogger();
        $config = new ScoltaConfig();
        $config->cacheTtl = 0;

        $handler = $controller->exposeCreateHandler(new ThrowingAiService(), $config);
        $result = $handler->handleSummarize('pricing', 'some excerpts');

        $this->assertTrue($result['ok'], 'A provider failure degrades to a 200 result');
        $this->assertSame('ai_error', $result['data']['degraded_reason']);
        $this->assertCount(1, $controller->logger->records, 'The provider failure must reach the resolved logger');
        $this->assertSame('error', $controller->logger->records[0]['level']);
        $this->assertStringContainsString('summarization failed', $controller->logger->records[0]['message']);
        $this->assertInstanceOf(\RuntimeException::class, $controller->logger->records[0]['context']['exception']);
    }

    // -------------------------------------------------------------------
    // parseJsonBody() — shared JSON decode + error shape for controllers
    // -------------------------------------------------------------------

    public function testParseJsonBodyDecodesValidJson(): void
    {
        $controller = new ConcreteAiController();

        $result = $controller->exposeParseJsonBody('{"query": "hello", "n": 3}');

        $this->assertTrue($result['ok']);
        $this->assertSame(['query' => 'hello', 'n' => 3], $result['data']);
    }

    public function testParseJsonBodyReturnsErrorShapeOnMalformedJson(): void
    {
        $controller = new ConcreteAiController();

        $result = $controller->exposeParseJsonBody('{"query": ');

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('Malformed JSON', $result['error']);
    }

    public function testParseJsonBodyRejectsScalarJson(): void
    {
        $controller = new ConcreteAiController();

        $result = $controller->exposeParseJsonBody('"just a string"');

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['status']);
    }

    public function testParseJsonBodyRejectsEmptyBody(): void
    {
        $controller = new ConcreteAiController();

        $result = $controller->exposeParseJsonBody('');

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['status']);
    }
}

/** @internal test double */
class ConcreteAiController
{
    use AiControllerTrait;

    public int $lastCacheTtl = -1;

    public LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function exposeCreateHandler(object $aiService, ScoltaConfig $config): AiEndpointHandler
    {
        return $this->createHandler($aiService, $config);
    }

    public function exposeParseJsonBody(string $rawBody): array
    {
        return $this->parseJsonBody($rawBody);
    }

    protected function resolveCache(int $cacheTtl): CacheDriverInterface
    {
        $this->lastCacheTtl = $cacheTtl;
        return new NullCacheDriver();
    }

    protected function getCacheGeneration(): int
    {
        return 0;
    }

    protected function resolveEnricher(): PromptEnricherInterface
    {
        return new NullEnricher();
    }

    protected function resolveLogger(): LoggerInterface
    {
        return $this->logger;
    }
}

/** @internal test double: every AI call fails like a provider outage */
class ThrowingAiService
{
    public function getSummarizePrompt(): string
    {
        return 'Summarize.';
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string
    {
        throw new \RuntimeException('529 overloaded');
    }
}

/** @internal test double */
class TraitRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
