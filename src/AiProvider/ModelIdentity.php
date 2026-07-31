<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider;

/**
 * Recognises whether a model name is native to a direct AI provider.
 *
 * Gateways in front of a provider (the Amazee.ai LiteLLM proxy is the one
 * this package ships an integration for) address models by their own aliases
 * — `claude-4-5-sonnet` rather than Anthropic's `claude-sonnet-4-5-20250929`.
 * Those aliases are meaningful only against the gateway that minted them. A
 * site that switches from the gateway to a direct provider key while such an
 * alias is still configured gets an "unknown model" rejection on every AI
 * request, which is indistinguishable from any other provider failure.
 *
 * This class exists so that failure can be named. It is deliberately NOT a
 * gate on the request path: see {@see self::looksNativeFor()} for why the
 * only safe use is to classify a failure that has already happened.
 *
 * @since 1.0.6
 * @stability experimental
 */
final class ModelIdentity
{
    /**
     * Model-name prefixes that belong to some vendor other than OpenAI.
     *
     * Used only for the OpenAI direction, where a positive grammar is not
     * possible — see {@see self::looksNativeFor()}.
     */
    private const FOREIGN_TO_OPENAI = [
        'claude',
        'gemini',
        'mistral',
        'mixtral',
        'llama',
        'command',
        'deepseek',
        'qwen',
    ];

    /**
     * Anthropic's current family-first naming: `claude-<family>-<version>`.
     *
     * Covers `claude-opus-5`, `claude-sonnet-4-6`, `claude-haiku-4-5` and the
     * dated snapshots of the same (`claude-sonnet-4-5-20250929`), plus the
     * `-latest` alias suffix.
     */
    private const ANTHROPIC_FAMILY_FIRST
        = '/^claude-(opus|sonnet|haiku|fable|mythos)(-\d+)+(-\d{8}|-latest)?$/';

    /**
     * Anthropic's legacy generation-first naming: `claude-<version>-<family>`.
     *
     * Covers `claude-3-opus-20240229` and `claude-3-5-sonnet-20241022`. The
     * trailing date (or `-latest`) is REQUIRED here, and that requirement is
     * the whole point: the bare form `claude-4-5-sonnet` is exactly the
     * gateway-alias shape this class needs to reject, and it is otherwise
     * indistinguishable from `claude-3-5-sonnet`, which Anthropic never
     * accepted undated either.
     */
    private const ANTHROPIC_GENERATION_FIRST
        = '/^claude-(\d+)(-\d+)*-(opus|sonnet|haiku|instant)(-\d{8}|-latest)$/';

    /** Pre-Claude-3 naming: `claude-2.1`, `claude-instant-1.2`. */
    private const ANTHROPIC_LEGACY = '/^claude-(instant-)?\d+(\.\d+)?$/';

    /**
     * Whether `$model` is plausibly a model ID `$provider` would recognise.
     *
     * **Only call this about a request that already failed.** The check is a
     * heuristic over naming conventions, not a lookup against a provider's
     * live catalogue, so a model released after this code was written can be
     * reported as non-native. Used to classify a failure, that costs nothing
     * beyond a differently-worded error on a request that was already broken.
     * Used as a pre-flight gate, the same false negative would take a working
     * site down — which is the failure mode this whole change set exists to
     * remove, not to reintroduce from the other direction.
     *
     * The two providers are handled asymmetrically because their namespaces
     * are shaped differently:
     *
     * - **anthropic** has a small, strictly conventional namespace, so it is
     *   matched positively against the three grammars it has ever used.
     * - **openai** does not: `gpt-*`, `o1`, `o3-mini`, `chatgpt-*`, and
     *   arbitrary `ft:...` fine-tune IDs are all valid, and an operator may
     *   legitimately point the OpenAI-compatible path at their own model
     *   names. Only names that positively belong to a *different* vendor are
     *   reported as non-native there.
     *
     * An unrecognised provider is never reported as a mismatch: this class
     * has nothing to say about a namespace it does not know.
     *
     * @param string $provider Effective provider ('anthropic' or 'openai').
     * @param string $model    Effective model identifier.
     *
     * @return bool False only when the name positively does not belong to the
     *   provider's namespace.
     *
     * @since 1.0.6
     * @stability experimental
     */
    public static function looksNativeFor(string $provider, string $model): bool
    {
        $model = trim($model);
        if ($model === '') {
            return true;
        }

        return match ($provider) {
            'anthropic' => self::looksAnthropic($model),
            'openai' => !self::belongsToAnotherVendor($model),
            default => true,
        };
    }

    /**
     * Build the operator-facing message for a provider/model mismatch.
     *
     * Names both values, because either one may be the wrong half: the
     * operator who switched providers may need to correct the model, and the
     * operator who pasted a model may need to correct the provider.
     *
     * @param string $provider Effective provider.
     * @param string $model    Effective model identifier.
     *
     * @since 1.0.6
     * @stability experimental
     */
    public static function describeMismatch(string $provider, string $model): string
    {
        return sprintf(
            'Scolta AI model "%s" is not a recognised %s model ID. '
            . 'This usually means a gateway-resolved model name (for example an '
            . 'Amazee.ai LiteLLM alias) is configured while the effective provider '
            . 'is "%s", which does not accept it. Set a provider-native model ID, '
            . 'or switch the provider back to the gateway that resolved this name.',
            $model,
            $provider,
            $provider,
        );
    }

    private static function looksAnthropic(string $model): bool
    {
        return preg_match(self::ANTHROPIC_FAMILY_FIRST, $model) === 1
            || preg_match(self::ANTHROPIC_GENERATION_FIRST, $model) === 1
            || preg_match(self::ANTHROPIC_LEGACY, $model) === 1;
    }

    private static function belongsToAnotherVendor(string $model): bool
    {
        $lower = strtolower($model);
        foreach (self::FOREIGN_TO_OPENAI as $prefix) {
            if (str_starts_with($lower, $prefix . '-') || $lower === $prefix) {
                return true;
            }
        }

        return false;
    }
}
