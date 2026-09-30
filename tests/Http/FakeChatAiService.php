<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * An AI service for the chat handler: every model call answers from a script
 * and is recorded. The prompt getters are the real ones.
 */
class FakeChatAiService extends AiServiceAdapter
{
    /** @var list<array{operation: string, system: string, user: string}> */
    public array $plans = [];

    /** @var list<array{system: string, messages: array<int, array<string, mixed>>, maxTokens: int, cacheSystem: bool}> */
    public array $streams = [];

    /** @var list<array{system: string, user: string}> */
    public array $messages = [];

    /** @var list<string> */
    public array $pieces = ['The answer.'];

    public ?\Throwable $streamFailure = null;

    public string $planReply = '{"query": "planned query", "needs_search": true, "terms": ["one", "two"]}';

    public ?\Throwable $planFailure = null;

    public string $foldReply = 'A short summary.';

    public ?\Throwable $foldFailure = null;

    /** @var (\Closure(): void)|null Runs inside the fold call, before it returns. */
    public ?\Closure $duringFold = null;

    public function __construct(?ScoltaConfig $config = null)
    {
        parent::__construct($config ?? ScoltaConfig::fromArray(['site_name' => 'ComplianceIQ', 'chat_enabled' => true]));
    }

    public function messageForOperation(string $operation, string $systemPrompt, string $userMessage, int $maxTokens = 512): string
    {
        $this->plans[] = ['operation' => $operation, 'system' => $systemPrompt, 'user' => $userMessage];
        if ($this->planFailure !== null) {
            throw $this->planFailure;
        }

        return $this->planReply;
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string
    {
        $this->messages[] = ['system' => $systemPrompt, 'user' => $userMessage];
        if ($this->duringFold !== null) {
            ($this->duringFold)();
        }
        if ($this->foldFailure !== null) {
            throw $this->foldFailure;
        }

        return $this->foldReply;
    }

    public function conversationStream(
        string $systemPrompt,
        array $messages,
        int $maxTokens = 1024,
        ?string $model = null,
        ?float $temperature = null,
        bool $cacheSystem = false,
    ): \Generator {
        $this->streams[] = ['system' => $systemPrompt, 'messages' => $messages, 'maxTokens' => $maxTokens, 'cacheSystem' => $cacheSystem];
        foreach ($this->pieces as $piece) {
            yield $piece;
        }
        if ($this->streamFailure !== null) {
            throw $this->streamFailure;
        }
    }
}
