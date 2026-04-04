<?php

declare(strict_types=1);

namespace Tag1\Scolta\Prompt;

use Tag1\Scolta\Wasm\ScoltaWasm;

/**
 * Prompt templates for Scolta AI features.
 *
 * Canonical prompt text lives in the scolta-core WASM module (Rust).
 * This class provides the same API as before — platform adapters call
 * resolve() with a template name and site identity, and get back the
 * fully resolved prompt string.
 *
 * The WASM module is the single source of truth for prompt wording.
 * Updates to prompts ship with a new WASM binary, not PHP changes.
 */
class DefaultPrompts
{
    /** Template identifiers (string names, not template text). */
    public const EXPAND_QUERY = 'expand_query';
    public const SUMMARIZE = 'summarize';
    public const FOLLOW_UP = 'follow_up';

    /**
     * Replace placeholders in a prompt template with actual values.
     *
     * @param string $template One of the template constants (e.g., self::EXPAND_QUERY)
     *                         or a custom prompt string containing {SITE_NAME}/{SITE_DESCRIPTION}.
     * @param string $siteName The site name.
     * @param string $siteDescription The site description.
     *
     * @return string The resolved prompt.
     */
    public static function resolve(string $template, string $siteName, string $siteDescription = 'website'): string
    {
        // If the template is one of our known names, delegate to WASM.
        if (in_array($template, [self::EXPAND_QUERY, self::SUMMARIZE, self::FOLLOW_UP], true)) {
            return ScoltaWasm::resolvePrompt($template, $siteName, $siteDescription);
        }

        // Custom prompt string — do local replacement (backward compat).
        return str_replace(
            ['{SITE_NAME}', '{SITE_DESCRIPTION}'],
            [$siteName, $siteDescription],
            $template,
        );
    }

    /**
     * Get the raw template text (with placeholders) for a named prompt.
     *
     * @param string $name One of the template constants.
     * @return string The template text with {SITE_NAME} and {SITE_DESCRIPTION} placeholders.
     */
    public static function getTemplate(string $name): string
    {
        return ScoltaWasm::getPrompt($name);
    }
}
