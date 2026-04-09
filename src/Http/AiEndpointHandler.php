<?php

declare(strict_types=1);

namespace Tag1\Scolta\Http;

use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Prompt\NullEnricher;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Shared business logic for all AI endpoint handlers.
 *
 * Extracts validation, caching, response parsing, and error handling
 * that was previously duplicated across Drupal, Laravel, and WordPress
 * controllers. Platform controllers become thin adapters that:
 *   1. Parse the request (platform-specific)
 *   2. Call the appropriate handle*() method
 *   3. Convert the result array to a platform response
 *
 * The AI service is duck-typed — any object with the required methods
 * (getExpandPrompt, getSummarizePrompt, getFollowUpPrompt, message,
 * conversation) will work.
 *
 * @since 0.2.0
 * @stability experimental
 */
class AiEndpointHandler
{
    /**
     * @param object                    $aiService       AI service (duck-typed).
     * @param CacheDriverInterface      $cache           Cache driver.
     * @param int                       $generation      Generation counter for cache invalidation.
     * @param int                       $cacheTtl        Cache TTL in seconds (0 = disabled).
     * @param int                       $maxFollowUps    Maximum follow-up exchanges allowed.
     * @param PromptEnricherInterface   $promptEnricher  Prompt enricher for site-specific context injection.
     */
    public function __construct(
        private readonly object $aiService,
        private readonly CacheDriverInterface $cache,
        private readonly int $generation,
        private readonly int $cacheTtl,
        private readonly int $maxFollowUps,
        private readonly PromptEnricherInterface $promptEnricher = new NullEnricher(),
    ) {}

    /**
     * Handle an expand-query request.
     *
     * @param string $query The search query to expand.
     * @return array{ok: bool, data?: mixed, status?: int, error?: string}
     */
    public function handleExpandQuery(string $query): array
    {
        $query = trim($query);

        if ($query === '' || strlen($query) > 500) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid query'];
        }

        // Cache lookup.
        $cacheKey = $this->cacheKey('expand', $query);
        if ($this->cacheTtl > 0) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return ['ok' => true, 'data' => $cached];
            }
        }

        try {
            $systemPrompt = $this->promptEnricher->enrich(
                $this->aiService->getExpandPrompt(),
                'expand_query',
                ['query' => $query],
            );

            $response = $this->aiService->message(
                $systemPrompt,
                'Expand this search query: ' . $query,
                512,
            );

            $terms = $this->parseExpansionResponse($response, $query);

            if ($this->cacheTtl > 0) {
                $this->cache->set($cacheKey, $terms, $this->cacheTtl);
            }

            return ['ok' => true, 'data' => $terms];
        } catch (\Exception $e) {
            return ['ok' => false, 'status' => 503, 'error' => 'Query expansion unavailable', 'exception' => $e];
        }
    }

    /**
     * Handle a summarize request.
     *
     * @param string $query   The search query.
     * @param string $context Search result excerpts.
     * @return array{ok: bool, data?: mixed, status?: int, error?: string}
     */
    public function handleSummarize(string $query, string $context): array
    {
        $query = trim($query);
        $context = trim($context);

        if ($query === '' || strlen($query) > 500) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid query'];
        }
        if ($context === '' || strlen($context) > 50000) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid context'];
        }

        // Cache lookup.
        $cacheKey = $this->cacheKey('summarize', $query, $context);
        if ($this->cacheTtl > 0) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return ['ok' => true, 'data' => $cached];
            }
        }

        $userMessage = "Search query: {$query}\n\nSearch result excerpts:\n{$context}";

        try {
            $systemPrompt = $this->promptEnricher->enrich(
                $this->aiService->getSummarizePrompt(),
                'summarize',
                ['query' => $query, 'context' => $context],
            );

            $summary = $this->aiService->message(
                $systemPrompt,
                $userMessage,
                512,
            );

            $result = ['summary' => $summary];

            if ($this->cacheTtl > 0) {
                $this->cache->set($cacheKey, $result, $this->cacheTtl);
            }

            return ['ok' => true, 'data' => $result];
        } catch (\Exception $e) {
            return ['ok' => false, 'status' => 503, 'error' => 'Summarization unavailable', 'exception' => $e];
        }
    }

    /**
     * Handle a follow-up conversation request.
     *
     * @param array $messages Conversation messages (role + content pairs).
     * @return array{ok: bool, data?: mixed, status?: int, error?: string}
     */
    public function handleFollowUp(array $messages): array
    {
        // Validate messages array.
        if (empty($messages) || !is_array($messages)) {
            return ['ok' => false, 'status' => 400, 'error' => 'Messages required'];
        }

        // Validate each message has role and content.
        foreach ($messages as $msg) {
            if (empty($msg['role']) || empty($msg['content'])) {
                return ['ok' => false, 'status' => 400, 'error' => 'Invalid message format'];
            }
            if (!in_array($msg['role'], ['user', 'assistant'], true)) {
                return ['ok' => false, 'status' => 400, 'error' => 'Invalid role'];
            }
        }

        // Last message must be from the user.
        if (end($messages)['role'] !== 'user') {
            return ['ok' => false, 'status' => 400, 'error' => 'Last message must be from user'];
        }

        // Enforce follow-up limit server-side.
        $followUpsSoFar = intdiv(count($messages) - 2, 2);
        if ($followUpsSoFar >= $this->maxFollowUps) {
            return [
                'ok' => false,
                'status' => 429,
                'error' => 'Follow-up limit reached',
                'limit' => $this->maxFollowUps,
            ];
        }

        try {
            $systemPrompt = $this->promptEnricher->enrich(
                $this->aiService->getFollowUpPrompt(),
                'follow_up',
                ['messages' => $messages],
            );

            $response = $this->aiService->conversation(
                $systemPrompt,
                $messages,
                512,
            );

            $remaining = $this->maxFollowUps - $followUpsSoFar - 1;

            return ['ok' => true, 'data' => [
                'response' => $response,
                'remaining' => max(0, $remaining),
            ]];
        } catch (\Exception $e) {
            return ['ok' => false, 'status' => 503, 'error' => 'Follow-up unavailable', 'exception' => $e];
        }
    }

    /**
     * Parse an AI expansion response, stripping markdown fences.
     *
     * @param string $response      Raw AI response text.
     * @param string $originalQuery The original query (used as fallback).
     * @return array The parsed list of search terms.
     */
    public function parseExpansionResponse(string $response, string $originalQuery): array
    {
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $terms = json_decode($cleaned, true);
        if (!is_array($terms) || count($terms) < 2) {
            $terms = [$originalQuery];
        }

        return $terms;
    }

    /**
     * Generate a deterministic cache key.
     *
     * @param string $action The action name (expand, summarize).
     * @param string ...$parts Variable parts to hash.
     * @return string The cache key.
     */
    public function cacheKey(string $action, string ...$parts): string
    {
        $hashInput = strtolower(implode('|', $parts));
        return 'scolta_' . $action . '_' . $this->generation . '_' . hash('sha256', $hashInput);
    }
}
