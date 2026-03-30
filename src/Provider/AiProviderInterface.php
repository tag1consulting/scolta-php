<?php

namespace Tag1\Scolta\Provider;

interface AiProviderInterface {
    /**
     * Send a chat message to an AI provider.
     *
     * @param string $systemPrompt
     * @param string $userMessage
     * @param array $options (model, temperature, max_tokens, etc.)
     * @return AiResponse
     */
    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse;
}
