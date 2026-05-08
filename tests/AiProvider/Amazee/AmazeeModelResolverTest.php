<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeModelResolver;

class AmazeeModelResolverTest extends TestCase
{
    private function makeResolver(array $responses): AmazeeModelResolver
    {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new AmazeeClient('https://api.amazee.ai', $httpClient);
        return new AmazeeModelResolver($client);
    }

    // --- pickHighestVersion ---

    public function testPicksHighestSonnet(): void
    {
        $resolver = $this->makeResolver([]);
        $names = ['claude-sonnet-4-5-20250514', 'claude-sonnet-4-6', 'claude-sonnet-4-5'];
        $this->assertSame('claude-sonnet-4-6', $resolver->pickHighestVersion($names, 'sonnet'));
    }

    public function testPicksHighestHaiku(): void
    {
        $resolver = $this->makeResolver([]);
        $names = ['claude-haiku-4-5', 'claude-haiku-3-5', 'claude-haiku-4'];
        $this->assertSame('claude-haiku-4-5', $resolver->pickHighestVersion($names, 'haiku'));
    }

    public function testReturnsNullWhenNoMatchingFamily(): void
    {
        $resolver = $this->makeResolver([]);
        $names = ['claude-sonnet-4-6', 'claude-haiku-4-5'];
        $this->assertNull($resolver->pickHighestVersion($names, 'opus'));
    }

    public function testReturnsNullOnEmptyList(): void
    {
        $resolver = $this->makeResolver([]);
        $this->assertNull($resolver->pickHighestVersion([], 'sonnet'));
    }

    public function testHandlesLegacyDateSuffixFormat(): void
    {
        $resolver = $this->makeResolver([]);
        // claude-3-5-sonnet-20241022 has version segments [3, 5, 20241022]
        // claude-sonnet-4-6 has version segments [4, 6]
        // 4 > 3 so sonnet-4-6 wins
        $names = ['claude-3-5-sonnet-20241022', 'claude-sonnet-4-6'];
        $this->assertSame('claude-sonnet-4-6', $resolver->pickHighestVersion($names, 'sonnet'));
    }

    public function testDateSuffixBreaksTieWhenMajorMinorEqual(): void
    {
        $resolver = $this->makeResolver([]);
        // Both have major.minor [3, 5]; the date suffix breaks the tie
        $names = ['claude-3-5-sonnet-20241022', 'claude-3-5-sonnet-20250514'];
        $this->assertSame('claude-3-5-sonnet-20250514', $resolver->pickHighestVersion($names, 'sonnet'));
    }

    // --- resolve ---

    public function testResolvePicksBestModels(): void
    {
        $resolver = $this->makeResolver([
            new Response(200, [], json_encode([
                'data' => [
                    ['model_name' => 'claude-sonnet-4-5'],
                    ['model_name' => 'claude-sonnet-4-6'],
                    ['model_name' => 'claude-haiku-4-5'],
                    ['model_name' => 'claude-haiku-4'],
                ],
            ])),
        ]);

        $result = $resolver->resolve('https://llm.amazee.ai', 'tok-xyz');

        $this->assertSame('claude-sonnet-4-6', $result['ai_model']);
        $this->assertSame('claude-haiku-4-5', $result['ai_expansion_model']);
    }

    public function testResolveDegracefullyOnHttpError(): void
    {
        $resolver = $this->makeResolver([
            new Response(500, [], ''),
        ]);

        $result = $resolver->resolve('https://llm.amazee.ai', 'tok-xyz');

        $this->assertNull($result['ai_model']);
        $this->assertNull($result['ai_expansion_model']);
    }

    public function testResolveReturnsNullsWhenNoSonnetOrHaiku(): void
    {
        $resolver = $this->makeResolver([
            new Response(200, [], json_encode([
                'data' => [
                    ['model_name' => 'gpt-4'],
                    ['model_name' => 'mistral-7b'],
                ],
            ])),
        ]);

        $result = $resolver->resolve('https://llm.amazee.ai', 'tok-xyz');

        $this->assertNull($result['ai_model']);
        $this->assertNull($result['ai_expansion_model']);
    }
}
