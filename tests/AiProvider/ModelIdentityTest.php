<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\ModelIdentity;

class ModelIdentityTest extends TestCase
{
    /**
     * Every Anthropic model ID naming scheme the API has accepted.
     *
     * The list is the load-bearing part of this class: a false negative here
     * mislabels a working configuration, so the grammar has to admit the
     * family-first aliases, their dated snapshots, the legacy
     * generation-first dated IDs, and the pre-Claude-3 names alike.
     *
     * @return array<string, array{string}>
     */
    public static function nativeAnthropicModels(): array
    {
        return [
            'family-first alias' => ['claude-opus-5'],
            'family-first two-part version' => ['claude-sonnet-4-6'],
            'family-first haiku' => ['claude-haiku-4-5'],
            'family-first fable' => ['claude-fable-5'],
            'dated family-first snapshot' => ['claude-sonnet-4-5-20250929'],
            'dated haiku snapshot' => ['claude-haiku-4-5-20251001'],
            'shipped default' => ['claude-sonnet-4-5-20250929'],
            'legacy generation-first' => ['claude-3-opus-20240229'],
            'legacy generation-first two-part' => ['claude-3-5-sonnet-20241022'],
            'legacy generation-first haiku' => ['claude-3-haiku-20240307'],
            'latest alias' => ['claude-opus-4-1-latest'],
            'pre-3 versioned' => ['claude-2.1'],
            'pre-3 instant' => ['claude-instant-1.2'],
        ];
    }

    /**
     * @dataProvider nativeAnthropicModels
     */
    public function testNativeAnthropicModelsAreRecognised(string $model): void
    {
        $this->assertTrue(
            ModelIdentity::looksNativeFor('anthropic', $model),
            $model . ' is a real Anthropic model ID and must not be flagged',
        );
    }

    public function testAmazeeGatewayAliasIsNotNativeToAnthropic(): void
    {
        // The exact name observed poisoning the Athenaeum demo. LiteLLM puts
        // the version before the family and carries no date; Anthropic's
        // generation-first IDs always carry one. That difference is the only
        // thing separating this from `claude-3-5-sonnet`, which is why the
        // date is required by the generation-first grammar.
        $this->assertFalse(ModelIdentity::looksNativeFor('anthropic', 'claude-4-5-sonnet'));
    }

    public function testUndatedGenerationFirstNameIsNotNativeToAnthropic(): void
    {
        // Anthropic never accepted the generation-first form without a date
        // suffix, so accepting it here would let the gateway-alias shape
        // through under a different version number.
        $this->assertFalse(ModelIdentity::looksNativeFor('anthropic', 'claude-3-5-sonnet'));
    }

    public function testOtherVendorsAreNotNativeToAnthropic(): void
    {
        $this->assertFalse(ModelIdentity::looksNativeFor('anthropic', 'gpt-4o'));
        $this->assertFalse(ModelIdentity::looksNativeFor('anthropic', 'llama-3-70b'));
    }

    public function testGatewayAliasIsNotNativeToOpenAi(): void
    {
        // A Claude alias sent to OpenAI's own API is unambiguously wrong,
        // whichever gateway minted it.
        $this->assertFalse(ModelIdentity::looksNativeFor('openai', 'claude-4-5-sonnet'));
        $this->assertFalse(ModelIdentity::looksNativeFor('openai', 'claude-sonnet-4-5-20250929'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function openAiCompatibleModels(): array
    {
        return [
            'chat model' => ['gpt-4o'],
            'reasoning model' => ['o3-mini'],
            'bare reasoning model' => ['o1'],
            'chatgpt snapshot' => ['chatgpt-4o-latest'],
            'fine-tune' => ['ft:gpt-4o-2024-08-06:acme::9xYz'],
            'operator-named model' => ['acme-internal-v2'],
        ];
    }

    /**
     * OpenAI's namespace is open-ended, so the check there only rejects names
     * that positively belong to another vendor. Fine-tune IDs and operator
     * model names on OpenAI-compatible servers must pass untouched.
     *
     * @dataProvider openAiCompatibleModels
     */
    public function testOpenAiNamespaceIsNotSecondGuessed(string $model): void
    {
        $this->assertTrue(ModelIdentity::looksNativeFor('openai', $model));
    }

    public function testUnknownProviderIsNeverReportedAsMismatched(): void
    {
        $this->assertTrue(ModelIdentity::looksNativeFor('amazee', 'claude-4-5-sonnet'));
        $this->assertTrue(ModelIdentity::looksNativeFor('', 'claude-4-5-sonnet'));
    }

    public function testEmptyModelIsNeverReportedAsMismatched(): void
    {
        // An unset model is a different failure (the caller falls back to the
        // shipped default), and naming it here would be misleading.
        $this->assertTrue(ModelIdentity::looksNativeFor('anthropic', ''));
        $this->assertTrue(ModelIdentity::looksNativeFor('anthropic', '   '));
    }

    public function testMismatchDescriptionNamesBothModelAndProvider(): void
    {
        $message = ModelIdentity::describeMismatch('anthropic', 'claude-4-5-sonnet');

        $this->assertStringContainsString('claude-4-5-sonnet', $message);
        $this->assertStringContainsString('anthropic', $message);
        // The operator needs to know where the name came from to know what to
        // do about it.
        $this->assertStringContainsString('Amazee', $message);
    }
}
