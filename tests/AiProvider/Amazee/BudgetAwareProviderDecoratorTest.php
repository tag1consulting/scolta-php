<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\BudgetAwareProviderDecorator;

class BudgetAwareProviderDecoratorTest extends TestCase
{
    private function makeDecorator(array $responses): BudgetAwareProviderDecorator
    {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new AiClient(
            ['provider' => 'openai', 'api_key' => 'test-key', 'model' => 'gpt-4o'],
            $httpClient
        );
        return new BudgetAwareProviderDecorator($client);
    }

    public function testMessagePassesThroughSuccess(): void
    {
        $decorator = $this->makeDecorator([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'Hello world']]],
            ])),
        ]);

        $result = $decorator->message('system', 'hello');
        $this->assertSame('Hello world', $result);
    }

    public function testConversationPassesThroughSuccess(): void
    {
        $decorator = $this->makeDecorator([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'Response here']]],
            ])),
        ]);

        $result = $decorator->conversation('system', [['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('Response here', $result);
    }

    public function testMessageThrowsBudgetExceededException(): void
    {
        $decorator = $this->makeDecorator([
            // AiClient wraps HTTP errors as RuntimeException with the response body.
            // Simulate budget exceeded: the message will contain "Budget has been exceeded!".
            new Response(429, [], json_encode(['error' => ['message' => 'Budget has been exceeded!']])),
        ]);

        $this->expectException(AmazeeBudgetExceededException::class);
        $decorator->message('system', 'hello');
    }

    public function testConversationThrowsBudgetExceededException(): void
    {
        $decorator = $this->makeDecorator([
            new Response(429, [], json_encode(['error' => ['message' => 'Budget has been exceeded!']])),
        ]);

        $this->expectException(AmazeeBudgetExceededException::class);
        $decorator->conversation('system', [['role' => 'user', 'content' => 'hi']]);
    }

    public function testMessageRethrowsNonBudgetErrors(): void
    {
        $decorator = $this->makeDecorator([
            new Response(500, [], json_encode(['error' => ['message' => 'Internal server error']])),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/(?!.*Budget has been exceeded).*/');
        $decorator->message('system', 'hello');
    }

    public function testGetClientReturnsAiClient(): void
    {
        $mock = new MockHandler([]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $aiClient = new AiClient(['api_key' => 'test'], $httpClient);
        $decorator = new BudgetAwareProviderDecorator($aiClient);

        $this->assertSame($aiClient, $decorator->getClient());
    }

    // -------------------------------------------------------------------
    // isBudgetError() — the public API platform adapters call instead of
    // duplicating the budget-message magic string.
    // -------------------------------------------------------------------

    public function testIsBudgetErrorMatchesDirectMessage(): void
    {
        $e = new \RuntimeException('HTTP 429: ' . BudgetAwareProviderDecorator::BUDGET_MESSAGE);
        $this->assertTrue(BudgetAwareProviderDecorator::isBudgetError($e));
    }

    public function testIsBudgetErrorWalksTheExceptionChain(): void
    {
        $inner = new \RuntimeException('Budget has been exceeded!');
        $outer = new \RuntimeException('AI API request failed', 0, $inner);
        $this->assertTrue(BudgetAwareProviderDecorator::isBudgetError($outer));
    }

    public function testIsBudgetErrorRecognizesAmazeeBudgetExceededException(): void
    {
        $e = new AmazeeBudgetExceededException(new \RuntimeException('original'));
        $this->assertTrue(BudgetAwareProviderDecorator::isBudgetError($e));
    }

    public function testIsBudgetErrorRejectsUnrelatedErrors(): void
    {
        $e = new \RuntimeException('Internal server error', 0, new \RuntimeException('timeout'));
        $this->assertFalse(BudgetAwareProviderDecorator::isBudgetError($e));
    }
}
