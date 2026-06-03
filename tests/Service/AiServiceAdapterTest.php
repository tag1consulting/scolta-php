<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Service;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Tests for prompt resolution in AiServiceAdapter.
 *
 * Key invariants:
 *   - Custom prompt overrides are returned raw — {SITE_NAME} is NOT substituted.
 *   - Default prompts are resolved — {SITE_NAME} and {SITE_DESCRIPTION} are substituted.
 *   - An empty override string falls back to the default with substitution.
 */
class AiServiceAdapterTest extends TestCase
{
    // -------------------------------------------------------------------
    // Custom overrides — returned raw (no substitution)
    // -------------------------------------------------------------------

    public function testCustomExpandPromptReturnedRaw(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Acme Corp',
            'prompt_expand_query' => 'My custom expand prompt for {SITE_NAME}.',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getExpandPrompt();

        $this->assertEquals('My custom expand prompt for {SITE_NAME}.', $prompt);
        $this->assertStringNotContainsString('Acme Corp', $prompt);
    }

    public function testCustomSummarizePromptReturnedRaw(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Acme Corp',
            'prompt_summarize' => 'Custom summarize for {SITE_NAME}.',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getSummarizePrompt();

        $this->assertEquals('Custom summarize for {SITE_NAME}.', $prompt);
        $this->assertStringNotContainsString('Acme Corp', $prompt);
    }

    public function testCustomFollowUpPromptReturnedRaw(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Acme Corp',
            'prompt_follow_up' => 'Custom follow-up for {SITE_NAME}.',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getFollowUpPrompt();

        $this->assertEquals('Custom follow-up for {SITE_NAME}.', $prompt);
        $this->assertStringNotContainsString('Acme Corp', $prompt);
    }

    // -------------------------------------------------------------------
    // Defaults — {SITE_NAME} and {SITE_DESCRIPTION} are substituted
    // -------------------------------------------------------------------

    public function testDefaultExpandPromptContainsSiteName(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Acme Corp',
            'site_description' => 'technology blog',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getExpandPrompt();

        $this->assertStringContainsString('Acme Corp', $prompt);
        $this->assertStringNotContainsString('{SITE_NAME}', $prompt);
        $this->assertStringNotContainsString('{SITE_DESCRIPTION}', $prompt);
    }

    public function testDefaultSummarizePromptContainsSiteName(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Example Site',
            'site_description' => 'news website',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getSummarizePrompt();

        $this->assertStringContainsString('Example Site', $prompt);
        $this->assertStringContainsString('news website', $prompt);
        $this->assertStringNotContainsString('{SITE_NAME}', $prompt);
        $this->assertStringNotContainsString('{SITE_DESCRIPTION}', $prompt);
    }

    public function testDefaultFollowUpPromptContainsSiteName(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Widget World',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getFollowUpPrompt();

        $this->assertStringContainsString('Widget World', $prompt);
        $this->assertStringNotContainsString('{SITE_NAME}', $prompt);
    }

    // -------------------------------------------------------------------
    // Empty override falls back to default with substitution
    // -------------------------------------------------------------------

    public function testEmptyExpandOverrideFallsBackToDefault(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Test Site',
            'prompt_expand_query' => '',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getExpandPrompt();

        $this->assertStringContainsString('Test Site', $prompt);
        $this->assertStringNotContainsString('{SITE_NAME}', $prompt);
    }

    public function testEmptySummarizeOverrideFallsBackToDefault(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Test Site',
            'prompt_summarize' => '',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getSummarizePrompt();

        $this->assertStringContainsString('Test Site', $prompt);
        $this->assertStringNotContainsString('{SITE_NAME}', $prompt);
    }

    public function testEmptyFollowUpOverrideFallsBackToDefault(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'Test Site',
            'prompt_follow_up' => '',
        ]);
        $adapter = new AiServiceAdapter($config);

        $prompt = $adapter->getFollowUpPrompt();

        $this->assertStringContainsString('Test Site', $prompt);
        $this->assertStringNotContainsString('{SITE_NAME}', $prompt);
    }

    // -------------------------------------------------------------------
    // resolvePrompt — direct template resolution
    // -------------------------------------------------------------------

    public function testResolvePromptSubstitutesPlaceholders(): void
    {
        $config = ScoltaConfig::fromArray([
            'site_name' => 'My Blog',
            'site_description' => 'a personal blog',
        ]);
        $adapter = new AiServiceAdapter($config);

        $resolved = $adapter->resolvePrompt(DefaultPrompts::EXPAND_QUERY);

        $this->assertStringContainsString('My Blog', $resolved);
        $this->assertStringContainsString('a personal blog', $resolved);
        $this->assertStringNotContainsString('{SITE_NAME}', $resolved);
        $this->assertStringNotContainsString('{SITE_DESCRIPTION}', $resolved);
    }

    // -------------------------------------------------------------------
    // messageForOperation — framework path takes precedence
    // -------------------------------------------------------------------

    public function testMessageForOperationUsesFrameworkPathWhenAvailable(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_expansion_model' => 'claude-haiku-4-5-20251001',
        ]);
        $adapter = new class ($config) extends AiServiceAdapter {
            protected function tryFrameworkAi(string $systemPrompt, string $userMessage, int $maxTokens): ?string
            {
                return 'framework-response';
            }
        };

        $result = $adapter->messageForOperation('expand_query', 'sys', 'user', 512);

        $this->assertEquals('framework-response', $result);
    }

    // -------------------------------------------------------------------
    // aiExpansionModel config property defaults and fromArray mapping
    // -------------------------------------------------------------------

    public function testAiExpansionModelDefaultsToEmpty(): void
    {
        $config = new ScoltaConfig();

        $this->assertSame('', $config->aiExpansionModel);
    }

    public function testAiExpansionModelMapsFromArray(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_expansion_model' => 'claude-haiku-4-5-20251001',
        ]);

        $this->assertSame('claude-haiku-4-5-20251001', $config->aiExpansionModel);
    }

    public function testAiExpansionModelNotIncludedInAiClientConfig(): void
    {
        $config = ScoltaConfig::fromArray([
            'ai_model' => 'claude-sonnet-4-5-20250929',
            'ai_expansion_model' => 'claude-haiku-4-5-20251001',
        ]);

        $clientConfig = $config->toAiClientConfig();

        $this->assertArrayHasKey('model', $clientConfig);
        $this->assertSame('claude-sonnet-4-5-20250929', $clientConfig['model']);
        $this->assertArrayNotHasKey('expansion_model', $clientConfig);
        $this->assertArrayNotHasKey('ai_expansion_model', $clientConfig);
    }

    // -------------------------------------------------------------------
    // handlePossibleBudgetException hook — invoked on a client RuntimeException
    // -------------------------------------------------------------------

    /**
     * Build an adapter whose built-in client throws the given RuntimeException
     * and whose budget hook is recorded so the test can assert it fired.
     */
    private function makeThrowingAdapter(\RuntimeException $toThrow): AiServiceAdapter
    {
        $config = ScoltaConfig::fromArray(['ai_expansion_model' => 'claude-haiku-4-5-20251001']);

        $throwingClient = new class ($toThrow) extends AiClient {
            private \RuntimeException $toThrow;

            public function __construct(\RuntimeException $toThrow)
            {
                $this->toThrow = $toThrow;
                parent::__construct([]);
            }

            public function message(string $systemPrompt, string $userMessage, int $maxTokens = 1024, ?string $model = null): string
            {
                throw $this->toThrow;
            }

            public function conversation(string $systemPrompt, array $messages, int $maxTokens = 1024, ?string $model = null): string
            {
                throw $this->toThrow;
            }
        };

        return new class ($config, $throwingClient) extends AiServiceAdapter {
            public int $hookCalls = 0;

            public ?\RuntimeException $hookArg = null;

            private AiClient $stubClient;

            public function __construct(ScoltaConfig $config, AiClient $stubClient)
            {
                parent::__construct($config);
                $this->stubClient = $stubClient;
            }

            protected function getClient(): AiClient
            {
                return $this->stubClient;
            }

            protected function handlePossibleBudgetException(\RuntimeException $e): void
            {
                $this->hookCalls++;
                $this->hookArg = $e;
            }
        };
    }

    public function testMessageInvokesBudgetHookOnClientException(): void
    {
        $original = new \RuntimeException('Budget has been exceeded!');
        $adapter = $this->makeThrowingAdapter($original);

        try {
            $adapter->message('sys', 'user');
            $this->fail('Expected RuntimeException to propagate.');
        } catch (\RuntimeException $caught) {
            // The original exception propagates unchanged after the hook fires.
            $this->assertSame($original, $caught);
        }

        $this->assertSame(1, $adapter->hookCalls);
        $this->assertSame($original, $adapter->hookArg);
    }

    public function testConversationInvokesBudgetHookOnClientException(): void
    {
        $original = new \RuntimeException('Budget has been exceeded!');
        $adapter = $this->makeThrowingAdapter($original);

        try {
            $adapter->conversation('sys', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected RuntimeException to propagate.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($original, $caught);
        }

        $this->assertSame(1, $adapter->hookCalls);
        $this->assertSame($original, $adapter->hookArg);
    }

    public function testMessageForOperationInvokesBudgetHookOnClientException(): void
    {
        $original = new \RuntimeException('Budget has been exceeded!');
        $adapter = $this->makeThrowingAdapter($original);

        try {
            $adapter->messageForOperation('expand_query', 'sys', 'user');
            $this->fail('Expected RuntimeException to propagate.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($original, $caught);
        }

        $this->assertSame(1, $adapter->hookCalls);
        $this->assertSame($original, $adapter->hookArg);
    }

    /**
     * The default base hook is a no-op: the original exception must propagate
     * unchanged when an adapter does not override handlePossibleBudgetException.
     */
    public function testDefaultHookIsNoOpAndExceptionPropagates(): void
    {
        $config = ScoltaConfig::fromArray([]);
        $original = new \RuntimeException('some unrelated client failure');

        $throwingClient = new class ($original) extends AiClient {
            private \RuntimeException $toThrow;

            public function __construct(\RuntimeException $toThrow)
            {
                $this->toThrow = $toThrow;
                parent::__construct([]);
            }

            public function message(string $systemPrompt, string $userMessage, int $maxTokens = 1024, ?string $model = null): string
            {
                throw $this->toThrow;
            }
        };

        // Adapter overrides ONLY getClient — handlePossibleBudgetException stays
        // the base no-op.
        $adapter = new class ($config, $throwingClient) extends AiServiceAdapter {
            private AiClient $stubClient;

            public function __construct(ScoltaConfig $config, AiClient $stubClient)
            {
                parent::__construct($config);
                $this->stubClient = $stubClient;
            }

            protected function getClient(): AiClient
            {
                return $this->stubClient;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('some unrelated client failure');
        $adapter->message('sys', 'user');
    }

    /**
     * An overriding hook may throw its own exception to replace the original,
     * mirroring how platform adapters convert a budget error to a typed one.
     */
    public function testHookMayReplaceTheException(): void
    {
        $config = ScoltaConfig::fromArray([]);
        $original = new \RuntimeException('Budget has been exceeded!');

        $throwingClient = new class ($original) extends AiClient {
            private \RuntimeException $toThrow;

            public function __construct(\RuntimeException $toThrow)
            {
                $this->toThrow = $toThrow;
                parent::__construct([]);
            }

            public function message(string $systemPrompt, string $userMessage, int $maxTokens = 1024, ?string $model = null): string
            {
                throw $this->toThrow;
            }
        };

        $adapter = new class ($config, $throwingClient) extends AiServiceAdapter {
            private AiClient $stubClient;

            public function __construct(ScoltaConfig $config, AiClient $stubClient)
            {
                parent::__construct($config);
                $this->stubClient = $stubClient;
            }

            protected function getClient(): AiClient
            {
                return $this->stubClient;
            }

            protected function handlePossibleBudgetException(\RuntimeException $e): void
            {
                throw new \LogicException('converted: ' . $e->getMessage(), 0, $e);
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('converted: Budget has been exceeded!');
        $adapter->message('sys', 'user');
    }
}
